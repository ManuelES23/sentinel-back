<?php
// tests/Feature/CRM/SincronizarOutlookCommandTest.php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmOutlookConexion;
use App\Models\CRM\CrmOutlookEventoMapeado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class SincronizarOutlookCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    private function crearConexion(array $overrides = []): CrmOutlookConexion
    {
        return CrmOutlookConexion::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'crm_vendedor_id' => $this->vendedor->id,
            'email_outlook' => 'vendedor@outlook.com',
            'access_token' => 'access-token-vigente',
            'refresh_token' => 'refresh-token-vigente',
            'token_expires_at' => now()->addHour(),
        ], $overrides));
    }

    private function crearEvento(array $overrides = []): CrmAgenda
    {
        return CrmAgenda::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'tipo' => 'tarea',
            'titulo' => 'Llamar a cliente',
            'fecha_inicio' => now()->addDay(),
            'fecha_fin' => now()->addDay()->addHour(),
            'completado' => false,
        ], $overrides));
    }

    public function test_evento_nuevo_se_crea_en_outlook(): void
    {
        $conexion = $this->crearConexion();
        $evento = $this->crearEvento();

        Http::fake([
            'graph.microsoft.com/v1.0/me/events' => Http::response(['id' => 'outlook-evt-1'], 201),
        ]);

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        $this->assertDatabaseHas('crm_outlook_eventos_mapeados', [
            'crm_agenda_id' => $evento->id,
            'crm_outlook_conexion_id' => $conexion->id,
            'outlook_event_id' => 'outlook-evt-1',
        ]);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/me/events'));
    }

    public function test_evento_editado_se_actualiza_con_patch(): void
    {
        $conexion = $this->crearConexion();
        $evento = $this->crearEvento();

        CrmOutlookEventoMapeado::create([
            'crm_agenda_id' => $evento->id,
            'crm_outlook_conexion_id' => $conexion->id,
            'outlook_event_id' => 'outlook-evt-1',
            'ultima_actualizacion_enviada_at' => now()->subDay(), // anterior a updated_at del evento
        ]);

        Http::fake([
            'graph.microsoft.com/v1.0/me/events/outlook-evt-1' => Http::response(['id' => 'outlook-evt-1'], 200),
        ]);

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_contains($request->url(), '/me/events/outlook-evt-1'));
    }

    public function test_evento_borrado_en_sentinel_se_borra_en_outlook_y_el_mapeo_desaparece(): void
    {
        $conexion = $this->crearConexion();
        $evento = $this->crearEvento();

        $mapeo = CrmOutlookEventoMapeado::create([
            'crm_agenda_id' => $evento->id,
            'crm_outlook_conexion_id' => $conexion->id,
            'outlook_event_id' => 'outlook-evt-1',
            'ultima_actualizacion_enviada_at' => now(),
        ]);

        $evento->delete(); // nullOnDelete deja crm_agenda_id = null en el mapeo

        Http::fake([
            'graph.microsoft.com/v1.0/me/events/outlook-evt-1' => Http::response([], 204),
        ]);

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        $this->assertDatabaseMissing('crm_outlook_eventos_mapeados', ['id' => $mapeo->id]);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), '/me/events/outlook-evt-1'));
    }

    public function test_evento_completado_no_genera_ninguna_llamada_a_graph(): void
    {
        $this->crearConexion();
        $this->crearEvento(['completado' => true]);

        Http::fake(); // cualquier request no esperado hace fallar assertNothingSent

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_un_fallo_de_conexion_no_detiene_el_procesamiento_de_las_demas(): void
    {
        $conexionRota = $this->crearConexion(['token_expires_at' => now()->subHour()]); // fuerza refresh
        $otroVendedorUser = \App\Models\User::factory()->create();
        $otroVendedor = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $otroVendedorUser->id,
            'nombre' => 'Otro vendedor',
        ]);
        $conexionSana = $this->crearConexion([
            'crm_vendedor_id' => $otroVendedor->id,
            'email_outlook' => 'otro@outlook.com',
        ]);
        $eventoDelSano = $this->crearEvento(['vendedor_id' => $otroVendedor->id]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400),
            'graph.microsoft.com/v1.0/me/events' => Http::response(['id' => 'outlook-evt-sano'], 201),
        ]);

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        $conexionRota->refresh();
        $this->assertNotNull($conexionRota->ultimo_error);

        $this->assertDatabaseHas('crm_outlook_eventos_mapeados', [
            'crm_agenda_id' => $eventoDelSano->id,
            'crm_outlook_conexion_id' => $conexionSana->id,
        ]);
    }

    public function test_token_expirado_se_refresca_antes_de_sincronizar(): void
    {
        $conexion = $this->crearConexion(['token_expires_at' => now()->subMinute()]);
        $this->crearEvento();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'access-token-refrescado',
                'refresh_token' => 'refresh-token-refrescado',
                'expires_in' => 3600,
            ], 200),
            'graph.microsoft.com/v1.0/me/events' => Http::response(['id' => 'outlook-evt-1'], 201),
        ]);

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        $conexion->refresh();
        $this->assertEquals('access-token-refrescado', $conexion->access_token);
        $this->assertEquals('refresh-token-refrescado', $conexion->refresh_token);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'login.microsoftonline.com')
            && ($request['grant_type'] ?? null) === 'refresh_token');
    }

    public function test_rate_limit_429_no_interrumpe_el_resto_del_lote(): void
    {
        $this->crearConexion();
        $evento1 = $this->crearEvento(['titulo' => 'Evento 1']);
        $evento2 = $this->crearEvento(['titulo' => 'Evento 2']);

        Http::fake([
            'graph.microsoft.com/v1.0/me/events' => Http::sequence()
                ->push(['error' => 'rate limited'], 429)
                ->push(['id' => 'outlook-evt-2'], 201),
        ]);

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        // El primero se saltó (429), el segundo sí se creó -- el lote no se detuvo.
        $this->assertDatabaseMissing('crm_outlook_eventos_mapeados', ['crm_agenda_id' => $evento1->id]);
        $this->assertDatabaseHas('crm_outlook_eventos_mapeados', ['crm_agenda_id' => $evento2->id]);
    }
}
