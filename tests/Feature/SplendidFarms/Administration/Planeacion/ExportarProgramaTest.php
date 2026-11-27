<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\Cultivo;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Variedad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * GET planeacion/programa/exportar: la semana en un .xlsx con la misma
 * distribución que la hoja WK del Excel «PROGRAMA CORN OI 2026-2027» (solo
 * valores) y cada cifra tomada del payload del Tablero.
 */
class ExportarProgramaTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion/programa/exportar';

    private const SECCIONES = [
        'CAMPO — HECTÁREAS A COSECHAR',
        'PRODUCCIÓN ESTIMADA (CAJAS)',
        'NECESIDADES VENTAS (CAJAS)',
        'PROGRAMA DE EMPAQUE (se calcula de las necesidades)',
        'BALANCE PRODUCCIÓN vs NECESIDAD',
        'POR COLOR',
        'EMBARQUES (número de cargas)',
        'NOTAS DE LA SEMANA',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));
    }

    private function wk48(): ProgramaSemanal
    {
        $programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $this->cargarWk48($programa);

        return $programa;
    }

    private function descargar(string $consulta = 'lunes=2026-11-23'): TestResponse
    {
        return $this->get(self::URL.'?'.$consulta);
    }

    private function libroDe(TestResponse $respuesta): Spreadsheet
    {
        $ruta = $respuesta->baseResponse->getFile()->getPathname();
        $copia = tempnam(sys_get_temp_dir(), 'programa_test_').'.xlsx';
        copy($ruta, $copia);
        @unlink($ruta);

        $libro = IOFactory::load($copia);
        @unlink($copia);

        return $libro;
    }

    /** Número de fila de la primera celda de esas columnas con ese texto, a partir de $desde. */
    private function fila(Worksheet $hoja, string $texto, int $desde = 1, array $columnas = ['B', 'C']): int
    {
        for ($fila = $desde; $fila <= $hoja->getHighestRow(); $fila++) {
            foreach ($columnas as $columna) {
                if ($hoja->getCell($columna.$fila)->getValue() === $texto) {
                    return $fila;
                }
            }
        }
        $this->fail("No hay una fila con «{$texto}».");
    }

    /** Valores G a N de una fila: siete días (lunes a domingo) y total. */
    private function dias(Worksheet $hoja, int $fila): array
    {
        return array_map(fn ($c) => $hoja->getCell($c.$fila)->getValue(), ['G', 'H', 'I', 'J', 'K', 'L', 'M', 'N']);
    }

    public function test_exporta_wk48_con_las_secciones_y_totales(): void
    {
        $this->wk48();

        $respuesta = $this->descargar()->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('Programa_WK48_2026-11-23.xlsx', $respuesta->headers->get('Content-Disposition'));

        $libro = $this->libroDe($respuesta);
        $this->assertSame(['WK48'], $libro->getSheetNames());
        $hoja = $libro->getSheetByName('WK48');
        $this->assertNotNull($hoja);

        $this->assertSame('PROGRAMA SEMANA 48', $hoja->getCell('F2')->getValue());

        // Las ocho secciones, con sus nombres, en orden.
        $anterior = 0;
        foreach (self::SECCIONES as $nombre) {
            $fila = $this->fila($hoja, $nombre, 1, ['B']);
            $this->assertGreaterThan($anterior, $fila, "«{$nombre}» fuera de orden.");
            $anterior = $fila;
        }

        // Campo y producción.
        $this->assertEquals([20, 10, 10, 10, 10, 10, 0, 70], $this->dias($hoja, $this->fila($hoja, 'Total ha elote dulce')));
        $this->assertEquals([12450, 6100, 6175, 6500, 6400, 6600, 0, 44225], $this->dias($hoja, $this->fila($hoja, 'Total cajas')));

        // Necesidades.
        $this->assertEquals([8230, 2430, 7144, 10048, 2631, 6000, 0, 36483], $this->dias($hoja, $this->fila($hoja, 'TOTAL NECESIDAD')));
        $this->assertEquals(7270, $hoja->getCell('N'.$this->fila($hoja, 'Subtotal Texas'))->getValue());
        $this->assertEquals(29213, $hoja->getCell('N'.$this->fila($hoja, 'Subtotal Nogales'))->getValue());

        // Programa de empaque: lo que no tiene empaque (Caruso, 1,000 cj) sale en «Sin clasificar».
        $this->assertEquals(6270, $hoja->getCell('N'.$this->fila($hoja, 'Total empaque Texas'))->getValue());
        $this->assertEquals(1000, $hoja->getCell('N'.$this->fila($hoja, 'Sin clasificar (debe ser 0)'))->getValue());
        $this->assertEquals(35483, $hoja->getCell('N'.$this->fila($hoja, 'Total empaque'))->getValue());

        // Balance.
        $this->assertEquals([4220, 3670, -969, -3548, 3769, 600, 0, 7742], $this->dias($hoja, $this->fila($hoja, 'Disponible del día')));
        $this->assertEquals([4220, 7890, 6921, 3373, 7142, 7742, 7742, 7742], $this->dias($hoja, $this->fila($hoja, 'Inventario acumulado')));
        $this->assertSame(-2893, (int) $hoja->getCell('N'.$this->fila($hoja, 'Acumulado Blanco'))->getValue());
        $this->assertSame(10635, (int) $hoja->getCell('N'.$this->fila($hoja, 'Acumulado Amarillo / Bicolor'))->getValue());

        // Por color: disponible ÷ 1,040 cajas por carga.
        $color = $this->fila($hoja, 'POR COLOR', 1, ['B']);
        $this->assertSame('SOBRANTE', $hoja->getCell('F'.($color + 1))->getValue());
        $this->assertSame('DISPONIBLE', $hoja->getCell('G'.($color + 1))->getValue());
        $this->assertSame('CARGAS EQ.', $hoja->getCell('H'.($color + 1))->getValue());
        $this->assertEqualsWithDelta(10.23, $hoja->getCell('H'.$this->fila($hoja, 'Amarillo / Bicolor', $color, ['C']))->getValue(), 0.0001);
        $this->assertEqualsWithDelta(-2.78, $hoja->getCell('H'.$this->fila($hoja, 'Blanco', $color, ['C']))->getValue(), 0.0001);
        $total = $this->fila($hoja, 'Total', $color, ['C']);
        $this->assertEqualsWithDelta(7.44, $hoja->getCell('H'.$total)->getValue(), 0.0001);
        // Sin sobrantes: la columna SOBRANTE es 0 y DISPONIBLE no cambia (producción − necesidad).
        $this->assertSame(0, (int) $hoja->getCell('F'.$total)->getValue());
        $this->assertSame(44225 - 36483, (int) $hoja->getCell('G'.$total)->getValue());
    }

    public function test_el_archivo_trae_valores_y_no_formulas(): void
    {
        $this->wk48();

        $hoja = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');
        foreach ($hoja->getRowIterator() as $fila) {
            foreach ($fila->getCellIterator() as $celda) {
                $this->assertFalse($celda->isFormula(), 'La celda '.$celda->getCoordinate().' trae una fórmula.');
            }
        }
    }

    public function test_produccion_estimada_anota_los_bins_por_hectarea_de_cada_color(): void
    {
        $this->wk48();
        \App\Models\Planeacion\RendimientoVariedad::where('variedad_id', $this->variedades['Amarillo']->id)->update(['bins_por_hectarea' => 45.5]);
        $hoja = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');

        $produccion = $this->fila($hoja, 'PRODUCCIÓN ESTIMADA (CAJAS)', 1, ['B']);
        $this->assertSame('45.5 bins/ha', $hoja->getCell('D'.$this->fila($hoja, 'Amarillo', $produccion, ['C']))->getValue());
        $this->assertNull($hoja->getCell('D'.$this->fila($hoja, 'Bicolor', $produccion, ['C']))->getValue());
        $this->assertEquals([3850, 2750, 2750, 0, 1650, 550, 0, 11550], $this->dias($hoja, $this->fila($hoja, 'Amarillo', $produccion, ['C'])));
    }

    public function test_las_cifras_del_archivo_son_las_del_tablero(): void
    {
        $this->wk48();
        $payload = $this->getJson('/api/splendidfarms/administration/planeacion/programa?lunes=2026-11-23')->assertOk()->json('data');
        $hoja = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');

        // Campo: hectáreas y rendimiento por color.
        $amarillo = $this->fila($hoja, 'Amarillo');
        $this->assertSame(550, (int) $hoja->getCell('D'.$amarillo)->getValue());
        $this->assertEquals([7, 5, 5, 0, 3, 1, 0, 21], $this->dias($hoja, $amarillo));
        $this->assertSame(650, (int) $hoja->getCell('D'.$this->fila($hoja, 'Bicolor'))->getValue());
        $this->assertSame(700, (int) $hoja->getCell('D'.$this->fila($hoja, 'Blanco'))->getValue());

        // Producción por color.
        $produccion = $this->fila($hoja, 'PRODUCCIÓN ESTIMADA (CAJAS)', 1, ['B']);
        $this->assertEquals([3850, 2750, 2750, 0, 1650, 550, 0, 11550], $this->dias($hoja, $this->fila($hoja, 'Amarillo', $produccion, ['C'])));

        // Cada grupo del balance del payload aparece con su acumulado y su total.
        foreach ($payload['balance'] as $clave => $g) {
            $nombre = collect($payload['grupos'])->first(fn ($x) => (string) $x['id'] === (string) $clave)['nombre'];
            $this->assertEquals(
                array_merge($g['acumulado'], [$g['final']]),
                $this->dias($hoja, $this->fila($hoja, 'Acumulado '.$nombre)),
            );
        }

        // Un renglón de necesidades: cliente, presentación, color, empaque y cajas por día.
        $vallarta = $this->fila($hoja, 'Vallarta', $this->fila($hoja, 'NECESIDADES VENTAS (CAJAS)', 1, ['B']), ['C']);
        $this->assertSame('Bulk', $hoja->getCell('D'.$vallarta)->getValue());
        $this->assertSame('Blanco', $hoja->getCell('E'.$vallarta)->getValue());
        $this->assertSame('Cartón', $hoja->getCell('F'.$vallarta)->getValue());
        $this->assertEquals([0, 0, 6584, 0, 0, 0, 0, 6584], array_map(fn ($v) => (int) $v, $this->dias($hoja, $vallarta)));

        $this->assertSame(
            collect($payload['necesidades'])->sum(fn ($n) => array_sum($n['cajas'])),
            (int) $hoja->getCell('N'.$this->fila($hoja, 'TOTAL NECESIDAD'))->getValue(),
        );
    }

    public function test_embarques_y_notas_de_la_semana(): void
    {
        $programa = $this->wk48();
        $programa->update(['notas' => 'Llega lluvia el jueves']);
        ProgramaEmbarque::create([
            'programa_id' => $programa->id,
            'cultivo_id' => $this->cultivo->id,
            'bodega_id' => $this->bodega('Nogales')->id,
            'tipo' => 'full',
            'dia' => 3,
            'cargas' => 4,
        ]);
        ProgramaEmbarque::create([
            'programa_id' => $programa->id,
            'cultivo_id' => $this->cultivo->id,
            'bodega_id' => $this->bodega('Texas')->id,
            'tipo' => 'sencillo',
            'dia' => 0,
            'cargas' => 2,
        ]);

        $hoja = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');

        $embarques = $this->fila($hoja, 'EMBARQUES (número de cargas)', 1, ['B']);
        $nogales = $this->fila($hoja, 'NOGALES', $embarques, ['C']);
        $this->assertSame('Full', $hoja->getCell('D'.$nogales)->getValue());
        $this->assertEquals([0, 0, 0, 4, 0, 0, 0, 4], $this->dias($hoja, $nogales));
        $texas = $this->fila($hoja, 'TEXAS', $embarques, ['C']);
        $this->assertSame('Sencillo', $hoja->getCell('D'.($texas + 1))->getValue());
        $this->assertEquals([2, 0, 0, 0, 0, 0, 0, 2], $this->dias($hoja, $texas + 1));
        $this->assertEquals([2, 0, 0, 4, 0, 0, 0, 6], $this->dias($hoja, $this->fila($hoja, 'Total embarques')));

        $notas = $this->fila($hoja, 'NOTAS DE LA SEMANA', 1, ['B']);
        $this->assertSame('Llega lluvia el jueves', $hoja->getCell('B'.($notas + 1))->getValue());
    }

    public function test_el_domingo_sale_en_la_columna_m_y_el_total_en_la_n(): void
    {
        $programa = $this->wk48();
        // Domingo: 2 ha de Blanco (700 cj/ha = 1,400 cj), 500 cj de necesidad y 3 cargas.
        $this->corte($programa, 'Blanco', 6, 2);
        $this->necesidad($programa, 'Nogales', 'Vallarta', 'Bulk', ['Blanco'], 'Cartón', [0, 0, 0, 0, 0, 0, 500]);
        ProgramaEmbarque::create([
            'programa_id' => $programa->id,
            'cultivo_id' => $this->cultivo->id,
            'bodega_id' => $this->bodega('Nogales')->id,
            'tipo' => 'full',
            'dia' => 6,
            'cargas' => 3,
        ]);

        $hoja = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');

        // Encabezado de «Campo»: L M X J V S D en G a M y el total en N.
        $campo = $this->fila($hoja, 'COLOR', 1, ['C']);
        $this->assertSame(['L', 'M', 'X', 'J', 'V', 'S', 'D', 'HA TOTAL'], array_map(fn ($c) => $hoja->getCell($c.$campo)->getValue(), ['G', 'H', 'I', 'J', 'K', 'L', 'M', 'N']));
        $this->assertNull($hoja->getCell('O'.$campo)->getValue());

        // Campo, producción, necesidades y balance: el domingo en M y el total en N.
        $this->assertEquals([20, 10, 10, 10, 10, 10, 2, 72], $this->dias($hoja, $this->fila($hoja, 'Total ha elote dulce')));
        $this->assertEquals([12450, 6100, 6175, 6500, 6400, 6600, 1400, 45625], $this->dias($hoja, $this->fila($hoja, 'Total cajas')));
        $this->assertEquals([8230, 2430, 7144, 10048, 2631, 6000, 500, 36983], $this->dias($hoja, $this->fila($hoja, 'TOTAL NECESIDAD')));
        $this->assertEquals([4220, 7890, 6921, 3373, 7142, 7742, 8642, 8642], $this->dias($hoja, $this->fila($hoja, 'Inventario acumulado')));
        $this->assertEquals(8642, $hoja->getCell('N'.$this->fila($hoja, 'Inventario acumulado'))->getValue());

        // Embarques: las 3 cargas del domingo en M, y el total de la semana en N.
        $embarques = $this->fila($hoja, 'EMBARQUES (número de cargas)', 1, ['B']);
        $this->assertEquals([0, 0, 0, 0, 0, 0, 3, 3], $this->dias($hoja, $this->fila($hoja, 'NOGALES', $embarques, ['C'])));
        $this->assertEquals([0, 0, 0, 0, 0, 0, 3, 3], $this->dias($hoja, $this->fila($hoja, 'Total embarques')));
    }

    /** Sobrante de Texas · Bulk · Amarillo · RPC: misma llave que un renglón de venta de la semana. */
    private function sobranteDeTexas(ProgramaSemanal $programa, array $cajas = [1000, 0, 0, 0, 0, 0, 0]): void
    {
        $attrs = [
            'bodega_id' => $this->bodega('Texas')->id,
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
        ];
        foreach ($cajas as $dia => $valor) {
            $attrs["cajas_{$dia}"] = $valor;
        }
        $this->crearSobrante($programa, $attrs, [$this->variedades['Amarillo']->id]);
    }

    public function test_el_excel_trae_el_sobrante_en_empaque_y_balance(): void
    {
        $this->sobranteDeTexas($this->wk48());

        $hoja = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');
        $empaque = $this->fila($hoja, 'PROGRAMA DE EMPAQUE (se calcula de las necesidades)', 1, ['B']);
        $texas = $this->fila($hoja, 'TEXAS', $empaque, ['B']);

        // Dentro de su destino: «Sobrante · RPC» con 1,000 el lunes y en el total.
        $sobrante = $this->fila($hoja, 'Sobrante · RPC', $texas, ['F']);
        $this->assertSame('Bulk', $hoja->getCell('D'.$sobrante)->getValue());
        $this->assertSame('Amarillo / Bicolor', $hoja->getCell('E'.$sobrante)->getValue());
        $this->assertEquals([1000, 0, 0, 0, 0, 0, 0, 1000], $this->dias($hoja, $sobrante));
        $this->assertLessThan($this->fila($hoja, 'Total empaque Texas', $texas), $sobrante);

        // Suma al total del destino (6,270 de venta + 1,000) y no a «Sin clasificar».
        $this->assertEquals(7270, $hoja->getCell('N'.$this->fila($hoja, 'Total empaque Texas'))->getValue());
        $this->assertEquals(1000, $hoja->getCell('N'.$this->fila($hoja, 'Sin clasificar (debe ser 0)'))->getValue());
        $this->assertEquals(35483 + 1000, $hoja->getCell('N'.$this->fila($hoja, 'Total empaque'))->getValue());

        // Balance: «Sobrante asignado» justo debajo de «Necesidad» y antes de «Disponible del día».
        $balance = $this->fila($hoja, 'BALANCE PRODUCCIÓN vs NECESIDAD', 1, ['B']);
        $necesidad = $this->fila($hoja, 'Necesidad', $balance);
        $this->assertSame('Sobrante asignado', $hoja->getCell('C'.($necesidad + 1))->getValue());
        $this->assertSame('Disponible del día', $hoja->getCell('C'.($necesidad + 2))->getValue());
        $this->assertEquals([1000, 0, 0, 0, 0, 0, 0, 1000], $this->dias($hoja, $necesidad + 1));
        // La necesidad sigue siendo solo de ventas.
        $this->assertEquals([8230, 2430, 7144, 10048, 2631, 6000, 0, 36483], $this->dias($hoja, $necesidad));
    }

    public function test_por_color_resta_el_sobrante_asignado_del_disponible(): void
    {
        $this->sobranteDeTexas($this->wk48());

        $hoja = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');
        $color = $this->fila($hoja, 'POR COLOR', 1, ['B']);
        $total = $this->fila($hoja, 'Total', $color, ['C']);
        $amarillo = $this->fila($hoja, 'Amarillo / Bicolor', $color, ['C']);

        $this->assertSame('SOBRANTE', $hoja->getCell('F'.($color + 1))->getValue());
        $this->assertSame(1000, (int) $hoja->getCell('F'.$total)->getValue());
        $this->assertSame(1000, (int) $hoja->getCell('F'.$amarillo)->getValue());

        // Igual que el balance y el Tablero: el DISPONIBLE total es el «Disponible del día» de la semana.
        $balance = $this->fila($hoja, 'BALANCE PRODUCCIÓN vs NECESIDAD', 1, ['B']);
        $delDia = (int) $hoja->getCell('N'.$this->fila($hoja, 'Disponible del día', $balance))->getValue();
        $this->assertSame(7742 - 1000, $delDia);
        $this->assertSame($delDia, (int) $hoja->getCell('G'.$total)->getValue());
        $this->assertEqualsWithDelta(round($delDia / 1040, 2), $hoja->getCell('H'.$total)->getValue(), 0.0001);
    }

    public function test_el_total_de_empaque_no_junta_el_sobrante_con_la_venta_de_misma_llave(): void
    {
        $this->sobranteDeTexas($this->wk48(), [1000, 0, 0, 0, 0, 0, 400]);
        $payload = $this->getJson('/api/splendidfarms/administration/planeacion/programa?lunes=2026-11-23')->assertOk()->json('data');
        $bulk = $this->presentacion('Bulk')->id;
        $rpc = $this->tipoEmpaque('RPC')->id;
        $ventas = collect($payload['empaque'])->filter(fn ($r) => $r['clase'] === 'ok' && $r['presentacion_id'] === $bulk
            && $r['tipo_empaque_id'] === $rpc && (string) $r['grupo_id'] === (string) $this->grupo('Amarillo / Bicolor')->id);
        $ventaCajas = $ventas->map(fn ($r) => array_sum($r['cajas']))->sum();
        $this->assertGreaterThan(0, $ventaCajas);

        $hoja = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');
        $total = $this->fila($hoja, 'TOTAL', $this->fila($hoja, 'PROGRAMA DE EMPAQUE (se calcula de las necesidades)', 1, ['B']), ['B']);

        // En el bloque TOTAL hay un renglón de venta (solo ventas) y uno de sobrante aparte.
        $sobrante = $this->fila($hoja, 'Sobrante · RPC', $total, ['F']);
        $this->assertEquals([1000, 0, 0, 0, 0, 0, 400, 1400], $this->dias($hoja, $sobrante));

        $venta = null;
        for ($f = $total; $f < $sobrante; $f++) {
            if ($hoja->getCell('D'.$f)->getValue() === 'Bulk' && $hoja->getCell('E'.$f)->getValue() === 'Amarillo / Bicolor' && $hoja->getCell('F'.$f)->getValue() === 'RPC') {
                $venta = $f;
            }
        }
        $this->assertNotNull($venta, 'Falta el renglón de venta Bulk · Amarillo / Bicolor · RPC en el TOTAL.');
        $this->assertEquals($ventaCajas, $hoja->getCell('N'.$venta)->getValue());

        // 35,483 de venta clasificada + 1,400 de sobrante (el sin clasificar no entra).
        $this->assertEquals(35483 + 1400,$hoja->getCell('N'.$this->fila($hoja, 'Total empaque'))->getValue());
        $this->assertEquals(1000, $hoja->getCell('N'.$this->fila($hoja, 'Sin clasificar (debe ser 0)', $total))->getValue());
    }

    public function test_el_excel_trae_empaque_y_balance_por_dia_de_empaque(): void
    {
        $this->antelacion('Texas', 2);
        ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $wk48 = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $this->necesidad($wk48, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1800, 0, 0, 0, 0, 0, 0]);

        $hoja = $this->libroDe($this->descargar('lunes=2026-11-16&cultivo_id='.$this->cultivo->id)->assertOk())->getSheetByName('WK47');

        // Programa de empaque: el renglón de Texas en la columna del sábado (L).
        $empaque = $this->fila($hoja, 'PROGRAMA DE EMPAQUE (se calcula de las necesidades)', 1, ['B']);
        $texas = $this->fila($hoja, 'TEXAS', $empaque, ['B']);
        $renglon = $this->fila($hoja, 'RPC', $texas, ['F']);
        $this->assertSame('Bulk', $hoja->getCell('D'.$renglon)->getValue());
        $this->assertEquals([0, 0, 0, 0, 0, 1800, 0, 1800], $this->dias($hoja, $renglon));
        $this->assertEquals(1800, $hoja->getCell('N'.$this->fila($hoja, 'Total empaque Texas'))->getValue());

        // Balance: la necesidad del sábado; NECESIDADES VENTAS (por día de llegada) queda en cero.
        $balance = $this->fila($hoja, 'BALANCE PRODUCCIÓN vs NECESIDAD', 1, ['B']);
        $this->assertEquals([0, 0, 0, 0, 0, 1800, 0, 1800], $this->dias($hoja, $this->fila($hoja, 'Necesidad', $balance)));
        $this->assertEquals(0, $hoja->getCell('N'.$this->fila($hoja, 'TOTAL NECESIDAD'))->getValue());

        // WK48 ya no lo trae en el empaque ni en el balance.
        $hoja = $this->libroDe($this->descargar('lunes=2026-11-23&cultivo_id='.$this->cultivo->id)->assertOk())->getSheetByName('WK48');
        $balance = $this->fila($hoja, 'BALANCE PRODUCCIÓN vs NECESIDAD', 1, ['B']);
        $this->assertEquals(0, $hoja->getCell('N'.$this->fila($hoja, 'Necesidad', $balance))->getValue());
        $this->assertEquals(1800, $hoja->getCell('N'.$this->fila($hoja, 'TOTAL NECESIDAD'))->getValue());
    }

    public function test_el_cultivo_por_defecto_considera_el_empaque_de_pedidos_de_la_semana_siguiente(): void
    {
        // El elote tiene temporada que toca WK47 pero ningún dato; el otro cultivo solo tiene un pedido de
        // WK48 en Texas (2 días) que se empaca el sábado de WK47: ese es el que debe abrir el Excel.
        $this->temporada->update(['fecha_inicio' => '2026-11-01']);
        $this->antelacion('Texas', 2);
        $otro = Cultivo::create(['nombre' => 'Zarzamora']);
        $variedad = Variedad::create(['cultivo_id' => $otro->id, 'nombre' => 'Tupy', 'user_id' => $this->autor->id]);
        $presentacion = Presentacion::create(['cultivo_id' => $otro->id, 'nombre' => 'Canasta', 'orden' => 1, 'is_active' => true]);
        ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $wk48 = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $necesidad = ProgramaNecesidad::create([
            'programa_id' => $wk48->id,
            'bodega_id' => $this->bodega('Texas')->id,
            'cliente_id' => null,
            'cliente_nombre' => 'Walmart',
            'cultivo_id' => $otro->id,
            'presentacion_id' => $presentacion->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
            'cajas_0' => 1800,
        ]);
        $necesidad->variedades()->attach([$variedad->id]);

        $hoja = $this->libroDe($this->descargar('lunes=2026-11-16')->assertOk())->getSheetByName('WK47');

        $this->assertStringContainsString('Zarzamora', (string) $hoja->getCell('B1')->getValue());
        $empaque = $this->fila($hoja, 'PROGRAMA DE EMPAQUE (se calcula de las necesidades)', 1, ['B']);
        $this->assertEquals(1800, $hoja->getCell('N'.$this->fila($hoja, 'Total empaque Texas', $empaque))->getValue());
    }

    public function test_cultivo_elegido_y_cultivo_inexistente(): void
    {
        $this->wk48();
        $otro = Cultivo::create(['nombre' => 'Aguacate']);

        $hoja = $this->libroDe($this->descargar('lunes=2026-11-23&cultivo_id='.$this->cultivo->id)->assertOk())->getSheetByName('WK48');
        $this->assertSame(44225, (int) $hoja->getCell('N'.$this->fila($hoja, 'Total cajas'))->getValue());

        // Otro cultivo sin datos esa semana: sale la hoja con sus secciones y en cero.
        $hoja = $this->libroDe($this->descargar('lunes=2026-11-23&cultivo_id='.$otro->id)->assertOk())->getSheetByName('WK48');
        $this->assertSame('CAMPO — HECTÁREAS A COSECHAR', $hoja->getCell('B4')->getValue());
        $this->assertSame(0, (int) $hoja->getCell('N'.$this->fila($hoja, 'Total cajas'))->getValue());
        $this->assertSame(0, (int) $hoja->getCell('N'.$this->fila($hoja, 'TOTAL NECESIDAD'))->getValue());

        $this->getJson(self::URL.'?lunes=2026-11-23&cultivo_id=999999')->assertStatus(422)->assertJsonValidationErrors('cultivo_id');
    }

    public function test_sin_permiso_responde_403(): void
    {
        $this->wk48();
        Sanctum::actingAs($this->usuarioCon(['plan-corte' => ['view', 'capturar'], 'necesidades-venta' => ['view']]));

        $this->getJson(self::URL.'?lunes=2026-11-23')->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para esta acción de Planeación.');
    }

    public function test_el_lunes_es_obligatorio_y_debe_ser_lunes(): void
    {
        $this->getJson(self::URL)->assertStatus(422)->assertJsonValidationErrors('lunes');
        $this->getJson(self::URL.'?lunes=2026-11-24')->assertStatus(422)->assertJsonValidationErrors('lunes');
        $this->getJson(self::URL.'?lunes=23-11-2026')->assertStatus(422)->assertJsonValidationErrors('lunes');
        $this->getJson(self::URL.'?lunes=2026-02-30')->assertStatus(422)->assertJsonValidationErrors('lunes');
    }

    public function test_una_semana_sin_programa_se_trata_igual_que_el_tablero(): void
    {
        $this->assertSame(0, DB::table('programas_semanales')->count());

        $hoja = $this->libroDe($this->descargar('lunes=2026-12-07')->assertOk())->getSheetByName('WK50');

        $this->assertNotNull($hoja);
        $this->assertSame('PROGRAMA SEMANA 50', $hoja->getCell('F2')->getValue());
        // Igual que GET programa: abrir la semana la crea.
        $this->assertSame(1, DB::table('programas_semanales')->count());
    }

    // ── Domingo opcional ────────────────────────────────

    /** Valores G a M de una fila de una semana sin domingo: seis días (lunes a sábado) y total. */
    private function diasSinDomingo(Worksheet $hoja, int $fila): array
    {
        return array_map(fn ($c) => $hoja->getCell($c.$fila)->getValue(), ['G', 'H', 'I', 'J', 'K', 'L', 'M']);
    }

    public function test_sin_domingo_el_excel_omite_la_columna_del_domingo_y_el_total_pasa_a_la_m(): void
    {
        $programa = $this->wk48();
        $programa->forceFill(['trabaja_domingo' => false, 'domingo_corte' => false, 'domingo_empaque' => false, 'domingo_embarques' => false])->save();

        $hoja = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');

        // Encabezado de «Campo»: L M X J V S y el total en la M; la N queda vacía.
        $encabezado = $this->fila($hoja, 'COLOR', 1, ['C']);
        $this->assertSame(['L', 'M', 'X', 'J', 'V', 'S', 'HA TOTAL'], $this->diasSinDomingo($hoja, $encabezado));
        $this->assertNull($hoja->getCell('N'.$encabezado)->getValue());

        // Los totales de siempre, uno por fila, ahora con seis días.
        $this->assertEquals([20, 10, 10, 10, 10, 10, 70], $this->diasSinDomingo($hoja, $this->fila($hoja, 'Total ha elote dulce')));
        $this->assertEquals([12450, 6100, 6175, 6500, 6400, 6600, 44225], $this->diasSinDomingo($hoja, $this->fila($hoja, 'Total cajas')));
        $this->assertEquals([8230, 2430, 7144, 10048, 2631, 6000, 36483], $this->diasSinDomingo($hoja, $this->fila($hoja, 'TOTAL NECESIDAD')));
        $this->assertEquals([4220, 3670, -969, -3548, 3769, 600, 7742], $this->diasSinDomingo($hoja, $this->fila($hoja, 'Disponible del día')));
        $this->assertEquals([4220, 7890, 6921, 3373, 7142, 7742, 7742], $this->diasSinDomingo($hoja, $this->fila($hoja, 'Inventario acumulado')));
        $this->assertEquals(35483, $hoja->getCell('M'.$this->fila($hoja, 'Total empaque'))->getValue());

        // En ninguna fila queda nada en la N, y el total de la fila es la suma de sus seis días.
        for ($fila = 1; $fila <= $hoja->getHighestRow(); $fila++) {
            $this->assertNull($hoja->getCell('N'.$fila)->getValue(), "N{$fila} debería estar vacía.");
        }
        $this->assertSame('TOTAL', $hoja->getCell('M'.$this->fila($hoja, 'TEXAS', 1, ['B']))->getValue());
        $filaCajas = $this->fila($hoja, 'Total cajas');
        $this->assertEquals(array_sum(array_slice($this->diasSinDomingo($hoja, $filaCajas), 0, 6)), $hoja->getCell('M'.$filaCajas)->getValue());

        // Título de sección y notas llegan hasta la M, y la M lleva el ancho del total.
        $titulo = $this->fila($hoja, 'CAMPO — HECTÁREAS A COSECHAR', 1, ['B']);
        $this->assertSame('FF1F4E3D', $hoja->getStyle('M'.$titulo)->getFill()->getStartColor()->getARGB());
        $this->assertNotSame('FF1F4E3D', $hoja->getStyle('N'.$titulo)->getFill()->getStartColor()->getARGB());
        $notas = $this->fila($hoja, 'NOTAS DE LA SEMANA', 1, ['B']);
        $this->assertSame(['B'.($notas + 1).':M'.($notas + 4)], array_values($hoja->getMergeCells()));
        $this->assertEquals(12, $hoja->getColumnDimension('M')->getWidth());
    }

    public function test_sin_domingo_es_el_mismo_excel_que_con_domingo_sin_esa_columna(): void
    {
        $programa = $this->wk48();
        $con = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');
        $programa->forceFill(['trabaja_domingo' => false, 'domingo_corte' => false, 'domingo_empaque' => false, 'domingo_embarques' => false])->save();
        $sin = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');

        $this->assertSame($con->getHighestRow(), $sin->getHighestRow());
        for ($fila = 1; $fila <= $con->getHighestRow(); $fila++) {
            foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $c) {
                $this->assertSame($con->getCell($c.$fila)->getValue(), $sin->getCell($c.$fila)->getValue(), "{$c}{$fila}");
            }
            // El total se movió de la N a la M; el domingo (que valía cero) no se escribe.
            $this->assertSame($con->getCell('N'.$fila)->getValue(), $sin->getCell('M'.$fila)->getValue(), "total de la fila {$fila}");
        }
    }

    public function test_con_un_solo_proceso_con_domingo_el_excel_trae_la_columna_del_domingo(): void
    {
        // Corte y Embarques sin domingo, Empaque con domingo: «alguno» trabaja el domingo.
        $programa = $this->wk48();
        $programa->forceFill(['trabaja_domingo' => true, 'domingo_corte' => false, 'domingo_empaque' => true, 'domingo_embarques' => false])->save();

        $hoja = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');

        $encabezado = $this->fila($hoja, 'COLOR', 1, ['C']);
        $this->assertSame(['L', 'M', 'X', 'J', 'V', 'S', 'D', 'HA TOTAL'], $this->dias($hoja, $encabezado));
        $this->assertEquals(70, $hoja->getCell('N'.$this->fila($hoja, 'Total ha elote dulce'))->getValue());

        // Solo Empaque sin domingo (Corte y Embarques sí): también hay columna de domingo.
        $programa->forceFill(['domingo_corte' => true, 'domingo_empaque' => false, 'domingo_embarques' => true])->save();
        $hoja = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');
        $this->assertSame(['L', 'M', 'X', 'J', 'V', 'S', 'D', 'HA TOTAL'], $this->dias($hoja, $this->fila($hoja, 'COLOR', 1, ['C'])));
    }

    public function test_con_domingo_el_excel_sigue_igual_con_siete_dias_y_el_total_en_la_n(): void
    {
        $this->wk48()->forceFill(['trabaja_domingo' => true])->save();

        $hoja = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');

        $encabezado = $this->fila($hoja, 'COLOR', 1, ['C']);
        $this->assertSame(['L', 'M', 'X', 'J', 'V', 'S', 'D', 'HA TOTAL'], $this->dias($hoja, $encabezado));
        $this->assertEquals([20, 10, 10, 10, 10, 10, 0, 70], $this->dias($hoja, $this->fila($hoja, 'Total ha elote dulce')));
        $titulo = $this->fila($hoja, 'CAMPO — HECTÁREAS A COSECHAR', 1, ['B']);
        $this->assertSame('FF1F4E3D', $hoja->getStyle('N'.$titulo)->getFill()->getStartColor()->getARGB());
        $notas = $this->fila($hoja, 'NOTAS DE LA SEMANA', 1, ['B']);
        $this->assertSame(['B'.($notas + 1).':N'.($notas + 4)], array_values($hoja->getMergeCells()));
    }
}
