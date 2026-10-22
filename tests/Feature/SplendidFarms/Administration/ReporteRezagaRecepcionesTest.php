<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\EmbarqueEmpaque;
use App\Models\EmbarqueEmpaqueDetalle;
use App\Models\Entity;
use App\Models\ProduccionEmpaque;
use App\Models\RecepcionEmpaque;
use App\Services\Reportes\RezagaRecepcionesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Concerns\CreatesReporteEmpaqueFixtures;
use Tests\TestCase;

/**
 * Dataset (planta «Empaque Principal» → pestaña «Principal»):
 *  - REC-A (1000 kg): pallet P-1 en M-1 (300 kg, báscula 310) y pallet P-2
 *    en M-2 (100 kg) → embarcado 400, rezaga 600 = 450 (M-1) + 150 (M-2).
 *  - Pallet mixto P-3 en M-1: 30 cajas / 300 kg de REC-B y 20 cajas /
 *    200 kg de REC-B-B; báscula del pallet 520 repartida por cajas (312/208).
 *  - REC-C sin embarque válido: solo está en un embarque cancelado y en uno
 *    sin manifiesto.
 */
class ReporteRezagaRecepcionesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesReporteEmpaqueFixtures;

    private const BASE_URL = '/api/splendidfarms/administration/reportes/empaque/rezaga-recepciones';

    private array $base;

    private array $mixto;

    private RecepcionEmpaque $recepcionC;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReporteEmpaqueFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->crearDataset();
    }

    public function test_agrupa_por_folio_y_manifiesto_con_pallet_mixto_y_folio_sin_embarque(): void
    {
        $datos = app(RezagaRecepcionesService::class)->obtenerDatos([], null, null, $this->temporada->id);

        $this->assertCount(1, $datos['empaques']);
        $empaque = $datos['empaques'][0];
        $this->assertSame('Principal', $empaque['hoja']);

        $filas = collect($empaque['filas'])->map(fn ($f) => [
            $f['folio_recepcion'], $f['manifiesto'], $f['pallets'], $f['cajas'],
            $f['peso_manifiesto'], $f['bascula_embarque'], $f['primera_fila'],
        ])->all();

        $this->assertSame([
            ['REC-A', 'M-1', 1, 20, 300.0, 310.0, 1],
            ['REC-A', 'M-2', 1, 10, 100.0, 0.0, 0],
            ['REC-B', 'M-1', 1, 30, 300.0, 312.0, 1],
            ['REC-B-B', 'M-1', 1, 20, 200.0, 208.0, 1],
            ['REC-C', null, 0, 0, 0.0, 0.0, 1],
        ], $filas);

        $sinEmbarque = collect($empaque['filas'])->firstWhere('folio_recepcion', 'REC-C');
        $this->assertNull($sinEmbarque['folio_embarque']);
        $this->assertNull($sinEmbarque['fecha_embarque']);
        $this->assertSame(0.0, $sinEmbarque['peso_bascula']);
    }

    public function test_cuenta_pallets_distintos_por_manifiesto(): void
    {
        // Otro pallet de REC-A en M-1 (P-4) y una segunda línea del mismo P-1.
        $otra = $this->produccionDe($this->base['proceso'], 'A2');
        $this->detalle($this->embarqueM1, $otra, 'P-4', 5, 50, null);
        $this->detalle($this->embarqueM1, $otra, 'P-1', 5, 50, null);

        $datos = app(RezagaRecepcionesService::class)->obtenerDatos([], null, null, $this->temporada->id);
        $m1 = collect($datos['empaques'][0]['filas'])
            ->first(fn ($f) => $f['folio_recepcion'] === 'REC-A' && $f['manifiesto'] === 'M-1');

        $this->assertSame(2, $m1['pallets']);
        $this->assertSame(30, $m1['cajas']);
        $this->assertSame(400.0, $m1['peso_manifiesto']);
    }

    public function test_descarga_excel_con_prorrateo_y_formulas_sin_errores(): void
    {
        $response = $this->get(self::BASE_URL.'?temporada_id='.$this->temporada->id);

        $response->assertOk();
        $this->assertStringContainsString(
            'Consolidado_Recepciones_Embarques_'.now()->format('Y-m-d').'.xlsx',
            $response->headers->get('Content-Disposition'),
        );

        $ruta = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $zip->open($ruta);
        $this->assertStringContainsString('name="tblPrincipal" displayName="tblPrincipal"', $zip->getFromName('xl/tables/table1.xml'));
        $zip->close();

        $libro = $this->cargarDescarga($response);

        $this->assertSame(
            ['Resumen', 'Principal', 'Consolidado', 'Rezaga Principal'],
            $libro->getSheetNames(),
        );

        $detalle = $libro->getSheetByName('Principal');
        $this->assertSame('tblPrincipal', $detalle->getTableNames()[0]);
        $this->assertSame('C2', $detalle->getFreezePane());
        $this->assertSame('=(F2-I2)*IF(I2=0,1,Q2/I2)', $detalle->getCell('J2')->getValue());
        $this->assertSame('=COUNTIFS($B$2:$B$6,B2,$M$2:$M$6,"?*")', $detalle->getCell('H2')->getValue());

        // Prorrateo de REC-A: 600 de rezaga repartidos 300/400 y 100/400.
        $this->assertEqualsWithDelta(450.0, $detalle->getCell('J2')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(150.0, $detalle->getCell('J3')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(750.0, $detalle->getCell('T2')->getCalculatedValue(), 0.001);
        $this->assertSame(2, (int) $detalle->getCell('H2')->getCalculatedValue());
        // REC-C sin embarque: 0 manifiestos y rezaga = todo lo recibido.
        $this->assertSame('Sin embarque', $detalle->getCell('L6')->getValue());
        $this->assertTrue($detalle->getStyle('L6')->getFont()->getItalic());
        $this->assertSame(0, (int) $detalle->getCell('H6')->getCalculatedValue());
        $this->assertEqualsWithDelta(1000.0, $detalle->getCell('J6')->getCalculatedValue(), 0.001);

        $rezaga = $libro->getSheetByName('Rezaga Principal');
        $this->assertSame('tblRezagaPrincipal', $rezaga->getTableNames()[0]);
        $this->assertSame('REC-A', $rezaga->getCell('B2')->getValue());
        $this->assertEqualsWithDelta(600.0, $rezaga->getCell('J2')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(0.6, $rezaga->getCell('K2')->getCalculatedValue(), 0.0001);
        // REC-B-B: recibió 1000, embarcó 200.
        $this->assertEqualsWithDelta(800.0, $rezaga->getCell('J4')->getCalculatedValue(), 0.001);
        // TOTAL dos filas debajo de la tabla (A1:K5 → fila 7).
        $this->assertSame('=SUBTOTAL(109,J2:J5)', $rezaga->getCell('J7')->getValue());
        $this->assertEqualsWithDelta(3100.0, $rezaga->getCell('J7')->getCalculatedValue(), 0.001);

        $resumen = $libro->getSheetByName('Resumen');
        $this->assertSame('Principal', $resumen->getCell('A8')->getValue());
        $this->assertSame(4, (int) $resumen->getCell('B8')->getCalculatedValue());
        $this->assertSame(3, (int) $resumen->getCell('C8')->getCalculatedValue()); // REC-C sin báscula
        $this->assertSame(1, (int) $resumen->getCell('D8')->getCalculatedValue());
        $this->assertEqualsWithDelta(4000.0, $resumen->getCell('E8')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(900.0, $resumen->getCell('G8')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(3100.0, $resumen->getCell('I8')->getCalculatedValue(), 0.001);
        $this->assertSame('TOTAL', $resumen->getCell('A9')->getValue());
        $this->assertEqualsWithDelta(0.775, $resumen->getCell('J9')->getCalculatedValue(), 0.0001);

        $this->assertValoresGuardadosIgualAlMotor($libro);
    }

    public function test_filtra_por_empaque_y_fecha_de_recepcion(): void
    {
        $otraPlanta = Entity::create([
            'branch_id' => $this->entity->branch_id,
            'entity_type_id' => $this->entity->entity_type_id,
            'code' => 'EMP-002',
            'name' => 'Empaque Fresh Cooling',
            'slug' => 'empaque-fresh-cooling',
            'is_active' => true,
        ]);
        $this->crearRecepcionConProduccion($this->productorPrincipal, 'F', [
            'entity_id' => $otraPlanta->id,
            'fecha_recepcion' => '2026-03-10',
        ]);

        $servicio = app(RezagaRecepcionesService::class);

        $todas = $servicio->obtenerDatos([], null, null, $this->temporada->id);
        $this->assertSame(['FreshCooling', 'Principal'], array_column($todas['empaques'], 'hoja'));

        $soloOtra = $servicio->obtenerDatos([$otraPlanta->id], null, null, $this->temporada->id);
        $this->assertSame(['FreshCooling'], array_column($soloOtra['empaques'], 'hoja'));

        $porFecha = $servicio->obtenerDatos([], '2026-03-01', '2026-03-31', $this->temporada->id);
        $this->assertSame(['REC-F'], array_column($porFecha['empaques'][0]['filas'], 'folio_recepcion'));

        // Misma forma de query que arma el front (empaques[] + fechas).
        $this->get(self::BASE_URL.'?temporada_id='.$this->temporada->id
            .'&empaques[]='.$otraPlanta->id.'&desde=2026-03-01&hasta=2026-03-31')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->getJson(self::BASE_URL.'/empaques?temporada_id='.$this->temporada->id)
            ->assertOk()
            ->assertJsonPath('data.0.hoja', 'FreshCooling')
            ->assertJsonPath('data.1.hoja', 'Principal')
            ->assertJsonPath('data.1.folios', 4);
    }

    public function test_valida_parametros_y_responde_422_sin_datos(): void
    {
        $this->getJson(self::BASE_URL)->assertStatus(422)->assertJsonValidationErrors('temporada_id');
        $this->getJson(self::BASE_URL.'/empaques')->assertStatus(422);
        $this->getJson(self::BASE_URL.'?temporada_id='.$this->temporada->id.'&desde=2026-05-01&hasta=2026-04-01')
            ->assertStatus(422)->assertJsonValidationErrors('hasta');

        $this->getJson(self::BASE_URL.'?temporada_id='.$this->temporada->id.'&desde=2027-01-01')
            ->assertStatus(422)
            ->assertJsonPath('message', 'No hay recepciones con esos filtros.');
    }

    // ---------------------------------------------------------------------

    private EmbarqueEmpaque $embarqueM1;

    private function crearDataset(): void
    {
        $this->base = $this->crearRecepcionConProduccion($this->productorPrincipal, 'A');
        $this->mixto = $this->crearRecepcionConProduccionMultiEntrada($this->productorPrincipal, 'B', $this->productorSecundario);
        $c = $this->crearRecepcionConProduccion($this->productorSecundario, 'C', ['peso_bascula' => null]);
        $this->recepcionC = $c['recepcion'];

        $this->embarqueM1 = $this->embarque('EMB-1', 'M-1', '2026-02-05');
        $m2 = $this->embarque('EMB-2', 'M-2', '2026-02-06');
        $cancelado = $this->embarque('EMB-3', 'M-3', '2026-02-07', 'cancelado');
        $sinManifiesto = $this->embarque('EMB-4', null, '2026-02-08');

        $this->detalle($this->embarqueM1, $this->base['produccion'], 'P-1', 20, 300, 310);
        $segunda = $this->produccionDe($this->base['proceso'], 'A1');
        $this->detalle($m2, $segunda, 'P-2', 10, 100, null);
        $this->detalle($this->embarqueM1, $this->mixto['produccion'], 'P-3', 50, 500, 520);
        $this->detalle($cancelado, $c['produccion'], 'P-5', 50, 500, null);
        $this->detalle($sinManifiesto, $c['produccion'], 'P-6', 50, 500, null);
    }

    private function embarque(string $folio, ?string $manifiesto, string $fecha, string $status = 'programado'): EmbarqueEmpaque
    {
        return EmbarqueEmpaque::create([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'folio_embarque' => $folio,
            'manifiesto' => $manifiesto,
            'tipo_venta' => 'exportacion',
            'cliente' => 'Cliente',
            'fecha_embarque' => $fecha,
            'status' => $status,
        ]);
    }

    private function produccionDe($proceso, string $sufijo): ProduccionEmpaque
    {
        return ProduccionEmpaque::create([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $proceso->id,
            'folio_produccion' => "PDN-{$sufijo}",
            'fecha_produccion' => '2026-02-02',
            'variedad_id' => $this->variedad->id,
            'total_cajas' => 10,
            'peso_neto_kg' => 100,
            'status' => 'en_almacen',
        ]);
    }

    private function detalle(EmbarqueEmpaque $embarque, ProduccionEmpaque $produccion, string $pallet, int $cajas, float $peso, ?float $bascula): void
    {
        EmbarqueEmpaqueDetalle::create([
            'embarque_id' => $embarque->id,
            'produccion_id' => $produccion->id,
            'numero_pallet' => $pallet,
            'cajas' => $cajas,
            'peso_kg' => $peso,
            'peso_bascula_kg' => $bascula,
        ]);
    }

    private function cargarDescarga($response): Spreadsheet
    {
        $ruta = $response->baseResponse->getFile()->getPathname();
        $copia = tempnam(sys_get_temp_dir(), 'rezaga_test_').'.xlsx';
        copy($ruta, $copia);
        @unlink($ruta);

        $libro = IOFactory::load($copia);
        @unlink($copia);

        return $libro;
    }

    /**
     * Las fórmulas se guardan con su valor calculado en PHP: recalcular el
     * libro con el motor debe dar lo mismo y ninguna celda debe dar error.
     */
    private function assertValoresGuardadosIgualAlMotor(Spreadsheet $libro): void
    {
        $formulas = 0;
        foreach ($libro->getWorksheetIterator() as $hoja) {
            foreach ($hoja->getRowIterator() as $fila) {
                foreach ($fila->getCellIterator() as $celda) {
                    if (! $celda->isFormula()) {
                        continue;
                    }
                    $formulas++;
                    $donde = $hoja->getTitle().'!'.$celda->getCoordinate();
                    $calculado = $celda->getCalculatedValue();

                    $this->assertFalse(is_string($calculado) && str_starts_with($calculado, '#'), "{$donde} da {$calculado}");
                    $this->assertEqualsWithDelta((float) $calculado, (float) $celda->getOldCalculatedValue(), 0.000001, $donde);
                }
            }
        }

        $this->assertGreaterThan(50, $formulas);
    }
}
