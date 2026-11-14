<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\Cultivo;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaInventarioInicial;
use App\Models\Planeacion\ProgramaSemanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Resumen de temporada: una fila por semana (hoja RESUMEN del Excel) más el
 * disponible por grupo de balance.
 */
class ResumenTemporadaTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/administration/planeacion/resumen';

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
    }

    private function comoPlaneacion(): void
    {
        Sanctum::actingAs($this->usuarioCon(['resumen-temporada' => ['view']]));
    }

    private function url(array $extra = []): string
    {
        return self::BASE.'?'.http_build_query(['temporada_id' => $this->temporada->id] + $extra);
    }

    private function semanaDe(array $semanas, string $lunes): array
    {
        foreach ($semanas as $s) {
            if ($s['lunes'] === $lunes) {
                return $s;
            }
        }
        $this->fail("No hay semana con lunes $lunes.");
    }

    public function test_wk48_cuadra_con_la_hoja_resumen(): void
    {
        $this->cargarWk48(ProgramaSemanal::create(['lunes' => '2026-11-23']));
        $this->comoPlaneacion();

        $r = $this->getJson($this->url())->assertOk()->assertJsonPath('success', true);
        $datos = $r->json('data');

        $this->assertSame(['id' => $this->temporada->id, 'nombre' => 'OI 2026-2027'], $datos['temporada']);

        $esperadas = intdiv((int) Carbon::parse('2026-11-23')->diffInDays(Carbon::parse('2027-05-31')), 7) + 1;
        $this->assertCount($esperadas, $datos['semanas']);

        $s = $datos['semanas'][0];
        $this->assertSame('2026-11-23', $s['lunes']);
        $this->assertSame(48, $s['semana_iso']);
        $this->assertSame('Nov', $s['mes']);
        $this->assertEquals(70, $s['ha_total']);
        $this->assertEquals(21, $s['ha_por_variedad'][$this->variedades['Amarillo']->id]);
        $this->assertEquals(32.5, $s['ha_por_variedad'][$this->variedades['Bicolor']->id]);
        $this->assertEquals(16.5, $s['ha_por_variedad'][$this->variedades['Blanco']->id]);
        $this->assertSame(44225, $s['cajas_total']);
        $this->assertSame(36483, $s['necesidad_total']);
        $this->assertSame(7742, $s['disponible']);
        $this->assertSame(7742, $s['inventario_final']);
        $this->assertEquals(7.44, $s['cargas_eq']);
        $this->assertSame(10635, $s['disponible_por_grupo'][$this->grupo('Amarillo / Bicolor')->id]);
        $this->assertSame(-2893, $s['disponible_por_grupo'][$this->grupo('Blanco')->id]);
        $this->assertSame(array_sum($s['cajas_por_grupo']), $s['cajas_total']);
        $this->assertSame(array_sum($s['necesidad_por_bodega']), $s['necesidad_total']);
        $this->assertSame(array_sum($s['inventario_final_por_grupo']), $s['inventario_final']);

        $w49 = $datos['semanas'][1];
        $this->assertSame('2026-11-30', $w49['lunes']);
        $this->assertSame(49, $w49['semana_iso']);
        $this->assertEquals(0, $w49['ha_total']);
        $this->assertSame(0, $w49['cajas_total']);
        $this->assertSame(0, $w49['necesidad_total']);
        $this->assertSame(0, $w49['disponible']);
        $this->assertSame(7742, $w49['inventario_final']);
        $this->assertEquals(0, $w49['cargas_eq']);
        $this->assertSame(10635, $w49['inventario_final_por_grupo'][$this->grupo('Amarillo / Bicolor')->id]);
        $this->assertSame(-2893, $w49['inventario_final_por_grupo'][$this->grupo('Blanco')->id]);

        $this->assertSame(7742, $datos['semanas'][array_key_last($datos['semanas'])]['inventario_final']);
        $this->assertSame(['Amarillo', 'Bicolor', 'Blanco'], array_column($datos['variedades'], 'nombre'));
        $this->assertContains('Amarillo / Bicolor', array_column($datos['grupos'], 'nombre'));
        $this->assertContains('Nogales', array_column($datos['bodegas'], 'nombre'));
    }

    public function test_totales_suman_las_semanas_y_el_inventario_es_el_de_la_ultima(): void
    {
        $this->cargarWk48(ProgramaSemanal::create(['lunes' => '2026-11-23']));
        $this->comoPlaneacion();

        $t = $this->getJson($this->url())->assertOk()->json('data.totales');

        $this->assertEquals(70, $t['ha_total']);
        $this->assertSame(44225, $t['cajas_total']);
        $this->assertSame(36483, $t['necesidad_total']);
        $this->assertSame(7742, $t['disponible']);
        $this->assertSame(7742, $t['inventario_final']);
        $this->assertEquals(7.44, $t['cargas_eq']);
        $this->assertSame(-2893, $t['disponible_por_grupo'][$this->grupo('Blanco')->id]);
        $this->assertSame(-2893, $t['inventario_final_por_grupo'][$this->grupo('Blanco')->id]);
    }

    public function test_arrastra_el_inventario_entre_semanas_y_respeta_el_capturado(): void
    {
        $this->cargarWk48(ProgramaSemanal::create(['lunes' => '2026-11-23']));

        // WK50: produce 2 ha de Blanco el lunes (1,400 cj) y pide 500 cj de Blanco.
        $wk50 = ProgramaSemanal::create(['lunes' => '2026-12-07']);
        $this->corte($wk50, 'Blanco', 0, 2);
        $this->necesidad($wk50, 'Texas', 'Walmart', 'Bulk', ['Blanco'], 'RPC', [500, 0, 0, 0, 0, 0]);

        // WK52: el inventario de Ama/Bi se captura a mano en 1,000 (ya no arrastra).
        $wk52 = ProgramaSemanal::create(['lunes' => '2026-12-21']);
        ProgramaInventarioInicial::create([
            'programa_id' => $wk52->id,
            'grupo_clave' => (string) $this->grupo('Amarillo / Bicolor')->id,
            'cajas' => 1000,
        ]);
        $this->comoPlaneacion();

        $semanas = $this->getJson($this->url())->assertOk()->json('data.semanas');
        $bi = $this->grupo('Amarillo / Bicolor')->id;
        $blanco = $this->grupo('Blanco')->id;

        $s50 = $this->semanaDe($semanas, '2026-12-07');
        $this->assertSame(1400, $s50['cajas_total']);
        $this->assertSame(500, $s50['necesidad_total']);
        $this->assertSame(900, $s50['disponible']);
        $this->assertSame(900, $s50['disponible_por_grupo'][$blanco]);
        $this->assertSame(-2893 + 900, $s50['inventario_final_por_grupo'][$blanco]);
        $this->assertSame(10635, $s50['inventario_final_por_grupo'][$bi]);
        $this->assertSame(7742 + 900, $s50['inventario_final']);

        // WK51 sin programa: deja pasar el inventario de WK50.
        $s51 = $this->semanaDe($semanas, '2026-12-14');
        $this->assertSame(7742 + 900, $s51['inventario_final']);

        // WK52: el capturado manda sobre el arrastre.
        $s52 = $this->semanaDe($semanas, '2026-12-21');
        $this->assertSame(1000, $s52['inventario_final_por_grupo'][$bi]);
        $this->assertSame(-2893 + 900, $s52['inventario_final_por_grupo'][$blanco]);
        $this->assertSame(1000 - 2893 + 900, $s52['inventario_final']);
        $this->assertSame(1000 - 2893 + 900, $semanas[array_key_last($semanas)]['inventario_final']);
    }

    public function test_embarques_se_suman_por_bodega_en_cargas_y_las_necesidades_por_bodega(): void
    {
        $p = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $this->cargarWk48($p);
        foreach ([['Nogales', 'full', 0, 3], ['Nogales', 'sencillo', 2, 1], ['Texas', 'full', 1, 5]] as [$bodega, $tipo, $dia, $cargas]) {
            ProgramaEmbarque::create([
                'programa_id' => $p->id,
                'cultivo_id' => $this->cultivo->id,
                'bodega_id' => $this->bodega($bodega)->id,
                'tipo' => $tipo,
                'dia' => $dia,
                'cargas' => $cargas,
            ]);
        }
        $this->comoPlaneacion();

        $s = $this->getJson($this->url())->assertOk()->json('data.semanas.0');

        $this->assertSame(4, $s['cargas_por_bodega'][$this->bodega('Nogales')->id]);
        $this->assertSame(5, $s['cargas_por_bodega'][$this->bodega('Texas')->id]);
        $this->assertSame(29213, $s['necesidad_por_bodega'][$this->bodega('Nogales')->id]);
        $this->assertSame(7270, $s['necesidad_por_bodega'][$this->bodega('Texas')->id]);
    }

    public function test_otro_cultivo_no_ve_los_renglones_del_elote(): void
    {
        $this->cargarWk48(ProgramaSemanal::create(['lunes' => '2026-11-23']));
        $otro = Cultivo::create(['nombre' => 'Kabocha']);
        $this->comoPlaneacion();

        $datos = $this->getJson($this->url(['cultivo_id' => $otro->id]))->assertOk()->json('data');

        $this->assertSame([], $datos['variedades']);
        $this->assertSame(0, $datos['semanas'][0]['cajas_total']);
        $this->assertSame(0, $datos['semanas'][0]['necesidad_total']);
        $this->assertSame(0, $datos['semanas'][0]['inventario_final']);
    }

    public function test_el_numero_de_consultas_no_depende_de_las_semanas(): void
    {
        $this->comoPlaneacion();
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url())->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->cargarWk48(ProgramaSemanal::create(['lunes' => '2026-11-23']));
        $contar(); // calienta
        $conUno = $contar();

        foreach (['2026-11-30', '2026-12-07', '2026-12-14', '2026-12-21', '2026-12-28', '2027-01-04'] as $lunes) {
            $p = ProgramaSemanal::create(['lunes' => $lunes]);
            $this->corte($p, 'Blanco', 0, 1);
            $this->necesidad($p, 'Texas', 'Walmart', 'Bulk', ['Blanco'], 'RPC', [100, 0, 0, 0, 0, 0]);
            ProgramaEmbarque::create([
                'programa_id' => $p->id,
                'cultivo_id' => $this->cultivo->id,
                'bodega_id' => $this->bodega('Texas')->id,
                'tipo' => 'full',
                'dia' => 0,
                'cargas' => 1,
            ]);
        }

        $this->assertSame($conUno, $contar());
    }

    public function test_sin_permiso_es_403(): void
    {
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));

        $this->getJson($this->url())->assertForbidden();
    }

    public function test_temporada_obligatoria_y_existente(): void
    {
        $this->comoPlaneacion();

        $this->getJson(self::BASE)->assertStatus(422)->assertJsonValidationErrors('temporada_id');
        $this->getJson(self::BASE.'?temporada_id=999999')->assertStatus(422)->assertJsonValidationErrors('temporada_id');
        $this->getJson($this->url(['cultivo_id' => 999999]))->assertStatus(422)->assertJsonValidationErrors('cultivo_id');
    }
}
