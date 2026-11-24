<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\Cultivo;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\ProduccionEmpaque;
use App\Models\Variedad;
use App\Services\Planeacion\ProgramaSemanalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET planeacion/programa/avance: el programa de empaque de la semana (por día
 * de empaque, sumando bodegas) contra el real empacado, con atraso acumulado
 * con compensación y lo que toca «para hoy». Hoy fijo: miércoles 2026-11-25
 * de la semana del lunes 2026-11-23.
 */
class AvanceEmpaqueTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion/programa/avance';

    private const LUNES = '2026-11-23';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->crearElote();
        Sanctum::actingAs($this->usuarioCon(['avance-empaque' => ['view']]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function avance(string $lunes = self::LUNES, ?int $cultivoId = null): TestResponse
    {
        $url = self::URL.'?lunes='.$lunes.($cultivoId !== null ? '&cultivo_id='.$cultivoId : '');

        return $this->getJson($url);
    }

    private function datos(string $lunes = self::LUNES, ?int $cultivoId = null): array
    {
        return $this->avance($lunes, $cultivoId)->assertOk()->json('data');
    }

    private function programa(string $lunes = self::LUNES): ProgramaSemanal
    {
        return ProgramaSemanal::create(['lunes' => $lunes]);
    }

    /** Receta clasificada como presentación × empaque (para cualquier variedad). */
    private function receta(string $presentacion, string $empaque)
    {
        $receta = $this->recetaEmpaque("{$presentacion} {$empaque}");
        $this->combinacion($receta, $presentacion, $empaque);

        return $receta;
    }

    private function fila(array $filas, string $presentacion, int|string $grupoId, string $empaque): ?array
    {
        foreach ($filas as $f) {
            if ($f['presentacion_id'] === $this->presentacion($presentacion)->id
                && $f['grupo_id'] === $grupoId
                && $f['tipo_empaque_id'] === $this->tipoEmpaque($empaque)->id) {
                return $f;
            }
        }

        return null;
    }

    private function ab(): int
    {
        return $this->grupo('Amarillo / Bicolor')->id;
    }

    private function blanco(): int
    {
        return $this->grupo('Blanco')->id;
    }

    public function test_programado_suma_las_bodegas_y_se_cruza_con_el_real(): void
    {
        $p = $this->programa();
        $this->necesidad($p, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [100, 0, 0, 0, 0, 0, 0]);
        $this->necesidad($p, 'Nogales', 'Vesta', 'Bulk', ['Bicolor'], 'RPC', [50, 20, 0, 0, 0, 0, 0]);
        $this->pallet('2026-11-23', $this->receta('Bulk', 'RPC'), 100, 'Amarillo');

        $d = $this->datos();

        $this->assertSame(self::LUNES, $d['lunes']);
        $this->assertSame('2026-11-25', $d['hoy']);
        $this->assertSame(2, $d['dias_pasados']);
        $this->assertCount(1, $d['renglones']);
        $fila = $d['renglones'][0];
        $this->assertSame([
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'grupo_id' => $this->ab(),
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
        ], array_intersect_key($fila, array_flip(['presentacion_id', 'grupo_id', 'tipo_empaque_id'])));
        $this->assertSame([150, 20, 0, 0, 0, 0, 0], $fila['programado']);
        $this->assertSame([100, 0, 0, 0, 0, 0, 0], $fila['real']);
        $this->assertSame(70, $fila['atraso']);
        $this->assertSame(70, $fila['para_hoy']);
        $this->assertSame([], $d['sin_programa']);
    }

    public function test_el_programado_viene_por_dia_de_empaque(): void
    {
        $this->antelacion('Texas', 2);
        $p = $this->programa();
        $this->necesidad($p, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [0, 0, 80, 0, 0, 0, 0]);

        $d = $this->datos();

        $this->assertSame([80, 0, 0, 0, 0, 0, 0], $this->fila($d['renglones'], 'Bulk', $this->ab(), 'RPC')['programado']);
    }

    public function test_atraso_con_compensacion(): void
    {
        $p = $this->programa();
        $this->necesidad($p, 'Nogales', 'Vesta', 'Bulk', ['Amarillo'], 'RPC', [100, 100, 100, 0, 0, 0, 0]);
        $this->necesidad($p, 'Nogales', 'Vesta', 'Tray', ['Amarillo'], 'RPC', [100, 100, 100, 0, 0, 0, 0]);
        // Bulk: el martes se empacó de más y cubre el faltante del lunes.
        $this->pallet('2026-11-24', $this->receta('Bulk', 'RPC'), 250, 'Amarillo');
        // Tray: el martes se empacaron 150 de 200 acumulados.
        $this->pallet('2026-11-24', $this->receta('Tray', 'RPC'), 150, 'Amarillo');

        $d = $this->datos();

        $bulk = $this->fila($d['renglones'], 'Bulk', $this->ab(), 'RPC');
        $this->assertSame(0, $bulk['atraso']);
        $this->assertSame(100, $bulk['para_hoy']);

        $tray = $this->fila($d['renglones'], 'Tray', $this->ab(), 'RPC');
        $this->assertSame(50, $tray['atraso']);
        $this->assertSame(150, $tray['para_hoy']);

        $this->assertSame(50, $d['totales']['atraso']);
        $this->assertSame(250, $d['totales']['para_hoy']);
    }

    public function test_la_compensacion_no_paga_por_adelantado(): void
    {
        // Empacar de más el lunes no deja «saldo a favor» para el martes.
        $p = $this->programa();
        $this->necesidad($p, 'Nogales', 'Vesta', 'Bulk', ['Amarillo'], 'RPC', [0, 100, 50, 0, 0, 0, 0]);
        $this->pallet('2026-11-23', $this->receta('Bulk', 'RPC'), 100, 'Amarillo');

        $fila = $this->fila($this->datos()['renglones'], 'Bulk', $this->ab(), 'RPC');

        $this->assertSame(100, $fila['atraso']);
        $this->assertSame(150, $fila['para_hoy']);
    }

    public function test_semana_futura_no_tiene_atraso_ni_para_hoy(): void
    {
        $p = $this->programa('2026-11-30');
        $this->necesidad($p, 'Nogales', 'Vesta', 'Bulk', ['Amarillo'], 'RPC', [100, 100, 0, 0, 0, 0, 0]);

        $d = $this->datos('2026-11-30');

        $this->assertSame(0, $d['dias_pasados']);
        $fila = $this->fila($d['renglones'], 'Bulk', $this->ab(), 'RPC');
        $this->assertSame(0, $fila['atraso']);
        $this->assertNull($fila['para_hoy']);
        $this->assertSame(0, $d['totales']['atraso']);
        $this->assertNull($d['totales']['para_hoy']);
    }

    public function test_semana_pasada_el_atraso_es_el_de_toda_la_semana(): void
    {
        $p = $this->programa('2026-11-16');
        $this->necesidad($p, 'Nogales', 'Vesta', 'Bulk', ['Amarillo'], 'RPC', [100, 100, 0, 0, 0, 0, 50]);
        $this->pallet('2026-11-17', $this->receta('Bulk', 'RPC'), 50, 'Amarillo');

        $d = $this->datos('2026-11-16');

        $this->assertSame(7, $d['dias_pasados']);
        $fila = $this->fila($d['renglones'], 'Bulk', $this->ab(), 'RPC');
        $this->assertSame([0, 50, 0, 0, 0, 0, 0], $fila['real']);
        $this->assertSame(200, $fila['atraso']);
        $this->assertNull($fila['para_hoy']);
        $this->assertSame(200, $d['totales']['atraso']);
        $this->assertNull($d['totales']['para_hoy']);
    }

    public function test_un_dia_futuro_no_cuenta_como_atraso(): void
    {
        $p = $this->programa();
        // Hoy (miércoles) 40 y el jueves 100; nada empacado.
        $this->necesidad($p, 'Nogales', 'Vesta', 'Bulk', ['Amarillo'], 'RPC', [0, 0, 40, 100, 0, 0, 0]);

        $fila = $this->fila($this->datos()['renglones'], 'Bulk', $this->ab(), 'RPC');

        $this->assertSame(0, $fila['atraso']);
        $this->assertSame(40, $fila['para_hoy']);
    }

    public function test_real_sin_renglon_programado_va_a_sin_programa(): void
    {
        $p = $this->programa();
        $this->necesidad($p, 'Nogales', 'Vesta', 'Bulk', ['Amarillo'], 'RPC', [100, 0, 0, 0, 0, 0, 0]);
        $this->pallet('2026-11-24', $this->receta('Tray', 'Cartón'), 35, 'Blanco');

        $d = $this->datos();

        $this->assertCount(1, $d['renglones']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $d['renglones'][0]['real']);
        $this->assertSame([[
            'presentacion_id' => $this->presentacion('Tray')->id,
            'grupo_id' => $this->blanco(),
            'tipo_empaque_id' => $this->tipoEmpaque('Cartón')->id,
            'programado' => [0, 0, 0, 0, 0, 0, 0],
            'real' => [0, 35, 0, 0, 0, 0, 0],
            'atraso' => 0,
            'para_hoy' => null,
        ]], $d['sin_programa']);
        $this->assertSame(35, $d['totales']['real']);
    }

    public function test_pallets_sin_clasificar_y_programa_sin_tipo_de_empaque_salen_aparte(): void
    {
        $p = $this->programa();
        $this->necesidad($p, 'Texas', 'Caruso', 'Bulk', ['Amarillo'], null, [0, 70, 0, 0, 0, 0, 0]);
        $suelta = $this->recetaEmpaque('Sin combinación');
        $this->pallet('2026-11-26', $suelta, 30, 'Amarillo');

        $d = $this->datos();

        $this->assertSame([], $d['renglones']);
        $this->assertSame([], $d['sin_programa']);
        $this->assertSame([0, 70, 0, 0, 0, 0, 0], $d['programado_sin_clasificar']);
        $this->assertSame([0, 0, 0, 30, 0, 0, 0], $d['sin_clasificar']['cajas']);
        $this->assertSame(1, $d['sin_clasificar']['pallets']);
        $this->assertSame([['recipe_id' => $suelta->id, 'nombre' => 'Sin combinación', 'cajas' => 30]], $d['sin_clasificar']['recetas']);
        $this->assertSame(70, $d['totales']['programado']);
        $this->assertSame(30, $d['totales']['real']);
        $this->assertSame(0, $d['totales']['atraso']);
    }

    public function test_semana_sin_programa_no_se_crea_y_el_real_sale_en_sin_programa(): void
    {
        $this->pallet('2026-11-23', $this->receta('Bulk', 'RPC'), 60, 'Amarillo');
        $antes = ProgramaSemanal::count();

        $d = $this->datos();

        $this->assertSame($antes, ProgramaSemanal::count());
        $this->assertSame(0, $antes);
        $this->assertSame([], $d['renglones']);
        $this->assertCount(1, $d['sin_programa']);
        $this->assertSame([60, 0, 0, 0, 0, 0, 0], $d['sin_programa'][0]['real']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $d['programado_sin_clasificar']);
        $this->assertSame(['programado' => 0, 'real' => 60, 'atraso' => 0, 'para_hoy' => 0], $d['totales']);
    }

    public function test_los_totales_cuadran(): void
    {
        $p = $this->programa();
        $this->necesidad($p, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [100, 50, 0, 0, 0, 0, 0]);
        $this->necesidad($p, 'Nogales', 'Vesta', 'Bulk', ['Blanco'], 'Cartón', [0, 0, 200, 0, 0, 0, 0]);
        $this->necesidad($p, 'Texas', 'Caruso', 'Tray', ['Amarillo'], null, [10, 0, 0, 0, 0, 0, 0]);
        $this->crearSobrante($p, [
            'bodega_id' => $this->bodega('Nogales')->id,
            'presentacion_id' => $this->presentacion('Minicobs')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
            'cajas_4' => 75,
        ], [$this->variedades['Bicolor']->id]);

        $bulk = $this->receta('Bulk', 'RPC');
        $tray = $this->receta('Tray', 'RPC');
        $suelta = $this->recetaEmpaque('Sin combinación');
        $this->pallet('2026-11-23', $bulk, 90, 'Amarillo');                                   // renglón programado
        $this->pallet('2026-11-24', $tray, 40, 'Blanco', ['status' => 'embarcado']);          // sin programa
        $this->pallet('2026-11-25', $suelta, 25, 'Bicolor', ['is_cola' => true]);             // sin clasificar
        $this->pallet('2026-11-26', null, 15, 'Amarillo');                                    // sin receta
        $this->pallet('2026-11-27', $bulk, 0, 'Amarillo', ['total_cajas' => 50], [[$tray, 20]]);  // remanente 30
        $this->pallet('2026-11-29', $bulk, 0, 'Bicolor', ['total_cajas' => 10], [[$tray, 25]]);   // detalles de más
        $this->pallet('2026-11-24', $bulk, 999, 'Amarillo')->delete();                        // borrado
        $this->pallet('2026-11-30', $bulk, 777, 'Amarillo');                                  // otra semana
        $this->pallet('2026-11-22', $bulk, 555, 'Amarillo');                                  // otra semana

        $d = $this->datos();

        // Cajas reales de la semana: por pallet, sus cajas o las de sus detalles si suman más.
        $esperado = ProduccionEmpaque::with('detalles')
            ->where('fecha_produccion', '>=', '2026-11-23')
            ->where('fecha_produccion', '<', '2026-11-30')
            ->get()
            ->sum(fn ($pl) => max((int) $pl->total_cajas, (int) $pl->detalles->sum('total_cajas')));
        $this->assertSame(90 + 40 + 25 + 15 + 50 + 25, $esperado);

        $mostrado = 0;
        foreach (array_merge($d['renglones'], $d['sin_programa']) as $f) {
            $mostrado += array_sum($f['real']);
        }
        $mostrado += array_sum($d['sin_clasificar']['cajas']);
        $this->assertSame($esperado, $mostrado);
        $this->assertSame($esperado, $d['totales']['real']);

        $programadoDelPrograma = (int) collect(app(ProgramaSemanalService::class)->empaqueDeSemana(self::LUNES))
            ->sum(fn ($r) => array_sum($r['cajas']));
        $this->assertSame(100 + 50 + 200 + 10 + 75, $programadoDelPrograma);

        $programadoMostrado = array_sum($d['programado_sin_clasificar']);
        foreach (array_merge($d['renglones'], $d['sin_programa']) as $f) {
            $programadoMostrado += array_sum($f['programado']);
        }
        $this->assertSame($programadoDelPrograma, $programadoMostrado);
        $this->assertSame($programadoDelPrograma, $d['totales']['programado']);

        $this->assertSame(array_sum(array_column($d['renglones'], 'atraso')), $d['totales']['atraso']);
        $this->assertSame(array_sum(array_column($d['renglones'], 'para_hoy')), $d['totales']['para_hoy']);
    }

    public function test_con_cultivo_solo_entran_presentaciones_de_ese_cultivo(): void
    {
        $calabaza = Cultivo::create(['nombre' => 'Calabaza']);
        $gris = Variedad::create(['cultivo_id' => $calabaza->id, 'nombre' => 'Gris', 'user_id' => $this->autor->id]);
        $caja = Presentacion::create(['cultivo_id' => $calabaza->id, 'nombre' => 'Caja 1/2', 'orden' => 1, 'is_active' => true]);

        $p = $this->programa();
        $this->necesidad($p, 'Nogales', 'Vesta', 'Bulk', ['Amarillo'], 'RPC', [100, 0, 0, 0, 0, 0, 0]);
        $otra = ProgramaNecesidad::create([
            'programa_id' => $p->id,
            'bodega_id' => $this->bodega('Nogales')->id,
            'cliente_id' => null,
            'cliente_nombre' => 'Vesta',
            'cultivo_id' => $calabaza->id,
            'presentacion_id' => $caja->id,
            'tipo_empaque_id' => $this->tipoEmpaque('Cartón')->id,
            'cajas_1' => 40,
        ]);
        $otra->variedades()->attach([$gris->id]);

        $recetaCaja = $this->recetaEmpaque('Caja calabaza');
        $this->combinacion($recetaCaja, $caja, 'Cartón');
        $this->pallet('2026-11-24', $recetaCaja, 30, $gris);
        $this->pallet('2026-11-23', $this->receta('Bulk', 'RPC'), 60, 'Amarillo');

        $elote = $this->datos(self::LUNES, $this->cultivo->id);
        $this->assertCount(1, $elote['renglones']);
        $this->assertSame($this->presentacion('Bulk')->id, $elote['renglones'][0]['presentacion_id']);
        $this->assertSame(100, $elote['totales']['programado']);
        $this->assertSame(60, $elote['totales']['real']);
        $this->assertSame([$this->presentacion('Bulk')->id], array_column($elote['catalogos']['presentaciones'], 'id'));

        $calabazaDatos = $this->datos(self::LUNES, $calabaza->id);
        $this->assertCount(1, $calabazaDatos['renglones']);
        $fila = $calabazaDatos['renglones'][0];
        $this->assertSame($caja->id, $fila['presentacion_id']);
        $this->assertSame('v'.$gris->id, $fila['grupo_id']);
        $this->assertSame([0, 40, 0, 0, 0, 0, 0], $fila['programado']);
        $this->assertSame([0, 30, 0, 0, 0, 0, 0], $fila['real']);
        $this->assertSame([['id' => 'v'.$gris->id, 'nombre' => 'Gris']], $calabazaDatos['catalogos']['grupos']);

        $todo = $this->datos();
        $this->assertCount(2, $todo['renglones']);
        $this->assertSame(140, $todo['totales']['programado']);
    }

    public function test_orden_de_renglones_y_catalogos(): void
    {
        $p = $this->programa();
        // Presentación primero (Bulk antes que Tray), luego nombre del grupo, luego empaque (RPC antes que Cartón).
        $this->necesidad($p, 'Nogales', 'A', 'Tray', ['Amarillo'], 'RPC', [10, 0, 0, 0, 0, 0, 0]);
        $this->necesidad($p, 'Nogales', 'B', 'Bulk', ['Blanco'], 'RPC', [10, 0, 0, 0, 0, 0, 0]);
        $this->necesidad($p, 'Nogales', 'C', 'Bulk', ['Amarillo'], 'Cartón', [10, 0, 0, 0, 0, 0, 0]);
        $this->necesidad($p, 'Nogales', 'D', 'Bulk', ['Amarillo'], 'RPC', [10, 0, 0, 0, 0, 0, 0]);

        $d = $this->datos();

        $orden = array_map(fn ($f) => [$f['presentacion_id'], $f['grupo_id'], $f['tipo_empaque_id']], $d['renglones']);
        $this->assertSame([
            [$this->presentacion('Bulk')->id, $this->ab(), $this->tipoEmpaque('RPC')->id],
            [$this->presentacion('Bulk')->id, $this->ab(), $this->tipoEmpaque('Cartón')->id],
            [$this->presentacion('Bulk')->id, $this->blanco(), $this->tipoEmpaque('RPC')->id],
            [$this->presentacion('Tray')->id, $this->ab(), $this->tipoEmpaque('RPC')->id],
        ], $orden);

        $bulk = $this->presentacion('Bulk');
        $this->assertSame([
            ['id' => $bulk->id, 'nombre' => 'Bulk', 'cultivo_id' => $this->cultivo->id, 'orden' => $bulk->orden],
            ['id' => $this->presentacion('Tray')->id, 'nombre' => 'Tray', 'cultivo_id' => $this->cultivo->id, 'orden' => $this->presentacion('Tray')->orden],
        ], $d['catalogos']['presentaciones']);
        $this->assertEqualsCanonicalizing([
            ['id' => $this->ab(), 'nombre' => 'Amarillo / Bicolor'],
            ['id' => $this->blanco(), 'nombre' => 'Blanco'],
        ], $d['catalogos']['grupos']);
        $this->assertSame(['RPC', 'Cartón'], array_column($d['catalogos']['tipos_empaque'], 'nombre'));
        $this->assertSame(['id', 'nombre', 'orden'], array_keys($d['catalogos']['tipos_empaque'][0]));
    }

    public function test_sin_n_mas_1_con_muchos_pallets_y_renglones(): void
    {
        $p = $this->programa();
        $this->necesidad($p, 'Nogales', 'Vesta', 'Bulk', ['Amarillo'], 'RPC', [100, 0, 0, 0, 0, 0, 0]);
        $bulk = $this->receta('Bulk', 'RPC');
        $tray = $this->receta('Tray', 'Cartón');
        $this->pallet('2026-11-23', $bulk, 10, 'Amarillo');

        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->datos();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $pocos = $contar();

        $this->necesidad($p, 'Texas', 'Walmart', 'Tray', ['Blanco'], 'Cartón', [0, 50, 0, 0, 0, 0, 0]);
        $this->necesidad($p, 'Nogales', 'Vallarta', 'Minicobs', ['Bicolor'], 'RPC', [0, 0, 50, 0, 0, 0, 0]);
        for ($i = 0; $i < 8; $i++) {
            $this->pallet('2026-11-2'.(3 + $i % 7), $i % 2 ? $bulk : $tray, 10 + $i, $i % 3 ? 'Blanco' : 'Bicolor', [], $i === 4 ? [[$bulk, 5], [$tray, 6]] : []);
        }
        $this->pallet('2026-11-24', $this->recetaEmpaque('Suelta'), 9, 'Amarillo');

        $this->assertSame($pocos, $contar());
    }

    public function test_sin_permiso_de_avance_responde_403(): void
    {
        Sanctum::actingAs($this->usuarioCon([]));
        $this->avance()->assertStatus(403);

        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view'], 'programa-empaque' => ['view']]));
        $this->avance()->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para esta acción de Planeación.');

        Sanctum::actingAs($this->usuarioCon(['avance-empaque' => ['view']]));
        $this->avance()->assertOk()->assertJsonPath('success', true);
    }

    public function test_valida_lunes_y_cultivo(): void
    {
        $this->getJson(self::URL)->assertStatus(422)->assertJsonValidationErrors('lunes');
        $this->avance('2026-11-24')->assertStatus(422)->assertJsonValidationErrors('lunes')
            ->assertJsonPath('errors.lunes.0', 'La semana se identifica por su lunes (AAAA-MM-DD).');
        $this->avance('23-11-2026')->assertStatus(422)->assertJsonValidationErrors('lunes');
        $this->avance('2026-02-30')->assertStatus(422)->assertJsonValidationErrors('lunes');
        $this->avance(self::LUNES, 999999)->assertStatus(422)->assertJsonValidationErrors('cultivo_id');
        $this->assertSame(0, ProgramaSemanal::count());
    }

    // ── Domingo opcional ────────────────────────────────

    public function test_el_payload_dice_si_empaque_trabaja_el_domingo(): void
    {
        // Sin programa el Avance no lo crea: se asume que sí.
        $this->assertTrue($this->datos()['trabaja_domingo']);
        $this->assertSame(0, ProgramaSemanal::count());

        $p = $this->programa();
        $this->assertTrue($this->datos()['trabaja_domingo']);

        // Solo cuenta Empaque: con Corte y Embarques trabajando el domingo («alguno» = sí) sigue en false.
        $p->forceFill(['domingo_empaque' => false, 'trabaja_domingo' => true])->save();
        $this->assertFalse($this->datos()['trabaja_domingo']);
    }

    public function test_empaque_con_domingo_aunque_corte_y_embarques_no(): void
    {
        $p = $this->programa();
        $this->necesidad($p, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [0, 0, 0, 0, 0, 0, 40]);
        $p->forceFill(['domingo_corte' => false, 'domingo_embarques' => false])->save();

        $d = $this->datos();

        $this->assertTrue($d['trabaja_domingo']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 40], $this->fila($d['renglones'], 'Bulk', $this->ab(), 'RPC')['programado']);
    }

    public function test_el_real_del_domingo_se_cuenta_aunque_empaque_no_lo_trabaje(): void
    {
        $p = $this->programa();
        $this->necesidad($p, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [0, 0, 0, 0, 0, 0, 40]);
        // Corte y Embarques sí trabajan el domingo: el programado igual se adelanta al sábado.
        $p->forceFill(['domingo_empaque' => false, 'trabaja_domingo' => true])->save();
        $this->pallet('2026-11-29', $this->receta('Bulk', 'RPC'), 25, 'Amarillo');

        $d = $this->datos();

        $this->assertFalse($d['trabaja_domingo']);
        $fila = $this->fila($d['renglones'], 'Bulk', $this->ab(), 'RPC');
        // Lo del domingo se programa el sábado; el real del domingo sigue apareciendo en su día.
        $this->assertSame([0, 0, 0, 0, 0, 40, 0], $fila['programado']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 25], $fila['real']);
        $this->assertSame(40, $d['totales']['programado']);
        $this->assertSame(25, $d['totales']['real']);
    }
}
