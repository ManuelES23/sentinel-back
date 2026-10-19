<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola;

use App\Events\EtapaUpdated;
use App\Models\Cultivo;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\Productor;
use App\Models\Temporada;
use App\Models\User;
use App\Models\ZonaCultivo;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Tiempo real de las etapas: cada alta, cambio, baja y registro de siembra se
 * emite por el canal del módulo agrícola de Operación Agrícola, para que el
 * Plan de siembra y la vista de Etapas de otra persona se actualicen solos.
 */
class EtapasTiempoRealTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/operacion-agricola/agricola/etapas';

    /** Límite por mensaje de Reverb (`REVERB_APP_MAX_MESSAGE_SIZE`). */
    private const LIMITE_REVERB = 10_000;

    private User $user;
    private Cultivo $cultivo;
    private Temporada $temporada;
    private Lote $lote;
    private int $contador = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-15 12:00:00');

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->cultivo = Cultivo::create(['nombre' => 'Mango']);
        $this->temporada = Temporada::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'Mango 2026',
            'locacion' => 'Sinaloa',
            'folio_temporada' => $this->cultivo->id.'-001',
            'año_inicio' => 2026,
            'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-12-31',
            'user_id' => $this->user->id,
        ]);
        $productor = Productor::create([
            'nombre' => 'Juan',
            'apellido' => 'Pérez',
            'tipo' => Productor::TIPO_EXTERNO,
            'is_active' => true,
        ]);
        $zona = ZonaCultivo::create(['nombre' => 'Zona Norte', 'is_active' => true]);

        $this->lote = Lote::create([
            'productor_id' => $productor->id,
            'zona_cultivo_id' => $zona->id,
            'nombre' => 'Keitt A',
            'superficie' => 200,
            'is_active' => true,
        ]);
        $this->temporada->lotes()->attach($this->lote->id, [
            'cultivo_id' => $this->cultivo->id,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function crearEtapa(array $extra = []): Etapa
    {
        $this->contador++;

        return Etapa::create(array_merge([
            'lote_id' => $this->lote->id,
            'nombre' => "Etapa {$this->contador}",
            'codigo' => "L{$this->lote->numero_lote}-E{$this->contador}",
            'superficie' => 1,
            'orden' => $this->contador,
            'is_active' => true,
            'fecha_siembra_estimada' => '2026-04-06',
            'fecha_cosecha_estimada' => '2026-07-07',
        ], $extra));
    }

    /** @return array<int, EtapaUpdated> */
    private function eventos(): array
    {
        return Event::dispatched(EtapaUpdated::class)->map(fn ($args) => $args[0])->values()->all();
    }

    private function bytes(EtapaUpdated $evento): int
    {
        return strlen(json_encode([
            'event' => $evento->broadcastAs(),
            'channel' => $evento->broadcastOn()[0]->name,
            'data' => $evento->broadcastWith(),
        ]));
    }

    public function test_el_evento_va_por_el_canal_del_modulo_agricola_de_operacion_agricola(): void
    {
        $evento = new EtapaUpdated('deleted', [['id' => 1, 'lote_id' => 2]]);

        $this->assertSame('etapa.updated', $evento->broadcastAs());
        $this->assertSame(
            'private-module.splendidfarms.operacion-agricola.agricola',
            $evento->broadcastOn()[0]->name
        );
        $this->assertSame('deleted', $evento->broadcastWith()['action']);
        $this->assertSame([['id' => 1, 'lote_id' => 2]], $evento->broadcastWith()['data']['etapas']);
    }

    public function test_crear_emite_la_etapa_completa_con_las_temporadas_de_su_lote(): void
    {
        Event::fake([EtapaUpdated::class]);

        $r = $this->postJson(self::URL, [
            'lote_id' => $this->lote->id,
            'nombre' => 'Etapa nueva',
            'superficie' => 2.5,
            'fecha_siembra_estimada' => '2026-05-01',
            'temporada_id' => $this->temporada->id,
        ])->assertCreated();

        $eventos = $this->eventos();
        $this->assertCount(1, $eventos);
        $this->assertSame('created', $eventos[0]->action);

        $etapa = $eventos[0]->data['etapas'][0];
        $this->assertSame($r->json('data.id'), $etapa['id']);
        $this->assertSame([$this->temporada->id], $etapa['temporada_ids']);
        // Misma forma que la respuesta de la API (relaciones incluidas).
        $this->assertSame('Keitt A', $etapa['lote']['nombre']);
        $this->assertArrayHasKey('productor', $etapa['lote']);
        $this->assertArrayHasKey('tipo_variedad', $etapa);
        $this->assertLessThan(self::LIMITE_REVERB, $this->bytes($eventos[0]));
    }

    public function test_temporada_ids_solo_incluye_las_temporadas_donde_el_lote_esta_activo(): void
    {
        Event::fake([EtapaUpdated::class]);
        $vieja = Temporada::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'Mango 2025',
            'locacion' => 'Sinaloa',
            'folio_temporada' => $this->cultivo->id.'-000',
            'año_inicio' => 2025,
            'año_fin' => 2025,
            'fecha_inicio' => '2025-01-01',
            'fecha_fin' => '2025-12-31',
            'user_id' => $this->user->id,
        ]);
        $vieja->lotes()->attach($this->lote->id, ['cultivo_id' => $this->cultivo->id, 'is_active' => false]);
        $etapa = $this->crearEtapa();

        $this->putJson(self::URL."/{$etapa->id}", ['nombre' => 'Renombrada'])->assertOk();

        $this->assertSame([$this->temporada->id], $this->eventos()[0]->data['etapas'][0]['temporada_ids']);
    }

    public function test_editar_emite_la_etapa_actualizada(): void
    {
        Event::fake([EtapaUpdated::class]);
        $etapa = $this->crearEtapa();

        $this->putJson(self::URL."/{$etapa->id}", [
            'superficie' => 3,
            'fecha_siembra_estimada' => '2026-04-20',
        ])->assertOk();

        $eventos = $this->eventos();
        $this->assertCount(1, $eventos);
        $this->assertSame('updated', $eventos[0]->action);
        $emitida = $eventos[0]->data['etapas'][0];
        $this->assertSame($etapa->id, $emitida['id']);
        $this->assertEquals(3, $emitida['superficie']);
        $this->assertStringStartsWith('2026-04-20', $emitida['fecha_siembra_estimada']);
        $this->assertSame([$this->temporada->id], $emitida['temporada_ids']);
        $this->assertArrayHasKey('lote', $emitida);
    }

    public function test_borrar_emite_el_id_y_el_lote(): void
    {
        Event::fake([EtapaUpdated::class]);
        $etapa = $this->crearEtapa();

        $this->deleteJson(self::URL."/{$etapa->id}")->assertOk();

        $eventos = $this->eventos();
        $this->assertCount(1, $eventos);
        $this->assertSame('deleted', $eventos[0]->action);
        $this->assertSame([['id' => $etapa->id, 'lote_id' => $this->lote->id]], $eventos[0]->data['etapas']);
    }

    public function test_registrar_siembra_emite_parches_en_grupos_que_caben_en_reverb(): void
    {
        Event::fake([EtapaUpdated::class]);
        $ids = collect(range(1, 45))->map(fn () => $this->crearEtapa()->id)->all();

        $this->postJson(self::URL.'/registrar-siembra', [
            'temporada_id' => $this->temporada->id,
            'etapa_ids' => $ids,
            'fecha_siembra_real' => '2026-04-15',
        ])->assertOk();

        $eventos = $this->eventos();
        $this->assertCount(2, $eventos);
        $this->assertSame(['updated', 'updated'], array_map(fn ($e) => $e->action, $eventos));
        $this->assertCount(EtapaUpdated::PARCHES_POR_EVENTO, $eventos[0]->data['etapas']);
        $this->assertCount(5, $eventos[1]->data['etapas']);

        $emitidos = array_merge($eventos[0]->data['etapas'], $eventos[1]->data['etapas']);
        $this->assertEqualsCanonicalizing($ids, array_column($emitidos, 'id'));

        // Parche: solo lo que cambió, sin `temporada_ids` (el front lo aplica a etapas que ya tiene).
        $parche = $emitidos[0];
        $this->assertEqualsCanonicalizing(
            ['id', 'fecha_siembra_real', 'fecha_cosecha_proyectada', 'updated_at'],
            array_keys($parche)
        );
        $this->assertStringStartsWith('2026-04-15', $parche['fecha_siembra_real']);
        $this->assertStringStartsWith('2026-07-16', $parche['fecha_cosecha_proyectada']);

        foreach ($eventos as $evento) {
            $this->assertLessThan(self::LIMITE_REVERB, $this->bytes($evento));
        }
    }

    public function test_un_registro_rechazado_no_emite_nada(): void
    {
        Event::fake([EtapaUpdated::class]);
        $sembrada = $this->crearEtapa(['fecha_siembra_real' => '2026-04-10']);

        $this->postJson(self::URL.'/registrar-siembra', [
            'temporada_id' => $this->temporada->id,
            'etapa_ids' => [$sembrada->id],
            'fecha_siembra_real' => '2026-04-15',
        ])->assertStatus(422);

        $this->putJson(self::URL."/{$sembrada->id}", ['superficie' => 999])->assertStatus(422);

        Event::assertNotDispatched(EtapaUpdated::class);
    }

    public function test_un_reverb_caido_no_convierte_en_500_las_escrituras(): void
    {
        Broadcast::extend('reventando', fn () => new class implements Broadcaster
        {
            public function auth($request)
            {
                return true;
            }

            public function validAuthenticationResponse($request, $result)
            {
                return $result;
            }

            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new RuntimeException('Pusher error: connection refused');
            }
        });
        config(['broadcasting.default' => 'reventando']);

        $creada = $this->postJson(self::URL, [
            'lote_id' => $this->lote->id,
            'nombre' => 'Sin websockets',
            'superficie' => 1,
        ])->assertCreated()->json('data.id');

        $this->putJson(self::URL."/{$creada}", ['nombre' => 'Editada sin websockets'])->assertOk();
        $this->postJson(self::URL.'/registrar-siembra', [
            'temporada_id' => $this->temporada->id,
            'etapa_ids' => [$creada],
            'fecha_siembra_real' => '2026-04-15',
        ])->assertOk();
        $this->deleteJson(self::URL."/{$creada}")->assertOk();

        $this->assertSoftDeleted('etapas', ['id' => $creada, 'nombre' => 'Editada sin websockets']);
    }
}
