<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\CierreCosecha;
use App\Models\Etapa;
use App\Models\Planeacion\ProgramaSemanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET planeacion/programa/avance-cosecha: el plan de corte de la semana
 * (hectáreas por etapa y día) contra los cierres de cosecha, con atraso
 * compensado, kilos de campo y lo que toca hoy. Hoy fijo: miércoles
 * 2026-11-25 de la semana del lunes 2026-11-23.
 */
class AvanceCosechaTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion/programa/avance-cosecha';

    private const LUNES = '2026-11-23';

    private Etapa $etapaA;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->crearElote();
        Sanctum::actingAs($this->usuarioCon(['avance-cosecha' => ['view']]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function avance(string $lunes = self::LUNES, array $filtros = []): TestResponse
    {
        return $this->getJson(self::URL.'?'.http_build_query(array_merge(['lunes' => $lunes], $filtros)));
    }

    private function datos(string $lunes = self::LUNES, array $filtros = []): array
    {
        return $this->avance($lunes, $filtros)->assertOk()->json('data');
    }

    private function programa(string $lunes = self::LUNES): ProgramaSemanal
    {
        return ProgramaSemanal::create(['lunes' => $lunes]);
    }

    /**
     * Escenario común: etapa A (Amarillo, 10 ha) con plan lunes 3 + 2, martes 4,
     * miércoles 3; cierres de A lunes 2 (abierto) y 1.5, martes 5 y uno de 9 borrado;
     * etapa B sin plan con cierre el martes de 2; cierre sin etapa el miércoles de
     * 1.25; corte sin etapa Blanco el jueves de 4; salidas de A el lunes de 12,000 kg
     * y de 5,000 kg eliminada; cierres de fuera de la semana.
     */
    private function escenario(): ProgramaSemanal
    {
        $p = $this->programa();
        $a = $this->etapaA = $this->crearEtapa('Amarillo', 10);
        $b = $this->crearEtapa('Bicolor', 8);

        $this->corte($p, 'Amarillo', 0, 3, $a);
        $this->corte($p, 'Amarillo', 0, 2, $a);
        $this->corte($p, 'Amarillo', 1, 4, $a);
        $this->corte($p, 'Amarillo', 2, 3, $a);
        $this->corte($p, 'Blanco', 3, 4);

        $this->cierre($a, '2026-11-23', 2, ['status' => 'abierto']);
        $this->cierre($a, '2026-11-23', 1.5);
        $this->cierre($a, '2026-11-24', 5);
        $this->cierre($a, '2026-11-24', 9)->delete();
        $this->cierre($b, '2026-11-24', 2);
        $this->cierre(null, '2026-11-25', 1.25);
        $this->cierre($a, '2026-11-22', 7);
        $this->cierre($a, '2026-11-30', 6);

        $this->salidaCampo($a, '2026-11-23', 12000);
        $this->salidaCampo($a, '2026-11-23', 5000, ['eliminado' => true]);

        return $p;
    }

    private function fila(array $filas, Etapa $etapa): ?array
    {
        foreach ($filas as $f) {
            if ($f['etapa_id'] === $etapa->id) {
                return $f;
            }
        }

        return null;
    }

    public function test_cruza_plan_y_cierres_por_etapa_y_dia(): void
    {
        $this->escenario();

        $d = $this->datos();

        $this->assertSame(self::LUNES, $d['lunes']);
        $this->assertSame('2026-11-25', $d['hoy']);
        $this->assertSame(2, $d['dias_pasados']);
        $this->assertTrue($d['trabaja_domingo']);

        $this->assertCount(1, $d['renglones']);
        $fila = $this->fila($d['renglones'], $this->etapaA);
        $this->assertEquals([5.0, 4.0, 3.0, 0.0, 0.0, 0.0, 0.0], $fila['plan']);
        $this->assertEquals([3.5, 5.0, 0.0, 0.0, 0.0, 0.0, 0.0], $fila['real']);
        $this->assertEquals(0.5, $fila['atraso']);
        $this->assertEquals(3.5, $fila['para_hoy']);
        $this->assertEquals(12000.0, $fila['kilos'][0]);
        $this->assertSame($this->etapaA->codigo, $fila['codigo']);
        $this->assertSame($this->etapaA->nombre, $fila['etapa']);
        $this->assertSame($this->lote->nombre, $fila['lote']);
        $this->assertSame($this->variedades['Amarillo']->id, $fila['variedad_id']);
        $this->assertSame('Amarillo', $fila['variedad']);
    }

    public function test_real_sin_plan_va_a_sin_plan(): void
    {
        $this->escenario();
        $b = Etapa::where('nombre', 'Etapa 2')->firstOrFail();

        $d = $this->datos();

        $this->assertNull($this->fila($d['renglones'], $b));
        $fila = $this->fila($d['sin_plan'], $b);
        $this->assertEquals(2.0, $fila['real'][1]);
        $this->assertEquals([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $fila['plan']);
        $this->assertEquals(0.0, $fila['atraso']);
        $this->assertNull($fila['para_hoy']);
        $this->assertSame('Bicolor', $fila['variedad']);
    }

    public function test_cierre_sin_etapa_y_kilos_sin_fila_no_se_pierden(): void
    {
        $this->escenario();
        $c = $this->crearEtapa('Blanco', 5);
        $this->salidaCampo(null, '2026-11-24', 800);
        $this->salidaCampo($c, '2026-11-26', 300);

        $d = $this->datos();

        $this->assertEquals(1.25, $d['sin_etapa']['real'][2]);
        $this->assertEquals(800.0, $d['sin_etapa']['kilos'][1]);
        $fila = $this->fila($d['sin_plan'], $c);
        $this->assertEquals([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $fila['real']);
        $this->assertEquals(300.0, $fila['kilos'][3]);
        $this->assertEquals(11.75, $d['totales']['real']);
        $this->assertEquals(16.0, $d['totales']['plan']);
        $this->assertEquals(13100.0, $d['totales']['kilos']);
        $this->assertEquals(0.5, $d['totales']['atraso']);
        $this->assertEquals(3.5, $d['totales']['para_hoy']);
    }

    public function test_plan_sin_etapa_se_agrupa_por_variedad(): void
    {
        $p = $this->escenario();
        $this->corte($p, 'Blanco', 4, 1.5);

        $d = $this->datos();

        $this->assertCount(1, $d['sin_etapa']['plan']);
        $grupo = $d['sin_etapa']['plan'][0];
        $this->assertSame($this->variedades['Blanco']->id, $grupo['variedad_id']);
        $this->assertSame('Blanco', $grupo['variedad']);
        $this->assertEquals([0.0, 0.0, 0.0, 4.0, 1.5, 0.0, 0.0], $grupo['plan']);
    }

    public function test_dia_futuro_no_es_atraso(): void
    {
        $p = $this->escenario();
        $this->corte($p, 'Amarillo', 4, 6, $this->etapaA);

        $d = $this->datos();
        $this->assertEquals(0.5, $this->fila($d['renglones'], $this->etapaA)['atraso']);

        // Semana futura: nada es atraso ni hay «para hoy».
        $futura = $this->programa('2026-11-30');
        $this->corte($futura, 'Amarillo', 0, 3, $this->etapaA);

        $d = $this->datos('2026-11-30');
        $fila = $this->fila($d['renglones'], $this->etapaA);
        $this->assertEquals(0.0, $fila['atraso']);
        $this->assertNull($fila['para_hoy']);
        $this->assertSame(0, $d['dias_pasados']);
        $this->assertNull($d['totales']['para_hoy']);
    }

    public function test_semana_pasada_atrasa_toda_la_semana(): void
    {
        $p = $this->programa('2026-11-16');
        $a = $this->crearEtapa('Amarillo', 10);
        $this->corte($p, 'Amarillo', 0, 2, $a);

        $d = $this->datos('2026-11-16');

        $this->assertSame(7, $d['dias_pasados']);
        $fila = $this->fila($d['renglones'], $a);
        $this->assertEquals(2.0, $fila['atraso']);
        $this->assertNull($fila['para_hoy']);
    }

    public function test_semana_sin_programa_no_se_crea(): void
    {
        $a = $this->crearEtapa('Amarillo', 10);
        $this->cierre($a, '2026-11-24', 3);

        $d = $this->datos();

        $this->assertSame([], $d['renglones']);
        $this->assertEquals(3.0, $this->fila($d['sin_plan'], $a)['real'][1]);
        $this->assertTrue($d['trabaja_domingo']);
        $this->assertSame(0, ProgramaSemanal::count());
    }

    public function test_filtra_por_cultivo_y_temporada(): void
    {
        $this->escenario();
        $otra = $this->otroCultivo();
        CierreCosecha::create([
            'temporada_id' => $otra->id,
            'fecha_inicio' => '2026-11-24',
            'fecha_cierre' => '2026-11-24',
            'superficie_cosechada' => 4,
            'status' => 'cerrado',
        ]);

        $this->assertEquals(15.75, $this->datos()['totales']['real']);
        $this->assertEquals(11.75, $this->datos(self::LUNES, ['cultivo_id' => $this->cultivo->id])['totales']['real']);
        $this->assertEquals(11.75, $this->datos(self::LUNES, ['temporada_id' => $this->temporada->id])['totales']['real']);
        $this->assertEquals(4.0, $this->datos(self::LUNES, ['temporada_id' => $otra->id])['totales']['real']);
        // El plan de corte también se filtra por la temporada del renglón.
        $this->assertEquals(0.0, $this->datos(self::LUNES, ['temporada_id' => $otra->id])['totales']['plan']);

        $this->avance(self::LUNES, ['temporada_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('temporada_id');
        $this->avance(self::LUNES, ['cultivo_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('cultivo_id');
    }

    public function test_real_del_domingo_cuenta_sin_domingo(): void
    {
        $p = $this->escenario();
        $p->update(['domingo_corte' => false]);
        $this->cierre($this->etapaA, '2026-11-29', 2.5);

        $d = $this->datos();
        $this->assertFalse($d['trabaja_domingo']);
        $fila = $this->fila($d['renglones'], $this->etapaA);
        $this->assertEquals(2.5, $fila['real'][6]);
        $this->assertEquals(14.25, $d['totales']['real']);

        // Solo la bandera de embarques no cambia la de cosecha.
        $p->update(['domingo_corte' => true, 'domingo_embarques' => false]);
        $this->assertTrue($this->datos()['trabaja_domingo']);
    }

    public function test_sin_permiso_403_y_valida_lunes(): void
    {
        Sanctum::actingAs($this->usuarioCon(['avance-empaque' => ['view']]));
        $this->avance()->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para esta acción de Planeación.');

        Sanctum::actingAs($this->usuarioCon(['avance-cosecha' => ['view']]));
        $this->avance('2026-11-24')->assertStatus(422)->assertJsonValidationErrors('lunes');
        $this->avance('2026-11-24')->assertJsonPath('errors.lunes.0', 'La semana se identifica por su lunes (AAAA-MM-DD).');
        $this->getJson(self::URL)->assertStatus(422)->assertJsonValidationErrors('lunes');
        $this->avance()->assertOk()->assertJsonPath('success', true);
    }

    public function test_sin_n_mas_1(): void
    {
        $p = $this->programa();
        $a = $this->crearEtapa('Amarillo', 10);
        $this->corte($p, 'Amarillo', 0, 2, $a);
        $this->cierre($a, '2026-11-23', 1);

        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->datos();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->datos(); // calienta lo que se guarda entre peticiones (empresa, permisos)
        $pocas = $contar();

        for ($i = 0; $i < 30; $i++) {
            $etapa = $this->crearEtapa($i % 2 ? 'Bicolor' : 'Blanco', 5);
            $this->corte($p, 'Amarillo', $i % 7, 1, $etapa);
            $this->cierre($etapa, '2026-11-2'.(3 + $i % 7), 1);
            $this->cierre($etapa, '2026-11-2'.(3 + ($i + 1) % 7), 0.5);
            $this->salidaCampo($etapa, '2026-11-24', 100);
        }

        $this->assertSame($pocas, $contar());
    }
}
