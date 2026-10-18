<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola;

use App\Models\Cultivo;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\Productor;
use App\Models\Temporada;
use App\Models\User;
use App\Models\ZonaCultivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Plan de siembra: registrar la siembra real de varias etapas en una sola
 * transacción (todo o nada), calculando la cosecha proyectada con el ciclo.
 */
class RegistrarSiembraEtapasTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/operacion-agricola/agricola/etapas/registrar-siembra';

    private User $user;
    private Cultivo $cultivo;
    private Temporada $temporada;
    private Productor $productor;
    private ZonaCultivo $zona;
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
        $this->productor = Productor::create([
            'nombre' => 'Juan',
            'apellido' => 'Pérez',
            'tipo' => Productor::TIPO_EXTERNO,
            'is_active' => true,
        ]);
        $this->zona = ZonaCultivo::create(['nombre' => 'Zona Norte', 'is_active' => true]);

        $this->lote = $this->crearLote('Keitt A', true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function crearLote(string $nombre, bool $enTemporada): Lote
    {
        $lote = Lote::create([
            'productor_id' => $this->productor->id,
            'zona_cultivo_id' => $this->zona->id,
            'nombre' => $nombre,
            'superficie' => 20,
            'is_active' => true,
        ]);

        if ($enTemporada) {
            $this->temporada->lotes()->attach($lote->id, [
                'cultivo_id' => $this->cultivo->id,
                'is_active' => true,
            ]);
        }

        return $lote;
    }

    private function crearEtapa(Lote $lote, array $extra = []): Etapa
    {
        $this->contador++;

        return Etapa::create(array_merge([
            'lote_id' => $lote->id,
            'nombre' => "Etapa {$this->contador}",
            'codigo' => "L{$lote->numero_lote}-E{$this->contador}",
            'superficie' => 1,
            'orden' => $this->contador,
            'is_active' => true,
            'fecha_siembra_estimada' => '2026-04-06',
            'fecha_cosecha_estimada' => '2026-07-07', // ciclo de 92 días
        ], $extra));
    }

    private function payload(array $ids, string $fecha = '2026-04-15'): array
    {
        return [
            'temporada_id' => $this->temporada->id,
            'etapa_ids' => $ids,
            'fecha_siembra_real' => $fecha,
        ];
    }

    public function test_registra_varias_y_calcula_la_cosecha_con_su_ciclo(): void
    {
        $a = $this->crearEtapa($this->lote);
        $b = $this->crearEtapa($this->lote);

        $r = $this->postJson(self::URL, $this->payload([$a->id, $b->id]));

        $r->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', '2 siembras registradas')
            ->assertJsonCount(2, 'data');

        // Mismas relaciones que el index (claves presentes aunque el valor sea null)
        $this->assertArrayHasKey('productor', $r->json('data.0.lote'));
        $this->assertArrayHasKey('zona_cultivo', $r->json('data.0.lote'));
        $this->assertArrayHasKey('tipo_variedad', $r->json('data.0'));

        foreach ([$a, $b] as $etapa) {
            $fresca = $etapa->fresh();
            $this->assertSame('2026-04-15', $fresca->fecha_siembra_real->toDateString());
            $this->assertSame('2026-07-16', $fresca->fecha_cosecha_proyectada->toDateString());
        }
    }

    public function test_sin_ciclo_deja_la_cosecha_proyectada_vacia(): void
    {
        $a = $this->crearEtapa($this->lote, ['fecha_cosecha_estimada' => null]);

        $this->postJson(self::URL, $this->payload([$a->id]))->assertOk();

        $fresca = $a->fresh();
        $this->assertSame('2026-04-15', $fresca->fecha_siembra_real->toDateString());
        $this->assertNull($fresca->fecha_cosecha_proyectada);
    }

    public function test_rechaza_fecha_futura(): void
    {
        $a = $this->crearEtapa($this->lote);

        $this->postJson(self::URL, $this->payload([$a->id], '2026-04-16'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fecha_siembra_real');

        $this->assertNull($a->fresh()->fecha_siembra_real);
    }

    public function test_rechaza_etapa_de_otra_temporada_sin_tocar_nada(): void
    {
        $ajeno = $this->crearLote('Sin temporada', false);
        $a = $this->crearEtapa($this->lote);
        $b = $this->crearEtapa($ajeno);

        $this->postJson(self::URL, $this->payload([$a->id, $b->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('etapa_ids');

        $this->assertNull($a->fresh()->fecha_siembra_real);
        $this->assertNull($b->fresh()->fecha_siembra_real);
    }

    public function test_rechaza_etapa_ya_sembrada_con_su_codigo(): void
    {
        $a = $this->crearEtapa($this->lote, ['codigo' => 'L1-E1']);
        $b = $this->crearEtapa($this->lote, ['codigo' => 'L1-E2', 'fecha_siembra_real' => '2026-04-10']);

        $r = $this->postJson(self::URL, $this->payload([$a->id, $b->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('etapa_ids');

        $this->assertSame('L1-E2 ya tiene siembra registrada.', $r->json('errors.etapa_ids.0'));
        $this->assertNull($a->fresh()->fecha_siembra_real);
        $this->assertSame('2026-04-10', $b->fresh()->fecha_siembra_real->toDateString());
    }

    public function test_rechaza_mas_de_cien_y_repetidas(): void
    {
        $a = $this->crearEtapa($this->lote);

        $this->postJson(self::URL, $this->payload(range(1, 101)))
            ->assertStatus(422)
            ->assertJsonValidationErrors('etapa_ids');

        $this->postJson(self::URL, $this->payload([$a->id, $a->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['etapa_ids.0', 'etapa_ids.1']);

        $this->assertNull($a->fresh()->fecha_siembra_real);
    }

    public function test_rechaza_etapa_borrada(): void
    {
        $a = $this->crearEtapa($this->lote);
        $a->delete();

        $this->postJson(self::URL, $this->payload([$a->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('etapa_ids.0');
    }
}
