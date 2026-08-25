<?php
// tests/Feature/CRM/SincronizarDialpadCommandTest.php

namespace Tests\Feature\CRM;

use App\Console\Commands\SincronizarDialpadCommand;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmDialpadSyncEstado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class SincronizarDialpadCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        config([
            'services.dialpad.api_key' => 'fake-api-key',
            'services.dialpad.base_url' => 'https://dialpad.test/api/v2',
        ]);
        // El vendedor de fixtures no tiene email por defecto -- las llamadas
        // de prueba deben poder resolverlo.
        $this->vendedor->update(['email' => 'juan.perez@example.com']);
    }

    private function llamadaFake(array $overrides = []): array
    {
        return array_merge([
            'call_id' => 'call-1',
            'direction' => 'inbound',
            'duration' => 240000, // 4 minutos en ms
            'date_started' => now()->subMinutes(10)->valueOf(),
            'target' => ['email' => 'juan.perez@example.com'],
            'contact' => ['phone' => '6621234567'],
        ], $overrides);
    }

    public function test_llamada_con_vendedor_y_contacto_match_crea_actividad_completa(): void
    {
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Cliente Fake',
            'telefono' => '6621234567',
            'estatus' => 'activo',
        ]);

        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([
                'items' => [$this->llamadaFake()],
                'cursor' => null,
            ], 200),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $this->assertDatabaseHas('crm_actividades', [
            'dialpad_call_id' => 'call-1',
            'fuente' => 'dialpad',
            'tipo' => 'llamada',
            'vendedor_id' => $this->vendedor->id,
            'entidad_type' => CrmCliente::class,
            'entidad_id' => $cliente->id,
            'duracion_minutos' => 4,
        ]);
    }

    public function test_llamada_con_vendedor_pero_sin_contacto_crea_actividad_sin_entidad(): void
    {
        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([
                'items' => [$this->llamadaFake(['call_id' => 'call-2'])],
                'cursor' => null,
            ], 200),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $this->assertDatabaseHas('crm_actividades', [
            'dialpad_call_id' => 'call-2',
            'entidad_type' => null,
            'entidad_id' => null,
        ]);
    }

    public function test_llamada_sin_vendedor_en_ninguna_empresa_se_omite(): void
    {
        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([
                'items' => [$this->llamadaFake([
                    'call_id' => 'call-3',
                    'target' => ['email' => 'nadie@example.com'],
                ])],
                'cursor' => null,
            ], 200),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $this->assertDatabaseMissing('crm_actividades', ['dialpad_call_id' => 'call-3']);
    }

    public function test_resync_de_llamada_ya_clasificada_manualmente_no_pisa_la_clasificacion(): void
    {
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Cliente Fake',
            'telefono' => '6621234567',
            'estatus' => 'activo',
        ]);

        $actividad = CrmActividad::create([
            'empresa_id' => $this->enterprise->id,
            'tipo' => 'llamada',
            'entidad_type' => CrmCliente::class,
            'entidad_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id,
            'descripcion' => 'Descripción original clasificada',
            'fecha_actividad' => now()->subHour(),
            'duracion_minutos' => 2,
            'resultado' => 'Cliente interesado',
            'fuente' => 'dialpad',
            'dialpad_call_id' => 'call-4',
        ]);

        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([
                'items' => [$this->llamadaFake(['call_id' => 'call-4', 'duration' => 600000])],
                'cursor' => null,
            ], 200),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $actividad->refresh();
        $this->assertEquals('Descripción original clasificada', $actividad->descripcion);
        $this->assertEquals(2, $actividad->duracion_minutos);
        $this->assertEquals('Cliente interesado', $actividad->resultado);
    }

    public function test_resync_de_llamada_sin_clasificar_actualiza_descripcion_y_duracion(): void
    {
        $actividad = CrmActividad::create([
            'empresa_id' => $this->enterprise->id,
            'tipo' => 'llamada',
            'vendedor_id' => $this->vendedor->id,
            'descripcion' => 'Llamada entrante de Dialpad (número desconocido) — 2 min',
            'fecha_actividad' => now()->subHour(),
            'duracion_minutos' => 2,
            'fuente' => 'dialpad',
            'dialpad_call_id' => 'call-5',
        ]);

        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([
                'items' => [$this->llamadaFake(['call_id' => 'call-5', 'duration' => 600000])],
                'cursor' => null,
            ], 200),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $actividad->refresh();
        $this->assertEquals(10, $actividad->duracion_minutos);
        $this->assertStringContainsString('10 min', $actividad->descripcion);
    }

    public function test_rate_limit_corta_la_corrida_sin_avanzar_el_cursor(): void
    {
        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([], 429),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $estado = CrmDialpadSyncEstado::obtenerSingleton();
        $this->assertNull($estado->ultimo_sync_at);
        $this->assertNull($estado->ultimo_call_id_sincronizado);
        $this->assertDatabaseCount('crm_actividades', 0);
    }

    public function test_api_key_invalida_falla_el_comando_completo_y_guarda_el_error(): void
    {
        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(1);

        $estado = CrmDialpadSyncEstado::obtenerSingleton();
        $this->assertNotNull($estado->ultimo_error);
    }

    public function test_los_contadores_de_la_corrida_quedan_en_cache_para_el_disparo_manual(): void
    {
        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([
                'items' => [
                    $this->llamadaFake(['call_id' => 'call-6']),
                    $this->llamadaFake(['call_id' => 'call-7', 'target' => ['email' => 'nadie@example.com']]),
                ],
                'cursor' => null,
            ], 200),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $contadores = Cache::get(SincronizarDialpadCommand::CACHE_ULTIMA_CORRIDA);
        $this->assertEquals(['sincronizadas' => 1, 'omitidas' => 1], $contadores);
    }
}
