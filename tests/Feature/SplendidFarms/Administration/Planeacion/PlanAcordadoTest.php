<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Etapa;
use App\Models\Planeacion\ProgramaCorte;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\User;
use App\Services\Planeacion\PlantasAvance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Plan acordado (fase 3): la foto del plan de corte y de embarques que se
 * guarda en la junta, y las marcas de lo que cambió después en Avance de
 * cosecha y Avance de embarques. Hoy fijo: miércoles 2026-11-25 de la semana
 * del lunes 2026-11-23.
 */
class PlanAcordadoTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/administration/planeacion/programa';

    private const LUNES = '2026-11-23';

    private User $usuario;

    private Etapa $etapaA;

    private Etapa $etapaB;

    private ProgramaSemanal $programa;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->crearElote();
        (new PlantasAvance)->guardar([$this->plantaEmpaqueId()]);
        $this->usuario = $this->usuarioCon([
            'tablero-semanal' => ['view', 'acordar'],
            'avance-cosecha' => ['view'],
            'avance-embarques' => ['view'],
        ]);
        Sanctum::actingAs($this->usuario);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Escenario de las tareas 2 y 4: etapa A (Amarillo) con plan lunes 3 + 2,
     * martes 4, miércoles 3; etapa B con real sin plan; corte sin etapa Blanco el
     * jueves de 4; Texas con full lunes 2, sencillo lunes 1, full martes 2 y full
     * miércoles 1, y los embarques reales.
     */
    private function escenario(): ProgramaSemanal
    {
        $p = $this->programa = ProgramaSemanal::create(['lunes' => self::LUNES]);
        $a = $this->etapaA = $this->crearEtapa('Amarillo', 10);
        $b = $this->etapaB = $this->crearEtapa('Bicolor', 8);

        $this->corte($p, 'Amarillo', 0, 3, $a);
        $this->corte($p, 'Amarillo', 0, 2, $a);
        $this->corte($p, 'Amarillo', 1, 4, $a);
        $this->corte($p, 'Amarillo', 2, 3, $a);
        $this->corte($p, 'Blanco', 3, 4);

        $this->cierre($a, '2026-11-23', 2, ['status' => 'abierto']);
        $this->cierre($a, '2026-11-23', 1.5);
        $this->cierre($a, '2026-11-24', 5);
        $this->cierre($b, '2026-11-24', 2);
        $this->cierre(null, '2026-11-25', 1.25);

        $this->cargaPlan($p, 'Texas', 'full', 0, 2);
        $this->cargaPlan($p, 'Texas', 'sencillo', 0, 1);
        $this->cargaPlan($p, 'Texas', 'full', 1, 2);
        $this->cargaPlan($p, 'Texas', 'full', 2, 1);

        $this->embarqueReal('2026-11-23', 'entregado', 'Texas');
        $this->embarqueReal('2026-11-23', 'en_transito', 'Texas');
        $this->embarqueReal('2026-11-24', 'entregado', 'Nogales');

        return $p;
    }

    private function acordar(?ProgramaSemanal $p = null): TestResponse
    {
        return $this->postJson(self::BASE.'/'.($p ?? $this->programa)->id.'/plan-acordado');
    }

    private function cosecha(array $filtros = []): array
    {
        return $this->getJson(self::BASE.'/avance-cosecha?'.http_build_query(array_merge(['lunes' => self::LUNES], $filtros)))
            ->assertOk()->json('data');
    }

    private function embarques(array $filtros = []): array
    {
        return $this->getJson(self::BASE.'/avance-embarques?'.http_build_query(array_merge(['lunes' => self::LUNES], $filtros)))
            ->assertOk()->json('data');
    }

    private function filaEtapa(array $filas, Etapa $etapa): ?array
    {
        foreach ($filas as $f) {
            if ($f['etapa_id'] === $etapa->id) {
                return $f;
            }
        }

        return null;
    }

    private function filaBodega(array $filas, string $nombre): ?array
    {
        foreach ($filas as $f) {
            if ($f['nombre'] === $nombre) {
                return $f;
            }
        }

        return null;
    }

    /** Lleva el corte de A del lunes de 5 a `$total` ha (el renglón de 3 pasa a `$total − 2`). */
    private function corteLunesDeA(float $total): void
    {
        ProgramaCorte::where('programa_id', $this->programa->id)->where('etapa_id', $this->etapaA->id)
            ->where('dia', 0)->where('hectareas', '!=', 2)->firstOrFail()
            ->update(['hectareas' => $total - 2]);
    }

    private function fullLunesTexas(int $cargas): void
    {
        ProgramaEmbarque::where('programa_id', $this->programa->id)->where('bodega_id', $this->bodega('Texas')->id)
            ->where('tipo', 'full')->where('dia', 0)->firstOrFail()
            ->update(['cargas' => $cargas]);
    }

    private const NULOS = [null, null, null, null, null, null, null];

    /**
     * Como assertSame, pero un flotante esperado acepta el entero que deja el
     * JSON (8.0 llega como 8) y nunca un null: un 0.0 no pasa por null.
     */
    private function assertIgual(mixed $esperado, mixed $real): void
    {
        if (is_float($esperado)) {
            $this->assertNotNull($real);
            $this->assertIsNumeric($real);
            $this->assertEquals($esperado, $real);

            return;
        }
        if (is_array($esperado)) {
            $this->assertIsArray($real);
            $this->assertSame(array_keys($esperado), array_keys($real));
            foreach ($esperado as $llave => $valor) {
                $this->assertIgual($valor, $real[$llave]);
            }

            return;
        }
        $this->assertSame($esperado, $real);
    }

    public function test_acordar_guarda_la_foto_y_emite_una_vez(): void
    {
        $this->escenario();
        Event::fake([ProgramaSemanalUpdated::class]);

        $r = $this->acordar()->assertStatus(201);
        $r->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Plan acordado.')
            ->assertJsonPath('data.cortes', 5)
            ->assertJsonPath('data.embarques', 4)
            ->assertJsonPath('data.por.id', $this->usuario->id)
            ->assertJsonPath('data.por.nombre', $this->usuario->name);
        $this->assertStringStartsWith('2026-11-25', $r->json('data.en'));
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);

        $foto = $this->getJson(self::BASE.'/'.$this->programa->id.'/plan-acordado')->assertOk()->json('data');
        $this->assertSame($r->json('data.id'), $foto['id']);
        $this->assertSame($this->usuario->name, $foto['por']['nombre']);
        $this->assertStringStartsWith('2026-11-25', $foto['en']);
        $this->assertCount(5, $foto['cortes']);
        $this->assertCount(4, $foto['embarques']);
        $sinEtapa = array_values(array_filter($foto['cortes'], fn ($c) => $c['etapa_id'] === null));
        $this->assertIgual([[
            'temporada_id' => $this->temporada->id,
            'etapa_id' => null,
            'variedad_id' => $this->variedades['Blanco']->id,
            'dia' => 3,
            'hectareas' => 4.0,
        ]], $sinEtapa);
        $this->assertContains([
            'cultivo_id' => $this->cultivo->id,
            'bodega_id' => $this->bodega('Texas')->id,
            'tipo' => 'sencillo',
            'dia' => 0,
            'cargas' => 1,
        ], $foto['embarques']);
    }

    public function test_sin_foto_no_hay_marcas(): void
    {
        $this->escenario();

        $this->getJson(self::BASE.'/'.$this->programa->id.'/plan-acordado')->assertOk()->assertJsonPath('data', null);

        $c = $this->cosecha();
        $this->assertSame(['existe' => false, 'por' => null, 'en' => null, 'cambios' => []], $c['acordado']);
        foreach (array_merge($c['renglones'], $c['sin_plan'], $c['sin_etapa']['plan']) as $fila) {
            $this->assertSame(self::NULOS, $fila['acordado']);
        }
        $this->assertCount(1, $c['renglones']);

        $e = $this->embarques();
        $this->assertSame(['existe' => false, 'por' => null, 'en' => null, 'cambios' => []], $e['acordado']);
        foreach (array_merge($e['renglones'], $e['sin_programa']) as $fila) {
            $this->assertSame(self::NULOS, $fila['acordado']);
        }
        $this->assertCount(1, $e['renglones']);
        $this->assertCount(1, $e['sin_programa']);
    }

    public function test_cambio_despues_de_acordar_se_marca(): void
    {
        $this->escenario();
        $this->acordar()->assertStatus(201);
        $this->corteLunesDeA(8);
        $this->fullLunesTexas(3);

        $c = $this->cosecha();
        $this->assertTrue($c['acordado']['existe']);
        $this->assertSame(['id' => $this->usuario->id, 'nombre' => $this->usuario->name], $c['acordado']['por']);
        $a = $this->filaEtapa($c['renglones'], $this->etapaA);
        $this->assertIgual(8.0, $a['plan'][0]);
        $this->assertIgual([5.0, null, null, null, null, null, null], $a['acordado']);
        $this->assertIgual(self::NULOS, $c['sin_etapa']['plan'][0]['acordado']);
        $this->assertIgual([[
            'dia' => 0,
            'etapa_id' => $this->etapaA->id,
            'variedad_id' => $this->variedades['Amarillo']->id,
            'nombre' => 'El Carrizo · Etapa 1',
            'antes' => 5.0,
            'ahora' => 8.0,
        ]], $c['acordado']['cambios']);

        $e = $this->embarques();
        $texas = $this->filaBodega($e['renglones'], 'Texas');
        $this->assertIgual(['full' => 2, 'sencillo' => 1], $texas['acordado'][0]);
        $this->assertIgual([null, null, null, null, null, null], array_slice($texas['acordado'], 1));
        $this->assertIgual([[
            'dia' => 0,
            'bodega_id' => $this->bodega('Texas')->id,
            'tipo' => 'full',
            'nombre' => 'Texas',
            'antes' => 2,
            'ahora' => 3,
        ]], $e['acordado']['cambios']);
    }

    public function test_valor_que_vuelve_al_acordado_deja_de_marcarse(): void
    {
        $this->escenario();
        $this->acordar()->assertStatus(201);
        $this->corteLunesDeA(8);
        $this->fullLunesTexas(3);
        $this->corteLunesDeA(5);
        $this->fullLunesTexas(2);

        $c = $this->cosecha();
        $this->assertIgual(self::NULOS, $this->filaEtapa($c['renglones'], $this->etapaA)['acordado']);
        $this->assertIgual([], $c['acordado']['cambios']);
        $this->assertTrue($c['acordado']['existe']);

        $e = $this->embarques();
        $this->assertIgual(self::NULOS, $this->filaBodega($e['renglones'], 'Texas')['acordado']);
        $this->assertIgual([], $e['acordado']['cambios']);
    }

    public function test_renglon_quitado_se_marca_con_cero(): void
    {
        $this->escenario();
        $this->acordar()->assertStatus(201);
        ProgramaCorte::where('etapa_id', $this->etapaA->id)->delete();
        ProgramaEmbarque::where('bodega_id', $this->bodega('Texas')->id)->delete();

        $c = $this->cosecha();
        $a = $this->filaEtapa($c['renglones'], $this->etapaA);
        $this->assertNotNull($a);
        $this->assertNull($this->filaEtapa($c['sin_plan'], $this->etapaA));
        $this->assertIgual([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $a['plan']);
        $this->assertIgual([5.0, 4.0, 3.0, null, null, null, null], $a['acordado']);
        $this->assertIgual(0.0, $a['atraso']);
        $this->assertCount(3, $c['acordado']['cambios']);
        $this->assertIgual([0, 1, 2], array_column($c['acordado']['cambios'], 'dia'));
        $this->assertIgual([5.0, 4.0, 3.0], array_column($c['acordado']['cambios'], 'antes'));
        $this->assertIgual([0.0, 0.0, 0.0], array_column($c['acordado']['cambios'], 'ahora'));

        $e = $this->embarques();
        $texas = $this->filaBodega($e['renglones'], 'Texas');
        $this->assertNotNull($texas);
        $this->assertNull($this->filaBodega($e['sin_programa'], 'Texas'));
        $this->assertIgual([0, 0, 0, 0, 0, 0, 0], $texas['plan']);
        $this->assertIgual(['full' => 2, 'sencillo' => 1], $texas['acordado'][0]);
        $this->assertIgual(['full' => 2, 'sencillo' => 0], $texas['acordado'][1]);
        $this->assertIgual(['full' => 1, 'sencillo' => 0], $texas['acordado'][2]);
        $this->assertIgual(0, $texas['atraso']);
        // Full lunes, sencillo lunes, full martes y full miércoles.
        $this->assertCount(4, $e['acordado']['cambios']);
        $this->assertIgual(['full', 'sencillo'], array_column(array_slice($e['acordado']['cambios'], 0, 2), 'tipo'));
    }

    public function test_renglon_agregado_despues_se_marca(): void
    {
        $this->escenario();
        $this->acordar()->assertStatus(201);
        $this->assertNotNull($this->filaEtapa($this->cosecha()['sin_plan'], $this->etapaB));

        $this->corte($this->programa, 'Bicolor', 3, 3, $this->etapaB);

        $c = $this->cosecha();
        $this->assertNull($this->filaEtapa($c['sin_plan'], $this->etapaB));
        $b = $this->filaEtapa($c['renglones'], $this->etapaB);
        $this->assertIgual([null, null, null, 0.0, null, null, null], $b['acordado']);
        $this->assertIgual([[
            'dia' => 3,
            'etapa_id' => $this->etapaB->id,
            'variedad_id' => $this->variedades['Bicolor']->id,
            'nombre' => 'El Carrizo · Etapa 2',
            'antes' => 0.0,
            'ahora' => 3.0,
        ]], $c['acordado']['cambios']);
    }

    public function test_vale_la_foto_mas_reciente(): void
    {
        $this->escenario();
        $primera = $this->acordar()->assertStatus(201)->json('data.id');
        $this->corteLunesDeA(8);
        $this->fullLunesTexas(3);
        $segunda = $this->acordar()->assertStatus(201)->json('data.id');
        $this->assertGreaterThan($primera, $segunda);

        $c = $this->cosecha();
        $this->assertSame([], $c['acordado']['cambios']);
        $this->assertSame(self::NULOS, $this->filaEtapa($c['renglones'], $this->etapaA)['acordado']);
        $this->assertSame([], $this->embarques()['acordado']['cambios']);

        $foto = $this->getJson(self::BASE.'/'.$this->programa->id.'/plan-acordado')->assertOk()->json('data');
        $this->assertSame($segunda, $foto['id']);
        $this->assertSame(2, DB::table('programa_planes_acordados')->count());
    }

    public function test_cambio_de_otro_cultivo_no_se_lista_con_filtro(): void
    {
        $this->escenario();
        $otra = $this->otroCultivo();
        $corteOtro = ProgramaCorte::create([
            'programa_id' => $this->programa->id,
            'temporada_id' => $otra->id,
            'variedad_id' => $this->variedades['Amarillo']->id,
            'dia' => 4,
            'hectareas' => 2,
        ]);
        $cargaOtra = ProgramaEmbarque::create([
            'programa_id' => $this->programa->id,
            'cultivo_id' => $otra->cultivo_id,
            'bodega_id' => $this->bodega('Texas')->id,
            'tipo' => 'full',
            'dia' => 4,
            'cargas' => 5,
        ]);
        $this->acordar()->assertStatus(201);
        $corteOtro->update(['hectareas' => 6]);
        $cargaOtra->update(['cargas' => 1]);

        // Sin filtro el cambio sí aparece.
        $this->assertCount(1, $this->cosecha()['acordado']['cambios']);
        $this->assertCount(1, $this->embarques()['acordado']['cambios']);

        $c = $this->cosecha(['cultivo_id' => $this->cultivo->id]);
        $this->assertSame([], $c['acordado']['cambios']);
        foreach ($c['sin_etapa']['plan'] as $fila) {
            $this->assertSame(self::NULOS, $fila['acordado']);
        }
        $this->assertSame([], $this->cosecha(['temporada_id' => $this->temporada->id])['acordado']['cambios']);
        $this->assertCount(1, $this->cosecha(['temporada_id' => $otra->id])['acordado']['cambios']);

        $e = $this->embarques(['cultivo_id' => $this->cultivo->id]);
        $this->assertSame([], $e['acordado']['cambios']);
        $this->assertSame(self::NULOS, $this->filaBodega($e['renglones'], 'Texas')['acordado']);
    }

    public function test_sin_permiso_acordar_403_y_semana_vacia_422(): void
    {
        $this->escenario();
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));
        $this->acordar()->assertStatus(403);
        $this->assertSame(0, DB::table('programa_planes_acordados')->count());

        Sanctum::actingAs($this->usuario);
        $vacio = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->acordar($vacio)->assertStatus(422)
            ->assertJsonValidationErrors('plan')
            ->assertJsonPath('errors.plan.0', 'La semana no tiene plan de corte ni de embarques que acordar.');
        $this->assertSame(0, DB::table('programa_planes_acordados')->count());

        // Leer la foto pide alguno de los tres permisos de lectura.
        Sanctum::actingAs($this->usuarioCon(['plan-corte' => ['view']]));
        $this->getJson(self::BASE.'/'.$this->programa->id.'/plan-acordado')->assertStatus(403);
        Sanctum::actingAs($this->usuarioCon(['avance-embarques' => ['view']]));
        $this->getJson(self::BASE.'/'.$this->programa->id.'/plan-acordado')->assertOk();
    }

    public function test_get_programa_trae_plan_acordado(): void
    {
        $this->escenario();

        $this->getJson(self::BASE.'?lunes='.self::LUNES)->assertOk()
            ->assertJsonPath('data.programa.plan_acordado', null);

        $this->acordar()->assertStatus(201);

        $plan = $this->getJson(self::BASE.'?lunes='.self::LUNES)->assertOk()->json('data.programa.plan_acordado');
        $this->assertSame(['id' => $this->usuario->id, 'nombre' => $this->usuario->name], $plan['por']);
        $this->assertStringStartsWith('2026-11-25', $plan['en']);
    }

    public function test_avance_con_foto_sin_n_mas_1(): void
    {
        $this->escenario();
        $this->acordar()->assertStatus(201);

        $contar = function (callable $leer): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $leer();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->cosecha();
        $this->embarques();
        $cosecha = $contar(fn () => $this->cosecha());
        $embarques = $contar(fn () => $this->embarques());

        for ($i = 0; $i < 20; $i++) {
            $etapa = $this->crearEtapa('Bicolor', 5);
            $this->corte($this->programa, 'Bicolor', $i % 7, 1, $etapa);
        }
        $this->cargaPlan($this->programa, 'Nogales', 'full', 3, 2);
        $this->acordar()->assertStatus(201);
        ProgramaCorte::where('programa_id', $this->programa->id)->where('variedad_id', $this->variedades['Bicolor']->id)->delete();

        $this->assertSame($cosecha, $contar(fn () => $this->cosecha()));
        $this->assertSame($embarques, $contar(fn () => $this->embarques()));
    }
}
