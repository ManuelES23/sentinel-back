<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\Cultivo;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaSemanal;
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

    /** Valores G a M de una fila: seis días y total. */
    private function dias(Worksheet $hoja, int $fila): array
    {
        return array_map(fn ($c) => $hoja->getCell($c.$fila)->getValue(), ['G', 'H', 'I', 'J', 'K', 'L', 'M']);
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
        $this->assertEquals([20, 10, 10, 10, 10, 10, 70], $this->dias($hoja, $this->fila($hoja, 'Total ha elote dulce')));
        $this->assertEquals([12450, 6100, 6175, 6500, 6400, 6600, 44225], $this->dias($hoja, $this->fila($hoja, 'Total cajas')));

        // Necesidades.
        $this->assertEquals([8230, 2430, 7144, 10048, 2631, 6000, 36483], $this->dias($hoja, $this->fila($hoja, 'TOTAL NECESIDAD')));
        $this->assertEquals(7270, $hoja->getCell('M'.$this->fila($hoja, 'Subtotal Texas'))->getValue());
        $this->assertEquals(29213, $hoja->getCell('M'.$this->fila($hoja, 'Subtotal Nogales'))->getValue());

        // Programa de empaque: lo que no tiene empaque (Caruso, 1,000 cj) sale en «Sin clasificar».
        $this->assertEquals(6270, $hoja->getCell('M'.$this->fila($hoja, 'Total empaque Texas'))->getValue());
        $this->assertEquals(1000, $hoja->getCell('M'.$this->fila($hoja, 'Sin clasificar (debe ser 0)'))->getValue());
        $this->assertEquals(35483, $hoja->getCell('M'.$this->fila($hoja, 'Total empaque'))->getValue());

        // Balance.
        $this->assertEquals([4220, 3670, -969, -3548, 3769, 600, 7742], $this->dias($hoja, $this->fila($hoja, 'Disponible del día')));
        $this->assertEquals([4220, 7890, 6921, 3373, 7142, 7742, 7742], $this->dias($hoja, $this->fila($hoja, 'Inventario acumulado')));
        $this->assertSame(-2893, (int) $hoja->getCell('M'.$this->fila($hoja, 'Acumulado Blanco'))->getValue());
        $this->assertSame(10635, (int) $hoja->getCell('M'.$this->fila($hoja, 'Acumulado Amarillo / Bicolor'))->getValue());

        // Por color: disponible ÷ 1,040 cajas por carga.
        $color = $this->fila($hoja, 'POR COLOR', 1, ['B']);
        $this->assertSame('CARGAS EQ.', $hoja->getCell('G'.($color + 1))->getValue());
        $this->assertEqualsWithDelta(10.23, $hoja->getCell('G'.$this->fila($hoja, 'Amarillo / Bicolor', $color, ['C']))->getValue(), 0.0001);
        $this->assertEqualsWithDelta(-2.78, $hoja->getCell('G'.$this->fila($hoja, 'Blanco', $color, ['C']))->getValue(), 0.0001);
        $this->assertEqualsWithDelta(7.44, $hoja->getCell('G'.$this->fila($hoja, 'Total', $color, ['C']))->getValue(), 0.0001);
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

    public function test_las_cifras_del_archivo_son_las_del_tablero(): void
    {
        $this->wk48();
        $payload = $this->getJson('/api/splendidfarms/administration/planeacion/programa?lunes=2026-11-23')->assertOk()->json('data');
        $hoja = $this->libroDe($this->descargar()->assertOk())->getSheetByName('WK48');

        // Campo: hectáreas y rendimiento por color.
        $amarillo = $this->fila($hoja, 'Amarillo');
        $this->assertSame(550, (int) $hoja->getCell('D'.$amarillo)->getValue());
        $this->assertEquals([7, 5, 5, 0, 3, 1, 21], $this->dias($hoja, $amarillo));
        $this->assertSame(650, (int) $hoja->getCell('D'.$this->fila($hoja, 'Bicolor'))->getValue());
        $this->assertSame(700, (int) $hoja->getCell('D'.$this->fila($hoja, 'Blanco'))->getValue());

        // Producción por color.
        $produccion = $this->fila($hoja, 'PRODUCCIÓN ESTIMADA (CAJAS)', 1, ['B']);
        $this->assertEquals([3850, 2750, 2750, 0, 1650, 550, 11550], $this->dias($hoja, $this->fila($hoja, 'Amarillo', $produccion, ['C'])));

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
        $this->assertEquals([0, 0, 6584, 0, 0, 0, 6584], array_map(fn ($v) => (int) $v, $this->dias($hoja, $vallarta)));

        $this->assertSame(
            collect($payload['necesidades'])->sum(fn ($n) => array_sum($n['cajas'])),
            (int) $hoja->getCell('M'.$this->fila($hoja, 'TOTAL NECESIDAD'))->getValue(),
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
        $this->assertEquals([0, 0, 0, 4, 0, 0, 4], $this->dias($hoja, $nogales));
        $texas = $this->fila($hoja, 'TEXAS', $embarques, ['C']);
        $this->assertSame('Sencillo', $hoja->getCell('D'.($texas + 1))->getValue());
        $this->assertEquals([2, 0, 0, 0, 0, 0, 2], $this->dias($hoja, $texas + 1));
        $this->assertEquals([2, 0, 0, 4, 0, 0, 6], $this->dias($hoja, $this->fila($hoja, 'Total embarques')));

        $notas = $this->fila($hoja, 'NOTAS DE LA SEMANA', 1, ['B']);
        $this->assertSame('Llega lluvia el jueves', $hoja->getCell('B'.($notas + 1))->getValue());
    }

    public function test_cultivo_elegido_y_cultivo_inexistente(): void
    {
        $this->wk48();
        $otro = Cultivo::create(['nombre' => 'Aguacate']);

        $hoja = $this->libroDe($this->descargar('lunes=2026-11-23&cultivo_id='.$this->cultivo->id)->assertOk())->getSheetByName('WK48');
        $this->assertSame(44225, (int) $hoja->getCell('M'.$this->fila($hoja, 'Total cajas'))->getValue());

        // Otro cultivo sin datos esa semana: sale la hoja con sus secciones y en cero.
        $hoja = $this->libroDe($this->descargar('lunes=2026-11-23&cultivo_id='.$otro->id)->assertOk())->getSheetByName('WK48');
        $this->assertSame('CAMPO — HECTÁREAS A COSECHAR', $hoja->getCell('B4')->getValue());
        $this->assertSame(0, (int) $hoja->getCell('M'.$this->fila($hoja, 'Total cajas'))->getValue());
        $this->assertSame(0, (int) $hoja->getCell('M'.$this->fila($hoja, 'TOTAL NECESIDAD'))->getValue());

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
}
