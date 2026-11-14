<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\User;
use App\Models\VacationOutlookConnection;
use App\Models\VacationOutlookEvent;
use App\Models\VacationRequest;
use App\Services\RH\OutlookVacaciones;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * Mi Perfil: vincular, sincronizar y desvincular el Outlook de quien autoriza vacaciones, y el callback
 * público compartido con el CRM (reparte por `proposito` del nonce).
 */
class OutlookVacacionesApiTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const BASE = '/api/profile/outlook-vacaciones';

    private const CALLBACK = '/api/crm/integraciones/outlook/callback';

    private User $director;

    private User $ajeno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();

        $this->director = $this->empleadoConUsuario($this->corporativo)->user;
        $this->ajeno = $this->empleadoConUsuario($this->empresa)->user;
        $this->regla(1, ApprovalFlowStep::TYPE_USER, ['user_id' => $this->director->id]);
    }

    private function conexion(?User $usuario = null): VacationOutlookConnection
    {
        return VacationOutlookConnection::create([
            'user_id' => ($usuario ?? $this->director)->id,
            'email_outlook' => 'director@outlook.com',
            'access_token' => 'access-secreto-123',
            'refresh_token' => 'refresh-secreto-456',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function simularSocialite(string $correo = 'director@outlook.com', string $id = 'ms-id-123'): void
    {
        $socialiteUser = new SocialiteUser();
        $socialiteUser->token = 'access-token-fake';
        $socialiteUser->refreshToken = 'refresh-token-fake';
        $socialiteUser->expiresIn = 3600;
        $socialiteUser->id = $id;
        $socialiteUser->email = $correo;

        $proveedor = \Mockery::mock(AbstractProvider::class);
        $proveedor->shouldReceive('stateless')->once()->andReturnSelf();
        $proveedor->shouldReceive('user')->once()->andReturn($socialiteUser);
        Socialite::shouldReceive('driver')->once()->with('microsoft')->andReturn($proveedor);
    }

    private function nonceVacaciones(string $nonce, ?User $usuario = null): void
    {
        Cache::put('outlook_connect_nonce:'.$nonce, [
            'user_id' => ($usuario ?? $this->director)->id,
            'proposito' => 'vacaciones',
        ], now()->addMinutes(10));
    }

    public function test_estado_del_director_sin_conexion_dice_disponible(): void
    {
        Sanctum::actingAs($this->director);

        $this->getJson(self::BASE)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.disponible', true)
            ->assertJsonPath('data.conectado', false)
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.agendadas', []);
    }

    public function test_estado_de_un_usuario_ajeno_no_esta_disponible(): void
    {
        Sanctum::actingAs($this->ajeno);

        $this->getJson(self::BASE)
            ->assertOk()
            ->assertJsonPath('data.disponible', false)
            ->assertJsonPath('data.conectado', false);
    }

    public function test_estado_con_conexion_nunca_trae_tokens(): void
    {
        $conexion = $this->conexion();
        $conexion->update(['ultimo_sync_at' => now(), 'ultimo_error' => 'Algo pasó']);
        $solicitud = $this->solicitud($this->empleadoConUsuario($this->empresa), 'approved', 3);
        VacationOutlookEvent::create([
            'vacation_outlook_connection_id' => $conexion->id,
            'vacation_request_id' => $solicitud->id,
            'outlook_event_id' => 'evt-1',
            'enviado_at' => now(),
        ]);
        Sanctum::actingAs($this->director);

        $respuesta = $this->getJson(self::BASE)->assertOk();

        $respuesta->assertJsonPath('data.conectado', true)
            ->assertJsonPath('data.email', 'director@outlook.com')
            ->assertJsonPath('data.ultimo_error', 'Algo pasó')
            ->assertJsonCount(1, 'data.agendadas');
        $this->assertNotNull($respuesta->json('data.ultimo_sync'));
        $texto = $respuesta->getContent();
        foreach (['access-secreto-123', 'refresh-secreto-456', 'access_token', 'refresh_token'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $texto);
        }
    }

    public function test_un_ajeno_que_ya_tenia_conexion_sigue_viendola_disponible(): void
    {
        $this->conexion($this->ajeno);
        Sanctum::actingAs($this->ajeno);

        $this->getJson(self::BASE)->assertOk()
            ->assertJsonPath('data.disponible', true)
            ->assertJsonPath('data.conectado', true);
    }

    public function test_conectar_responde_403_a_un_usuario_ajeno(): void
    {
        Sanctum::actingAs($this->ajeno);

        $this->getJson(self::BASE.'/conectar')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Solo quien autoriza vacaciones como usuario específico puede vincular Outlook aquí.');
    }

    public function test_conectar_devuelve_la_url_y_guarda_el_nonce_de_vacaciones(): void
    {
        Sanctum::actingAs($this->director);

        $guardado = null;
        $proveedor = \Mockery::mock(AbstractProvider::class);
        $proveedor->shouldReceive('stateless')->once()->andReturnSelf();
        $proveedor->shouldReceive('scopes')->once()->with(\Mockery::on(fn ($s) => in_array('offline_access', $s) && in_array('https://graph.microsoft.com/Calendars.ReadWrite', $s)))->andReturnSelf();
        $proveedor->shouldReceive('with')->once()->with(\Mockery::on(function ($p) use (&$guardado) {
            $guardado = $p['state'] ?? null;

            return $guardado !== null;
        }))->andReturnSelf();
        $proveedor->shouldReceive('redirect')->once()->andReturn(redirect()->away('https://login.microsoftonline.com/consent'));
        Socialite::shouldReceive('driver')->once()->with('microsoft')->andReturn($proveedor);

        $this->getJson(self::BASE.'/conectar')
            ->assertOk()
            ->assertJsonPath('data.url', 'https://login.microsoftonline.com/consent');

        $this->assertNotEmpty($guardado);
        $this->assertSame(
            ['user_id' => $this->director->id, 'proposito' => 'vacaciones'],
            Cache::get('outlook_connect_nonce:'.$guardado),
        );
    }

    public function test_callback_con_nonce_de_vacaciones_vincula_y_redirige_al_perfil(): void
    {
        $this->nonceVacaciones('nonce-vac');
        $this->simularSocialite();
        Http::fake(['*' => Http::response(['id' => 'evt-x'], 201)]);

        $respuesta = $this->get(self::CALLBACK.'?state=nonce-vac');

        $respuesta->assertRedirect();
        $this->assertStringEndsWith('/profile?tab=approvals&outlook=ok', $respuesta->headers->get('Location'));
        $this->assertDatabaseHas('vacation_outlook_connections', [
            'user_id' => $this->director->id,
            'email_outlook' => 'director@outlook.com',
        ]);
        $this->assertNull(Cache::get('outlook_connect_nonce:nonce-vac'));
    }

    public function test_callback_de_vacaciones_agenda_las_ya_aprobadas_con_la_primera_sincronizacion(): void
    {
        // El director aprueba una solicitud antes de vincular; al vincular se agenda sola.
        $solicitante = $this->empleadoConUsuario($this->empresa, ['first_name' => 'Ana', 'last_name' => 'Pérez']);
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);
        $servicio = app(VacationRequestService::class);
        $solicitud = $servicio->crear($solicitante, $lunes, $lunes->copy()->addDays(4), 'Descanso familiar largo', VacationRequest::SOURCE_PORTAL);
        $servicio->aprobarPaso($solicitud, $this->director);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'x', 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/me/events' => Http::response(['id' => 'evt-1'], 201),
            'graph.microsoft.com/v1.0/me/events/*' => Http::response(null, 204),
        ]);
        $this->nonceVacaciones('nonce-vac');
        $this->simularSocialite();

        $this->get(self::CALLBACK.'?state=nonce-vac')->assertRedirect();

        $this->assertDatabaseHas('vacation_outlook_events', ['vacation_request_id' => $solicitud->id, 'outlook_event_id' => 'evt-1']);
    }

    public function test_callback_de_vacaciones_con_socialite_fallando_redirige_a_error(): void
    {
        $this->nonceVacaciones('nonce-vac');
        $proveedor = \Mockery::mock(AbstractProvider::class);
        $proveedor->shouldReceive('stateless')->once()->andReturnSelf();
        $proveedor->shouldReceive('user')->once()->andThrow(new \RuntimeException('boom'));
        Socialite::shouldReceive('driver')->once()->with('microsoft')->andReturn($proveedor);

        $respuesta = $this->get(self::CALLBACK.'?state=nonce-vac');

        $this->assertStringEndsWith('/profile?tab=approvals&outlook=error', $respuesta->headers->get('Location'));
        $this->assertDatabaseCount('vacation_outlook_connections', 0);
    }

    public function test_callback_de_vacaciones_guarda_el_id_de_la_cuenta_de_microsoft(): void
    {
        $this->nonceVacaciones('nonce-vac');
        $this->simularSocialite('director@outlook.com', 'ms-id-123');
        Http::fake(['*' => Http::response(['id' => 'evt-x'], 201)]);

        $this->get(self::CALLBACK.'?state=nonce-vac')->assertRedirect();

        $this->assertDatabaseHas('vacation_outlook_connections', ['user_id' => $this->director->id, 'microsoft_user_id' => 'ms-id-123']);
    }

    public function test_callback_de_vacaciones_con_otra_cuenta_de_microsoft_limpia_los_eventos_de_la_anterior(): void
    {
        $conexion = $this->conexion();
        $conexion->update(['microsoft_user_id' => 'ms-vieja']);
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'approved', 5);
        $conexion->eventos()->create(['vacation_request_id' => $solicitud->id, 'outlook_event_id' => 'evt-viejo', 'enviado_at' => now()]);
        Http::fake(['*' => Http::response(null, 204)]);
        $this->nonceVacaciones('nonce-vac');
        $this->simularSocialite('otra@outlook.com', 'ms-nueva');

        $this->get(self::CALLBACK.'?state=nonce-vac')->assertRedirect();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://graph.microsoft.com/v1.0/me/events/evt-viejo'
            && $request->hasHeader('Authorization', 'Bearer access-secreto-123'));
        $this->assertDatabaseCount('vacation_outlook_events', 0);
        $this->assertDatabaseHas('vacation_outlook_connections', ['user_id' => $this->director->id, 'microsoft_user_id' => 'ms-nueva', 'email_outlook' => 'otra@outlook.com']);
    }

    public function test_si_socialite_falla_en_el_callback_de_vacaciones_el_log_lleva_la_clase_y_el_usuario_sin_el_mensaje(): void
    {
        $registros = $this->capturarLog();
        $this->nonceVacaciones('nonce-vac');
        $proveedor = \Mockery::mock(AbstractProvider::class);
        $proveedor->shouldReceive('stateless')->once()->andReturnSelf();
        $proveedor->shouldReceive('user')->once()->andThrow(new \RuntimeException('SECRET-TOKEN boom'));
        Socialite::shouldReceive('driver')->once()->with('microsoft')->andReturn($proveedor);

        $this->get(self::CALLBACK.'?state=nonce-vac')->assertRedirect();

        $this->assertLogSinSecreto($registros, "usuario #{$this->director->id}");
        $this->assertTrue(collect($registros)->contains(fn ($r) => str_contains($r, 'RuntimeException')), 'El log debe llevar la clase de la excepción.');
    }

    public function test_callback_de_vacaciones_sin_disponibilidad_redirige_a_error_sin_vincular(): void
    {
        // Entre conectar y el callback dejó de ser «usuario específico».
        $this->nonceVacaciones('nonce-vac', $this->ajeno);

        $respuesta = $this->get(self::CALLBACK.'?state=nonce-vac');

        $this->assertStringEndsWith('/profile?tab=approvals&outlook=error', $respuesta->headers->get('Location'));
        $this->assertDatabaseCount('vacation_outlook_connections', 0);
    }

    public function test_callback_con_nonce_del_crm_no_toca_las_conexiones_de_vacaciones(): void
    {
        Cache::put('outlook_connect_nonce:nonce-crm', [
            'user_id' => $this->director->id,
            'empresa_id' => $this->empresa->id,
        ], now()->addMinutes(10));
        $this->simularSocialite();

        $respuesta = $this->get(self::CALLBACK.'?state=nonce-crm');

        $respuesta->assertRedirect();
        $this->assertStringContainsString('outlook=error', $respuesta->headers->get('Location')); // sin perfil de vendedor
        $this->assertStringNotContainsString('/profile?tab=approvals', $respuesta->headers->get('Location'));
        $this->assertDatabaseCount('vacation_outlook_connections', 0);
    }

    public function test_callback_con_nonce_desconocido_redirige_a_error(): void
    {
        $respuesta = $this->get(self::CALLBACK.'?state=no-existe');

        $respuesta->assertRedirect();
        $this->assertStringContainsString('outlook=error', $respuesta->headers->get('Location'));
        $this->assertDatabaseCount('vacation_outlook_connections', 0);
    }

    public function test_sincronizar_sin_conexion_responde_404(): void
    {
        Sanctum::actingAs($this->director);

        $this->postJson(self::BASE.'/sincronizar')
            ->assertStatus(404)
            ->assertJsonPath('message', 'No tienes Outlook vinculado.');
    }

    public function test_sincronizar_con_conexion_corre_y_devuelve_el_estado(): void
    {
        $conexion = $this->conexion();
        Http::fake(['*' => Http::response(null, 204)]);
        Sanctum::actingAs($this->director);

        $respuesta = $this->postJson(self::BASE.'/sincronizar')->assertOk();

        $respuesta->assertJsonPath('data.conectado', true)->assertJsonPath('data.email', 'director@outlook.com');
        $this->assertNotNull($conexion->fresh()->ultimo_sync_at);
        $this->assertStringNotContainsString('access-secreto-123', $respuesta->getContent());
    }

    public function test_desconectar_borra_la_conexion_y_sus_mapeos(): void
    {
        $conexion = $this->conexion();
        $solicitud = $this->solicitud($this->empleadoConUsuario($this->empresa), 'approved', 3);
        VacationOutlookEvent::create([
            'vacation_outlook_connection_id' => $conexion->id,
            'vacation_request_id' => $solicitud->id,
            'outlook_event_id' => 'evt-1',
            'enviado_at' => now(),
        ]);
        Http::fake(['*' => Http::response(null, 204)]);
        Sanctum::actingAs($this->director);

        $this->deleteJson(self::BASE)->assertOk()->assertJsonPath('message', 'Outlook desvinculado.');

        $this->assertDatabaseCount('vacation_outlook_connections', 0);
        $this->assertDatabaseCount('vacation_outlook_events', 0);
    }

    public function test_desconectar_sin_conexion_tambien_responde_200(): void
    {
        Sanctum::actingAs($this->ajeno);

        $this->deleteJson(self::BASE)->assertOk()->assertJsonPath('message', 'Outlook desvinculado.');
    }

    /** Escucha todo lo que se registra en el log (mensaje + contexto serializado) durante la prueba. */
    private function capturarLog(): \ArrayObject
    {
        $registros = new \ArrayObject();
        Event::listen(MessageLogged::class, function (MessageLogged $e) use ($registros) {
            $registros[] = $e->message.' '.json_encode($e->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });

        return $registros;
    }

    private function assertLogSinSecreto(\ArrayObject $registros, string $pista): void
    {
        $this->assertTrue(
            collect($registros)->contains(fn ($r) => str_contains($r, $pista)),
            'Se esperaba un registro con «'.$pista.'»: '.json_encode($registros->getArrayCopy()),
        );
        foreach ($registros as $registro) {
            $this->assertStringNotContainsString('SECRET-TOKEN', $registro);
        }
    }

    private function excepcionConSecreto(): QueryException
    {
        return new QueryException('sqlite', 'insert into t (access_token) values (?)', ['SECRET-TOKEN'], new \PDOException('SECRET-TOKEN duplicado'));
    }

    public function test_si_vincular_falla_por_la_base_el_log_no_lleva_el_mensaje_ni_los_bindings(): void
    {
        VacationOutlookConnection::creating(function () {
            throw $this->excepcionConSecreto();
        });
        $registros = $this->capturarLog();
        $this->nonceVacaciones('nonce-vac');
        $this->simularSocialite();

        $respuesta = $this->get(self::CALLBACK.'?state=nonce-vac');

        $this->assertStringEndsWith('/profile?tab=approvals&outlook=error', $respuesta->headers->get('Location'));
        $this->assertDatabaseCount('vacation_outlook_connections', 0);
        $this->assertLogSinSecreto($registros, "usuario #{$this->director->id}");
    }

    public function test_si_la_primera_sincronizacion_falla_el_log_no_lleva_el_mensaje(): void
    {
        $this->app->bind(OutlookVacaciones::class, fn () => new class extends OutlookVacaciones
        {
            public function sincronizar(VacationOutlookConnection $c): array
            {
                throw new QueryException('sqlite', 'update t set access_token = ?', ['SECRET-TOKEN'], new \PDOException('SECRET-TOKEN'));
            }
        });
        $registros = $this->capturarLog();
        $this->nonceVacaciones('nonce-vac');
        $this->simularSocialite();

        $respuesta = $this->get(self::CALLBACK.'?state=nonce-vac');
        app(DeferredCallbackCollection::class)->invoke();

        $this->assertStringEndsWith('outlook=ok', $respuesta->headers->get('Location'));
        $this->assertLogSinSecreto($registros, 'tras vincular');
    }

    public function test_si_el_defer_de_aprobar_falla_el_log_no_lleva_el_mensaje(): void
    {
        $this->app->bind(OutlookVacaciones::class, fn () => new class extends OutlookVacaciones
        {
            public function sincronizarSolicitud(VacationRequest $s): void
            {
                throw new QueryException('sqlite', 'insert into t (access_token) values (?)', ['SECRET-TOKEN'], new \PDOException('SECRET-TOKEN'));
            }
        });
        $registros = $this->capturarLog();
        $solicitante = $this->empleadoConUsuario($this->empresa);
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);
        $servicio = app(VacationRequestService::class);
        $solicitud = $servicio->crear($solicitante, $lunes, $lunes->copy()->addDays(4), 'Descanso familiar largo', VacationRequest::SOURCE_PORTAL);

        $servicio->aprobarPaso($solicitud, $this->director);
        app(DeferredCallbackCollection::class)->invoke();

        $this->assertLogSinSecreto($registros, "solicitud de vacaciones {$solicitud->id}");
    }

    public function test_las_rutas_exigen_sesion(): void
    {
        $this->getJson(self::BASE)->assertStatus(401);
        $this->deleteJson(self::BASE)->assertStatus(401);
    }
}
