<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\DestinoEmbarque;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\PlantasAvance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET planeacion/programa/avance-embarques: las cargas programadas de la semana
 * (Full + Sencillo por bodega y día) contra los embarques salidos (cargando, en
 * tránsito o entregado), una carga por embarque. Hoy fijo: miércoles 2026-11-25
 * de la semana del lunes 2026-11-23.
 */
class AvanceEmbarquesTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion/programa/avance-embarques';

    private const LUNES = '2026-11-23';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->crearElote();
        (new PlantasAvance)->guardar([$this->plantaEmpaqueId()]);
        Sanctum::actingAs($this->usuarioCon(['avance-embarques' => ['view']]));
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
     * Escenario común: plan de Texas (full lunes 2, sencillo lunes 1, full martes 2,
     * full miércoles 1) y los embarques reales del brief.
     */
    private function escenario(): ProgramaSemanal
    {
        $p = $this->programa();
        $this->cargaPlan($p, 'Texas', 'full', 0, 2);
        $this->cargaPlan($p, 'Texas', 'sencillo', 0, 1);
        $this->cargaPlan($p, 'Texas', 'full', 1, 2);
        $this->cargaPlan($p, 'Texas', 'full', 2, 1);

        $this->embarqueReal('2026-11-23', 'entregado', '  TEXAS ', ['total_cajas' => 1000]);
        $this->embarqueReal('2026-11-23', 'en_transito', 'Téxas', ['total_cajas' => 1100]);
        $consignatario = $this->consignatario('Bodega de Texas', 'Texas');
        $this->embarqueReal('2026-11-24', 'cargando', 'otro', ['total_cajas' => 950, 'destino_consignatario_id' => $consignatario->id]);
        $this->embarqueReal('2026-11-24', 'cancelado', 'Texas');
        $this->embarqueReal('2026-11-24', 'programado', 'Texas');
        $this->embarqueReal('2026-11-24', 'entregado', 'Texas')->delete();
        $this->embarqueReal('2026-11-24', 'entregado', 'Nogales');
        $this->embarqueReal('2026-11-25', 'entregado', 'Chicago');

        return $p;
    }

    private function fila(array $filas, string $nombre): ?array
    {
        foreach ($filas as $f) {
            if ($f['nombre'] === $nombre) {
                return $f;
            }
        }

        return null;
    }

    public function test_cruza_cargas_programadas_con_embarques_salidos(): void
    {
        $this->escenario();

        $d = $this->datos();

        $this->assertSame(self::LUNES, $d['lunes']);
        $this->assertSame('2026-11-25', $d['hoy']);
        $this->assertSame(2, $d['dias_pasados']);
        $this->assertTrue($d['trabaja_domingo']);

        $this->assertCount(1, $d['renglones']);
        $texas = $this->fila($d['renglones'], 'Texas');
        $this->assertSame($this->bodega('Texas')->id, $texas['bodega_id']);
        $this->assertSame([3, 2, 1, 0, 0, 0, 0], $texas['plan']);
        $this->assertSame([2, 2, 1, 0, 0, 0, 0], $texas['full']);
        $this->assertSame([1, 0, 0, 0, 0, 0, 0], $texas['sencillo']);
        $this->assertSame([2, 1, 0, 0, 0, 0, 0], $texas['real']);
        $this->assertSame([2100, 950, 0, 0, 0, 0, 0], $texas['cajas']);
        $this->assertSame(2, $texas['atraso']);
        $this->assertSame(3, $texas['para_hoy']);

        $this->assertSame(6, $d['totales']['plan']);
        $this->assertSame(2, $d['totales']['atraso']);
        $this->assertSame(3, $d['totales']['para_hoy']);
    }

    public function test_solo_cuentan_cargando_en_transito_y_entregado(): void
    {
        $this->escenario();

        $d = $this->datos();

        // Cancelado, programado y borrado no suman en ningún lado: 3 de Texas + Nogales + Chicago.
        $this->assertSame(5, $d['totales']['real']);
        $this->assertSame(2100 + 950 + 1000 + 1000, $d['totales']['cajas']);
        $this->assertSame([2, 1, 0, 0, 0, 0, 0], $this->fila($d['renglones'], 'Texas')['real']);
        $this->assertSame([0, 1, 0, 0, 0, 0, 0], $this->fila($d['sin_programa'], 'Nogales')['real']);
        $this->assertSame([0, 0, 1, 0, 0, 0, 0], $d['sin_destino']['cargas']);
    }

    public function test_destino_por_nombre_normalizado_y_consignatario(): void
    {
        $p = $this->programa();
        $this->cargaPlan($p, 'Texas', 'full', 0, 1);
        $texas = $this->consignatario('Cliente A', 'Texas');
        $sinBodega = $this->consignatario('Cliente B', null);
        $vacia = $this->consignatario('Cliente C', '   ');

        $this->embarqueReal('2026-11-23', 'entregado', '  TEXAS ');
        $this->embarqueReal('2026-11-23', 'entregado', 'Téxas');
        $this->embarqueReal('2026-11-24', 'entregado', 'otro', ['destino_consignatario_id' => $texas->id]);
        $this->embarqueReal('2026-11-24', 'entregado', 'Texas', ['destino_consignatario_id' => $sinBodega->id]);
        $this->embarqueReal('2026-11-25', 'entregado', 'TEXAS', ['destino_consignatario_id' => $vacia->id]);

        $d = $this->datos();

        $this->assertSame([2, 2, 1, 0, 0, 0, 0], $this->fila($d['renglones'], 'Texas')['real']);
        $this->assertSame([], $d['sin_programa']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $d['sin_destino']['cargas']);
    }

    private function destinoCatalogo(string $nombre, ?string $bodegaPlaneacion = null, bool $activo = true): DestinoEmbarque
    {
        return DestinoEmbarque::create([
            'enterprise_id' => $this->empresaSfId(),
            'nombre' => $nombre,
            'plan_bodega_destino_id' => $bodegaPlaneacion ? $this->bodega($bodegaPlaneacion)->id : null,
            'is_active' => $activo,
        ]);
    }

    public function test_destino_del_catalogo_ligado_cuenta_en_su_bodega_aunque_el_nombre_no_coincida(): void
    {
        $p = $this->programa();
        $this->cargaPlan($p, 'Texas', 'full', 0, 2);
        $mcallen = $this->destinoCatalogo('McAllen', 'Texas');
        // Desactivado después: sigue contando (el embarque ya salió a ese destino).
        $pharr = $this->destinoCatalogo('Pharr', 'Texas', false);

        $this->embarqueReal('2026-11-23', 'entregado', 'McAllen', ['destino_embarque_id' => $mcallen->id, 'total_cajas' => 900]);
        $this->embarqueReal('2026-11-23', 'en_transito', 'Pharr', ['destino_embarque_id' => $pharr->id, 'total_cajas' => 800]);

        $d = $this->datos();

        $texas = $this->fila($d['renglones'], 'Texas');
        $this->assertSame([2, 0, 0, 0, 0, 0, 0], $texas['real']);
        $this->assertSame([1700, 0, 0, 0, 0, 0, 0], $texas['cajas']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $d['sin_destino']['cargas']);
    }

    public function test_destino_del_catalogo_sin_liga_empata_por_su_nombre_o_cae_en_sin_destino(): void
    {
        $p = $this->programa();
        $this->cargaPlan($p, 'Texas', 'full', 0, 1);
        $nogales = $this->destinoCatalogo('Nogales');
        $chicago = $this->destinoCatalogo('Chicago');

        // El nombre del catálogo manda sobre el texto guardado.
        $this->embarqueReal('2026-11-24', 'entregado', 'otro', ['destino_embarque_id' => $nogales->id]);
        $this->embarqueReal('2026-11-25', 'cargando', 'Texas', ['destino_embarque_id' => $chicago->id]);

        $d = $this->datos();

        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $this->fila($d['renglones'], 'Texas')['real']);
        $this->assertSame([0, 1, 0, 0, 0, 0, 0], $this->fila($d['sin_programa'], 'Nogales')['real']);
        $this->assertSame([0, 0, 1, 0, 0, 0, 0], $d['sin_destino']['cargas']);
        $this->assertSame([['destino' => 'Chicago', 'cargas' => 1]], $d['sin_destino']['destinos']);
    }

    public function test_destino_reconocido_sin_plan_va_a_sin_programa(): void
    {
        $this->escenario();

        $d = $this->datos();

        $this->assertCount(1, $d['sin_programa']);
        $nogales = $this->fila($d['sin_programa'], 'Nogales');
        $this->assertSame($this->bodega('Nogales')->id, $nogales['bodega_id']);
        $this->assertSame(1, $nogales['real'][1]);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $nogales['plan']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $nogales['full']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $nogales['sencillo']);
        $this->assertSame([0, 1000, 0, 0, 0, 0, 0], $nogales['cajas']);
        $this->assertSame(0, $nogales['atraso']);
        $this->assertNull($nogales['para_hoy']);
    }

    public function test_sin_destino_agrupa_por_clave_y_sin_destino(): void
    {
        $this->escenario();
        $this->embarqueReal('2026-11-25', 'cargando', null, ['total_cajas' => 500]);
        $this->embarqueReal('2026-11-25', 'cargando', '  chicago ', ['total_cajas' => 400]);

        $d = $this->datos();

        $this->assertSame([0, 0, 3, 0, 0, 0, 0], $d['sin_destino']['cargas']);
        $this->assertSame([0, 0, 1900, 0, 0, 0, 0], $d['sin_destino']['cajas']);
        $this->assertSame([
            ['destino' => 'Chicago', 'cargas' => 2],
            ['destino' => 'Sin destino', 'cargas' => 1],
        ], $d['sin_destino']['destinos']);
        $this->assertSame(7, $d['totales']['real']);
    }

    public function test_sin_destino_reconocido(): void
    {
        $this->escenario();

        $d = $this->datos();

        $this->assertSame(1, $d['sin_destino']['cargas'][2]);
        $this->assertSame([['destino' => 'Chicago', 'cargas' => 1]], $d['sin_destino']['destinos']);
        $this->assertSame(5, $d['totales']['real']);
    }

    public function test_solo_plantas_del_avance(): void
    {
        $this->escenario();
        $otra = $this->planta('Otra planta', true);
        $this->embarqueReal('2026-11-23', 'entregado', 'Texas', ['entity_id' => $otra->id]);

        $d = $this->datos();

        $this->assertSame([2, 1, 0, 0, 0, 0, 0], $this->fila($d['renglones'], 'Texas')['real']);
        $this->assertSame(5, $d['totales']['real']);
        $this->assertSame([['id' => $this->plantaEmpaqueId(), 'nombre' => 'Empaque Principal']], $d['plantas']);

        // Sin ninguna planta elegida el real queda en ceros.
        (new PlantasAvance)->guardar([]);
        $d = $this->datos();
        $this->assertSame(0, $d['totales']['real']);
        $this->assertSame([], $d['plantas']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $this->fila($d['renglones'], 'Texas')['real']);
    }

    public function test_filtra_por_cultivo(): void
    {
        $p = $this->escenario();
        $otra = $this->otroCultivo();
        $this->embarqueReal('2026-11-23', 'entregado', 'Texas', ['temporada_id' => $otra->id]);
        // Plan de otro cultivo en la misma bodega y día.
        \App\Models\Planeacion\ProgramaEmbarque::create([
            'programa_id' => $p->id,
            'cultivo_id' => $otra->cultivo_id,
            'bodega_id' => $this->bodega('Texas')->id,
            'tipo' => 'full',
            'dia' => 4,
            'cargas' => 5,
        ]);

        $todo = $this->datos();
        $this->assertSame(6, $todo['totales']['real']);
        $this->assertSame(11, $todo['totales']['plan']);

        $elote = $this->datos(self::LUNES, ['cultivo_id' => $this->cultivo->id]);
        $this->assertSame(5, $elote['totales']['real']);
        $this->assertSame(6, $elote['totales']['plan']);
        $this->assertSame([3, 2, 1, 0, 0, 0, 0], $this->fila($elote['renglones'], 'Texas')['plan']);

        $solo = $this->datos(self::LUNES, ['cultivo_id' => $otra->cultivo_id]);
        $this->assertSame(1, $solo['totales']['real']);
        $this->assertSame(5, $solo['totales']['plan']);

        $this->avance(self::LUNES, ['cultivo_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('cultivo_id');
    }

    public function test_dia_futuro_y_semanas_pasada_y_futura(): void
    {
        $p = $this->escenario();
        $this->cargaPlan($p, 'Texas', 'full', 4, 2);

        // Un día futuro sin real no cambia el atraso.
        $this->assertSame(2, $this->fila($this->datos()['renglones'], 'Texas')['atraso']);

        // Semana futura: ni atraso ni «para hoy».
        $futura = $this->programa('2026-11-30');
        $this->cargaPlan($futura, 'Texas', 'full', 0, 3);
        $d = $this->datos('2026-11-30');
        $fila = $this->fila($d['renglones'], 'Texas');
        $this->assertSame(0, $fila['atraso']);
        $this->assertNull($fila['para_hoy']);
        $this->assertSame(0, $d['dias_pasados']);
        $this->assertNull($d['totales']['para_hoy']);

        // Semana pasada: el atraso es el de toda la semana.
        $pasada = $this->programa('2026-11-16');
        $this->cargaPlan($pasada, 'Texas', 'full', 0, 1);
        $d = $this->datos('2026-11-16');
        $fila = $this->fila($d['renglones'], 'Texas');
        $this->assertSame(7, $d['dias_pasados']);
        $this->assertSame(1, $fila['atraso']);
        $this->assertNull($fila['para_hoy']);
    }

    public function test_semana_sin_programa_no_se_crea(): void
    {
        $this->embarqueReal('2026-11-24', 'entregado', 'Texas');

        $d = $this->datos();

        $this->assertSame([], $d['renglones']);
        $this->assertSame([0, 1, 0, 0, 0, 0, 0], $this->fila($d['sin_programa'], 'Texas')['real']);
        $this->assertTrue($d['trabaja_domingo']);
        $this->assertSame(0, ProgramaSemanal::count());
    }

    public function test_real_del_domingo_cuenta_sin_domingo(): void
    {
        $p = $this->programa();
        $this->cargaPlan($p, 'Texas', 'full', 0, 1);
        $p->update(['domingo_embarques' => false]);
        $this->embarqueReal('2026-11-29', 'entregado', 'Texas');

        $d = $this->datos();
        $this->assertFalse($d['trabaja_domingo']);
        $this->assertSame(1, $this->fila($d['renglones'], 'Texas')['real'][6]);
        $this->assertSame(1, $d['totales']['real']);

        // Solo la bandera de corte no cambia la de embarques.
        $p->update(['domingo_embarques' => true, 'domingo_corte' => false]);
        $this->assertTrue($this->datos()['trabaja_domingo']);
        $p->update(['domingo_embarques' => false]);
        $this->assertFalse($this->datos()['trabaja_domingo']);
    }

    public function test_sin_permiso_403_y_valida_lunes(): void
    {
        Sanctum::actingAs($this->usuarioCon(['avance-cosecha' => ['view']]));
        $this->avance()->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para esta acción de Planeación.');

        Sanctum::actingAs($this->usuarioCon(['avance-embarques' => ['view']]));
        $this->avance('2026-11-24')->assertStatus(422)->assertJsonValidationErrors('lunes');
        $this->avance('2026-11-24')->assertJsonPath('errors.lunes.0', 'La semana se identifica por su lunes (AAAA-MM-DD).');
        $this->getJson(self::URL)->assertStatus(422)->assertJsonValidationErrors('lunes');
        $this->avance()->assertOk()->assertJsonPath('success', true);
    }

    public function test_sin_n_mas_1(): void
    {
        $p = $this->programa();
        $this->cargaPlan($p, 'Texas', 'full', 0, 1);
        $consignatario = $this->consignatario('Cliente', 'Texas');
        $this->embarqueReal('2026-11-23', 'entregado', 'Texas', ['destino_consignatario_id' => $consignatario->id]);

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

        $destinos = ['Texas', 'Nogales', 'Chicago', 'Mexicali', 'Nacional'];
        for ($i = 0; $i < 40; $i++) {
            $c = $this->consignatario("Cliente {$i}", $i % 2 ? 'Nogales' : null);
            $this->embarqueReal('2026-11-2'.(3 + $i % 7), 'entregado', $destinos[$i % 5], ['destino_consignatario_id' => $c->id]);
        }

        $this->assertSame($pocas, $contar());
    }
}
