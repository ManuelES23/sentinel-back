<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationOutlookConnection;
use App\Models\VacationOutlookEvent;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\RH\OutlookVacaciones;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * Las vacaciones que aprobó quien autoriza (usuario específico) se agendan en su Outlook como eventos de
 * día completo y se borran al cancelarse. Cadena: 1 jefe directo, 2 visto bueno de RH, 3 director.
 */
class OutlookVacacionesSincronizacionTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const EVENTOS = 'https://graph.microsoft.com/v1.0/me/events';

    private const MOTIVO = 'Boda de mi hermana en Cancún';

    private Employee $jefe;

    private Employee $solicitante;

    private Employee $directorEmpleado;

    private User $director;

    private User $rh;

    private int $semana = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();

        $depto = $this->departamento($this->empresa, 'COS');
        $puesto = $this->puesto($this->empresa, 'Supervisor de cosecha');

        $this->jefe = $this->empleadoConUsuario($this->empresa);
        $this->solicitante = $this->empleadoConUsuario($this->empresa, [
            'first_name' => 'Ana', 'last_name' => 'Pérez', 'reports_to' => $this->jefe->id,
            'department_id' => $depto->id, 'position_id' => $puesto->id,
        ]);
        $this->rh = $this->empleadoConUsuario($this->corporativo)->user;
        $this->concederVacaciones($this->rh, 'aprobar');
        $this->directorEmpleado = $this->empleadoConUsuario($this->corporativo);
        $this->director = $this->directorEmpleado->user;

        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->regla(2, ApprovalFlowStep::TYPE_RH_REVIEW);
        $this->regla(3, ApprovalFlowStep::TYPE_USER, ['user_id' => $this->director->id]);
    }

    private function servicio(): VacationRequestService
    {
        return app(VacationRequestService::class);
    }

    private function outlook(): OutlookVacaciones
    {
        return app(OutlookVacaciones::class);
    }

    /** Solicitud nueva de 5 días en una semana distinta cada vez (sin empalmes), en el paso del jefe. */
    private function nueva(?Employee $empleado = null): VacationRequest
    {
        $lunes = today()->next(Carbon::MONDAY)->addWeeks($this->semana++);

        return $this->servicio()->crear($empleado ?? $this->solicitante, $lunes, $lunes->copy()->addDays(4), self::MOTIVO, VacationRequest::SOURCE_PORTAL);
    }

    private function enPasoDelDirector(?Employee $empleado = null): VacationRequest
    {
        $solicitud = $this->servicio()->aprobarPaso($this->nueva($empleado), $this->jefe->user);

        return $this->servicio()->darVistoBueno($solicitud, $this->rh, 'Va bien de saldo');
    }

    private function aprobadaPorElDirector(?Employee $empleado = null): VacationRequest
    {
        return $this->servicio()->aprobarPaso($this->enPasoDelDirector($empleado), $this->director);
    }

    private function conexion(?User $usuario = null, array $atributos = []): VacationOutlookConnection
    {
        return VacationOutlookConnection::create(array_merge([
            'user_id' => ($usuario ?? $this->director)->id,
            'email_outlook' => 'director@outlook.com',
            'access_token' => 'access-vigente',
            'refresh_token' => 'refresh-vigente',
            'token_expires_at' => now()->addHour(),
        ], $atributos));
    }

    private function fakeGraph(int $estadoCrear = 201): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'access-nuevo', 'refresh_token' => 'refresh-nuevo', 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/me/events' => Http::sequence()
                ->push(['id' => 'evt-1'], $estadoCrear)->push(['id' => 'evt-2'], $estadoCrear)->push(['id' => 'evt-3'], $estadoCrear),
            'graph.microsoft.com/v1.0/me/events/*' => Http::response(null, 204),
        ]);
    }

    private function posts(): int
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST' && $request->url() === self::EVENTOS)->count();
    }

    public function test_agenda_las_aprobadas_por_el_usuario_como_evento_de_dia_completo(): void
    {
        $solicitud = $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        $this->fakeGraph();

        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(['creados' => 1, 'borrados' => 0], $resultado);
        Http::assertSent(function ($request) use ($solicitud, $conexion) {
            if ($request->method() !== 'POST' || $request->url() !== self::EVENTOS) {
                return false;
            }
            $datos = $request->data();
            $this->assertSame('Vacaciones · Ana Pérez', $datos['subject']);
            $this->assertTrue($datos['isAllDay']);
            $this->assertSame('free', $datos['showAs']);
            $this->assertFalse($datos['isReminderOn']);
            $this->assertSame($solicitud->start_date->format('Y-m-d').'T00:00:00', $datos['start']['dateTime']);
            $this->assertSame($solicitud->end_date->copy()->addDay()->format('Y-m-d').'T00:00:00', $datos['end']['dateTime']);
            $this->assertSame('America/Mazatlan', $datos['start']['timeZone']);
            $this->assertSame("sentinel-vac-{$conexion->id}-{$solicitud->id}", $datos['transactionId']);
            $this->assertStringContainsString('Splendid Farms · Supervisor de cosecha · Depto COS', $datos['body']['content']);
            $this->assertStringContainsString('· 5 días', $datos['body']['content']);
            $this->assertStringContainsString('Folio VAC-'.str_pad((string) $solicitud->id, 6, '0', STR_PAD_LEFT), $datos['body']['content']);
            $this->assertStringNotContainsString(self::MOTIVO, json_encode($datos));
            $this->assertStringNotContainsString('Va bien de saldo', json_encode($datos));

            return true;
        });
        $this->assertSame(1, VacationOutlookEvent::count());
        $this->assertDatabaseHas('vacation_outlook_events', [
            'vacation_outlook_connection_id' => $conexion->id,
            'vacation_request_id' => $solicitud->id,
            'outlook_event_id' => 'evt-1',
        ]);
        $this->assertNull($conexion->fresh()->ultimo_error);
        $this->assertNotNull($conexion->fresh()->ultimo_sync_at);
    }

    public function test_la_fecha_del_cuerpo_usa_meses_en_espanol(): void
    {
        $solicitud = $this->aprobadaPorElDirector();
        $solicitud->update(['start_date' => '2026-11-20', 'end_date' => '2026-11-24']);

        $cuerpo = OutlookVacaciones::evento($solicitud->fresh(), 7)['body']['content'];

        $this->assertStringContainsString('Del 20 nov 2026 al 24 nov 2026 · 5 días', $cuerpo);
    }

    public function test_no_agenda_las_que_no_aprobo_las_pendientes_ni_las_ya_pasadas(): void
    {
        $conexion = $this->conexion();
        $this->fakeGraph();

        // Pendiente: le toca al director pero aún no decide.
        $this->enPasoDelDirector();

        // Ya autorizada capturada por RH: no la aprobó el director.
        $otro = $this->empleadoConUsuario($this->empresa);
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(9);
        $capturada = $this->servicio()->capturarAutorizada($otro, $lunes, $lunes->copy()->addDays(4), 'Ya autorizada', $this->rh);
        $this->assertSame('approved', $capturada->status);

        // Aprobada por el director pero que ya terminó.
        $pasada = $this->solicitud($this->empleado($this->empresa), 'approved', 3, [
            'start_date' => today()->subDays(10)->toDateString(), 'end_date' => today()->subDays(8)->toDateString(),
        ]);
        $pasada->approvals()->create([
            'step_order' => 3, 'description' => 'Director', 'rules' => [], 'is_rh_final' => false,
            'decision' => VacationRequestApproval::APPROVED, 'decided_by' => $this->director->id, 'decided_at' => now()->subDays(20),
        ]);

        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(['creados' => 0, 'borrados' => 0], $resultado);
        $this->assertSame(0, $this->posts());
        $this->assertSame(0, VacationOutlookEvent::count());
    }

    public function test_no_duplica_si_ya_esta_agendada(): void
    {
        $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        $this->fakeGraph();

        $this->outlook()->sincronizar($conexion);
        $segunda = $this->outlook()->sincronizar($conexion);

        $this->assertSame(['creados' => 0, 'borrados' => 0], $segunda);
        $this->assertSame(1, $this->posts());
        $this->assertSame(1, VacationOutlookEvent::count());
    }

    public function test_borra_en_outlook_la_cancelada_y_su_mapeo(): void
    {
        $cancelada = $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        $this->fakeGraph();
        $this->outlook()->sincronizar($conexion);

        $this->servicio()->cancelar($cancelada, VacationRequestService::CANCELA_RH);
        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(['creados' => 0, 'borrados' => 1], $resultado);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === self::EVENTOS.'/evt-1');
        $this->assertSame(0, VacationOutlookEvent::count());
    }

    public function test_un_404_de_graph_tambien_limpia_el_mapeo(): void
    {
        $cancelada = $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        Http::fake([
            'graph.microsoft.com/v1.0/me/events' => Http::response(['id' => 'evt-1'], 201),
            'graph.microsoft.com/v1.0/me/events/*' => Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404),
        ]);
        $this->outlook()->sincronizar($conexion);

        $this->servicio()->cancelar($cancelada, VacationRequestService::CANCELA_RH);
        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(1, $resultado['borrados']);
        $this->assertSame(0, VacationOutlookEvent::count());
        $this->assertNull($conexion->fresh()->ultimo_error);
    }

    public function test_la_borrada_se_quita_de_outlook_pero_la_que_ya_paso_se_conserva(): void
    {
        $borrada = $this->aprobadaPorElDirector();
        $pasada = $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        $this->fakeGraph();
        $this->outlook()->sincronizar($conexion);
        $this->assertSame(2, VacationOutlookEvent::count());

        $borrada->delete(); // soft delete
        $pasada->update(['start_date' => today()->subDays(9)->toDateString(), 'end_date' => today()->subDays(5)->toDateString()]);
        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(['creados' => 0, 'borrados' => 1], $resultado);
        $this->assertSame([$pasada->id], VacationOutlookEvent::pluck('vacation_request_id')->all());
    }

    public function test_renueva_el_token_vencido(): void
    {
        $this->aprobadaPorElDirector();
        $conexion = $this->conexion(null, ['token_expires_at' => now()->addMinutes(2)]);
        $this->fakeGraph();

        $this->outlook()->sincronizar($conexion);

        Http::assertSent(fn ($request) => $request->url() === 'https://login.microsoftonline.com/common/oauth2/v2.0/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-vigente'
            && $request['scope'] === 'https://graph.microsoft.com/Calendars.ReadWrite offline_access');
        Http::assertSent(fn ($request) => $request->url() === self::EVENTOS && $request->hasHeader('Authorization', 'Bearer access-nuevo'));
        $conexion = $conexion->fresh();
        $this->assertSame('access-nuevo', $conexion->access_token);
        $this->assertSame('refresh-nuevo', $conexion->refresh_token);
        $this->assertTrue($conexion->token_expires_at->isFuture());
    }

    public function test_token_que_no_se_renueva_guarda_el_error_y_no_llama_a_graph(): void
    {
        $this->aprobadaPorElDirector();
        $conexion = $this->conexion(null, ['token_expires_at' => now()->subMinute()]);
        Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(['creados' => 0, 'borrados' => 0], $resultado);
        $this->assertSame('No se pudo renovar el acceso a Outlook. Vuelve a vincular tu cuenta.', $conexion->fresh()->ultimo_error);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.microsoft.com'));
        $this->assertSame(0, VacationOutlookEvent::count());
    }

    public function test_un_429_no_detiene_los_demas_eventos(): void
    {
        $primera = $this->aprobadaPorElDirector();
        $segunda = $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        Http::fake([
            'graph.microsoft.com/v1.0/me/events' => Http::sequence()
                ->push(['error' => ['code' => 'TooManyRequests']], 429)
                ->push(['id' => 'evt-2'], 201)
                ->push(['id' => 'evt-3'], 201),
        ]);

        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(1, $resultado['creados']);
        $this->assertSame(2, $this->posts());
        $this->assertSame([$segunda->id], VacationOutlookEvent::pluck('vacation_request_id')->all());
        $this->assertNotNull($conexion->fresh()->ultimo_error, 'queda pendiente de reintento');

        // La siguiente corrida agenda la que quedó pendiente.
        $siguiente = $this->outlook()->sincronizar($conexion);
        $this->assertSame(1, $siguiente['creados']);
        $this->assertSame(2, VacationOutlookEvent::count());
        $this->assertNull($conexion->fresh()->ultimo_error);
        $this->assertSame($primera->id, VacationOutlookEvent::where('outlook_event_id', 'evt-3')->value('vacation_request_id'));
    }

    public function test_conflicto_del_indice_unico_borra_el_evento_sobrante_y_no_cuenta_como_fallo(): void
    {
        $solicitud = $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        Http::fake([
            // Otro proceso agenda la misma solicitud entre nuestro POST y el guardado del mapeo.
            'graph.microsoft.com/v1.0/me/events' => function () use ($conexion, $solicitud) {
                VacationOutlookEvent::create([
                    'vacation_outlook_connection_id' => $conexion->id,
                    'vacation_request_id' => $solicitud->id,
                    'outlook_event_id' => 'evt-otro-proceso',
                    'enviado_at' => now(),
                ]);

                return Http::response(['id' => 'evt-sobrante'], 201);
            },
            'graph.microsoft.com/v1.0/me/events/*' => Http::response(null, 204),
        ]);

        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(0, $resultado['creados']);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === self::EVENTOS.'/evt-sobrante');
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === self::EVENTOS.'/evt-otro-proceso');
        $this->assertSame(['evt-otro-proceso'], VacationOutlookEvent::pluck('outlook_event_id')->all());
        $this->assertNull($conexion->fresh()->ultimo_error);
        $this->assertNotNull($conexion->fresh()->ultimo_sync_at);
    }

    public function test_un_fallo_de_bd_distinto_al_guardar_el_mapeo_cuenta_como_fallo_y_borra_el_evento(): void
    {
        $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        $this->fakeGraph();
        VacationOutlookEvent::creating(fn () => throw new QueryException('mysql', 'insert into vacation_outlook_events', [], new \Exception('conexión perdida')));

        try {
            $resultado = $this->outlook()->sincronizar($conexion);
        } finally {
            VacationOutlookEvent::flushEventListeners();
        }

        $this->assertSame(0, $resultado['creados']);
        $this->assertSame(0, VacationOutlookEvent::count());
        // El evento recién creado no queda huérfano en Outlook.
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === self::EVENTOS.'/evt-1');
        $conexion = $conexion->fresh();
        $this->assertSame('Algunos eventos no se pudieron sincronizar con Outlook; se reintentará en la siguiente corrida.', $conexion->ultimo_error);
        $this->assertNull($conexion->ultimo_sync_at);
    }

    public function test_graph_2xx_sin_id_cuenta_como_fallo_y_no_guarda_mapeo_vacio(): void
    {
        $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        Http::fake(['graph.microsoft.com/v1.0/me/events' => Http::response([], 201)]);

        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(0, $resultado['creados']);
        $this->assertSame(0, VacationOutlookEvent::count());
        $conexion = $conexion->fresh();
        $this->assertSame('Algunos eventos no se pudieron sincronizar con Outlook; se reintentará en la siguiente corrida.', $conexion->ultimo_error);
        $this->assertNull($conexion->ultimo_sync_at);
    }

    public function test_si_el_candado_esta_tomado_no_hace_nada(): void
    {
        $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        $this->fakeGraph();
        $candado = Cache::lock("outlook-vacaciones:{$conexion->id}", 120);
        $this->assertTrue($candado->get());

        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(['creados' => 0, 'borrados' => 0], $resultado);
        Http::assertNothingSent();
        $candado->release();
    }

    public function test_sincronizar_solicitud_solo_toca_las_conexiones_de_quienes_aprobaron(): void
    {
        $solicitud = $this->aprobadaPorElDirector();
        $this->conexion();
        $ajeno = User::factory()->create();
        $this->conexion($ajeno, ['access_token' => 'access-ajeno', 'email_outlook' => 'ajeno@outlook.com']);
        $this->fakeGraph();

        $this->outlook()->sincronizarSolicitud($solicitud);

        $this->assertSame(1, $this->posts());
        Http::assertSent(fn ($request) => $request->url() === self::EVENTOS && $request->hasHeader('Authorization', 'Bearer access-vigente'));
        Http::assertNotSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer access-ajeno'));
        $this->assertSame(1, VacationOutlookEvent::count());
    }

    public function test_sincronizar_solicitud_quita_el_evento_de_la_cancelada_y_nunca_lanza(): void
    {
        $solicitud = $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        Http::fake([
            'graph.microsoft.com/v1.0/me/events' => Http::response(['id' => 'evt-1'], 201),
            'graph.microsoft.com/v1.0/me/events/*' => Http::response('boom', 500),
        ]);
        $this->outlook()->sincronizar($conexion);

        $this->servicio()->cancelar($solicitud, VacationRequestService::CANCELA_RH);
        $this->outlook()->sincronizarSolicitud($solicitud->fresh()); // Graph falla: no lanza

        $this->assertSame(1, VacationOutlookEvent::count(), 'sigue mapeado para reintentar');
        $this->assertNotNull($conexion->fresh()->ultimo_error);
    }

    public function test_aprobada_desde_el_portal_por_el_director_tambien_se_agenda(): void
    {
        $solicitud = $this->enPasoDelDirector();
        $this->conexion();
        $this->fakeGraph();

        // El director decide como Employee (portal): decided_by = employees.user_id.
        $solicitud = $this->servicio()->aprobarPaso($solicitud, $this->directorEmpleado);
        $this->assertSame('approved', $solicitud->status);
        $this->outlook()->sincronizarSolicitud($solicitud);

        $this->assertSame(1, $this->posts());
        $this->assertSame([$solicitud->id], VacationOutlookEvent::pluck('vacation_request_id')->all());
    }

    public function test_desvincular_borra_los_eventos_y_la_conexion_aunque_graph_falle(): void
    {
        $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        $this->fakeGraph();
        $this->outlook()->sincronizar($conexion);

        Http::fake(['*' => Http::response('boom', 500)]);
        $this->outlook()->desvincular($conexion->fresh());

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === self::EVENTOS.'/evt-1');
        $this->assertSame(0, VacationOutlookConnection::count());
        $this->assertSame(0, VacationOutlookEvent::count());
    }

    public function test_disponible_solo_para_usuario_especifico_o_con_conexion(): void
    {
        $this->assertTrue($this->outlook()->disponible($this->director));
        $this->assertFalse($this->outlook()->disponible($this->rh));
        $this->assertFalse($this->outlook()->disponible($this->jefe->user));

        // Ya tenía conexión (dejó de ser usuario específico): sigue pudiendo verla y desvincular.
        $this->conexion($this->rh);
        $this->assertTrue($this->outlook()->disponible($this->rh));

        // Una regla inactiva no cuenta.
        $otro = User::factory()->create();
        $this->regla(4, ApprovalFlowStep::TYPE_USER, ['user_id' => $otro->id, 'is_active' => false]);
        $this->assertFalse($this->outlook()->disponible($otro));
    }

    public function test_vincular_crea_o_actualiza_y_limpia_el_error(): void
    {
        $existente = $this->conexion(null, ['ultimo_error' => 'viejo']);

        $conexion = $this->outlook()->vincular($this->director->id, 'nuevo@outlook.com', 'tok-a', 'tok-r', 3600);

        $this->assertSame($existente->id, $conexion->id);
        $this->assertSame(1, VacationOutlookConnection::count());
        $this->assertNull($conexion->ultimo_error);
        $this->assertSame('nuevo@outlook.com', $conexion->email_outlook);
        $this->assertTrue($conexion->token_expires_at->isFuture());
    }

    /** Conexión con un evento ya agendado (evt-1) para una solicitud aprobada por el director. */
    private function conexionAgendada(array $atributos = []): VacationOutlookConnection
    {
        $this->aprobadaPorElDirector();
        $conexion = $this->conexion(null, $atributos);
        $this->fakeGraph();
        $this->outlook()->sincronizar($conexion);
        $this->assertSame(1, VacationOutlookEvent::count());

        return $conexion->fresh();
    }

    public function test_vincular_con_la_misma_cuenta_conserva_los_mapeos(): void
    {
        $this->conexionAgendada(['microsoft_user_id' => 'ms-1', 'email_outlook' => 'director@outlook.com']);
        Http::fake(['*' => Http::response(null, 204)]);

        $this->outlook()->vincular($this->director->id, 'director@outlook.com', 'tok-nuevo', 'ref-nuevo', 3600, 'ms-1');

        $this->assertSame(1, VacationOutlookEvent::count());
        Http::assertNothingSent();
        $this->assertSame('tok-nuevo', VacationOutlookConnection::first()->access_token);
        $this->assertSame('ms-1', VacationOutlookConnection::first()->microsoft_user_id);
    }

    public function test_vincular_con_otra_cuenta_borra_los_eventos_con_el_token_viejo_y_limpia_los_mapeos(): void
    {
        $conexion = $this->conexionAgendada(['microsoft_user_id' => 'ms-1', 'access_token' => 'access-viejo']);
        $conexion->update(['ultimo_error' => 'error viejo']);
        Http::fake(['*' => Http::response(null, 204)]);

        $nueva = $this->outlook()->vincular($this->director->id, 'otro@outlook.com', 'access-de-la-otra', 'ref-otra', 3600, 'ms-2');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === self::EVENTOS.'/evt-1'
            && $request->hasHeader('Authorization', 'Bearer access-viejo'));
        Http::assertNotSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer access-de-la-otra'));
        $this->assertSame(0, VacationOutlookEvent::count());
        $this->assertSame($conexion->id, $nueva->id);
        $nueva = $nueva->fresh();
        $this->assertSame('access-de-la-otra', $nueva->access_token);
        $this->assertSame('ms-2', $nueva->microsoft_user_id);
        $this->assertSame('otro@outlook.com', $nueva->email_outlook);
        $this->assertNull($nueva->ultimo_error);
    }

    public function test_tras_cambiar_de_cuenta_la_siguiente_sincronizacion_recrea_el_evento_en_el_calendario_nuevo(): void
    {
        $this->conexionAgendada(['microsoft_user_id' => 'ms-1']);
        Http::fake(['*' => Http::response(null, 204)]);
        $nueva = $this->outlook()->vincular($this->director->id, 'otro@outlook.com', 'access-de-la-otra', 'ref-otra', 3600, 'ms-2');
        $this->fakeGraph();

        $this->outlook()->sincronizar($nueva);

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === self::EVENTOS
            && $request->hasHeader('Authorization', 'Bearer access-de-la-otra'));
        $this->assertSame(1, VacationOutlookEvent::count());
    }

    public function test_sin_id_de_microsoft_compara_el_correo_sin_distinguir_mayusculas(): void
    {
        $this->conexionAgendada(['microsoft_user_id' => null, 'email_outlook' => 'Director@Outlook.com']);
        Http::fake(['*' => Http::response(null, 204)]);

        $this->outlook()->vincular($this->director->id, 'director@outlook.COM', 'tok-nuevo', 'ref', 3600, 'ms-9');

        $this->assertSame(1, VacationOutlookEvent::count());
        Http::assertNothingSent();
        $this->assertSame('ms-9', VacationOutlookConnection::first()->microsoft_user_id);
    }

    public function test_sin_id_de_microsoft_un_correo_distinto_cuenta_como_otra_cuenta(): void
    {
        $this->conexionAgendada(['microsoft_user_id' => null]);
        Http::fake(['*' => Http::response(null, 204)]);

        // Firma vieja (sin id): también compara por correo.
        $this->outlook()->vincular($this->director->id, 'otro@outlook.com', 'tok-nuevo', 'ref', 3600);

        $this->assertSame(0, VacationOutlookEvent::count());
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_si_el_id_nuevo_es_nulo_compara_el_correo(): void
    {
        $this->conexionAgendada(['microsoft_user_id' => 'ms-1']);
        Http::fake(['*' => Http::response(null, 204)]);

        $this->outlook()->vincular($this->director->id, 'director@outlook.com', 'tok-nuevo', 'ref', 3600, null);

        $this->assertSame(1, VacationOutlookEvent::count());
        Http::assertNothingSent();
    }

    public function test_un_correo_vacio_cuenta_como_cuenta_distinta(): void
    {
        $this->conexionAgendada(['microsoft_user_id' => null, 'email_outlook' => '']);
        Http::fake(['*' => Http::response(null, 204)]);

        $this->outlook()->vincular($this->director->id, '', 'tok-nuevo', 'ref', 3600);

        $this->assertSame(0, VacationOutlookEvent::count());
    }

    public function test_si_graph_falla_al_limpiar_al_cambiar_de_cuenta_igual_se_vincula(): void
    {
        $this->conexionAgendada(['microsoft_user_id' => 'ms-1']);
        Http::fake(['*' => Http::response('boom', 500)]);

        $nueva = $this->outlook()->vincular($this->director->id, 'otro@outlook.com', 'access-de-la-otra', 'ref', 3600, 'ms-2');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
        $this->assertSame(0, VacationOutlookEvent::count());
        $this->assertSame('access-de-la-otra', $nueva->fresh()->access_token);
        $this->assertSame('ms-2', $nueva->fresh()->microsoft_user_id);
    }

    public function test_si_graph_lanza_excepcion_al_limpiar_al_cambiar_de_cuenta_igual_se_vincula(): void
    {
        $this->conexionAgendada(['microsoft_user_id' => 'ms-1']);
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $nueva = $this->outlook()->vincular($this->director->id, 'otro@outlook.com', 'access-de-la-otra', 'ref', 3600, 'ms-2');

        $this->assertSame(0, VacationOutlookEvent::count());
        $this->assertSame('access-de-la-otra', $nueva->fresh()->access_token);
    }

    public function test_un_timeout_de_graph_cuenta_como_fallo_parcial_y_no_lanza(): void
    {
        $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        Http::fake(['graph.microsoft.com/*' => fn () => throw new ConnectionException('cURL error 28: timeout')]);

        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(['creados' => 0, 'borrados' => 0], $resultado);
        $this->assertSame(0, VacationOutlookEvent::count());
        $this->assertSame('Algunos eventos no se pudieron sincronizar con Outlook; se reintentará en la siguiente corrida.', $conexion->fresh()->ultimo_error);
    }

    public function test_un_timeout_al_renovar_el_token_guarda_el_error_y_no_lanza(): void
    {
        $this->aprobadaPorElDirector();
        $conexion = $this->conexion(null, ['token_expires_at' => now()->subMinute()]);
        Http::fake(['login.microsoftonline.com/*' => fn () => throw new ConnectionException('cURL error 28: timeout')]);

        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(['creados' => 0, 'borrados' => 0], $resultado);
        $this->assertSame(OutlookVacaciones::MENSAJE_TOKEN, $conexion->fresh()->ultimo_error);
    }

    public function test_todas_las_llamadas_a_microsoft_llevan_timeout_de_conexion_y_de_respuesta(): void
    {
        $this->aprobadaPorElDirector();
        $conexion = $this->conexion(null, ['token_expires_at' => now()->subMinute()]);
        $opciones = [];
        Http::fake(function ($request, $options) use (&$opciones) {
            $opciones[$request->method().' '.$request->url()] = [$options['connect_timeout'] ?? null, $options['timeout'] ?? null];

            return str_contains($request->url(), 'login.microsoftonline.com')
                ? Http::response(['access_token' => 'access-nuevo', 'refresh_token' => 'refresh-nuevo', 'expires_in' => 3600])
                : Http::response(['id' => 'evt-1'], 201);
        });

        $this->outlook()->sincronizar($conexion);
        // Y el borrado de los eventos del vínculo anterior.
        $this->outlook()->vincular($this->director->id, 'otro@outlook.com', 'tok', 'ref', 3600, 'ms-2');

        $this->assertArrayHasKey('POST https://login.microsoftonline.com/common/oauth2/v2.0/token', $opciones);
        $this->assertArrayHasKey('POST '.self::EVENTOS, $opciones);
        $this->assertArrayHasKey('DELETE '.self::EVENTOS.'/evt-1', $opciones);
        foreach ($opciones as $llamada => $tiempos) {
            $this->assertEquals([5, 10], $tiempos, "Sin timeout esperado en {$llamada}");
        }
    }

    public function test_una_instancia_vieja_dentro_del_candado_se_recarga_y_no_pisa_el_vinculo_nuevo(): void
    {
        $this->aprobadaPorElDirector();
        $vieja = $this->conexion(null, [
            'access_token' => 'access-viejo', 'refresh_token' => 'refresh-muerto', 'token_expires_at' => now()->subHour(),
        ]);
        // El usuario se vincula de nuevo mientras el comando ya cargó la instancia vieja.
        VacationOutlookConnection::whereKey($vieja->id)->first()->update([
            'access_token' => 'access-fresco', 'refresh_token' => 'refresh-fresco', 'token_expires_at' => now()->addHour(),
        ]);
        $this->fakeGraph();

        $resultado = $this->outlook()->sincronizar($vieja);

        $this->assertSame(['creados' => 1, 'borrados' => 0], $resultado);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'login.microsoftonline.com'));
        Http::assertSent(fn ($request) => $request->url() === self::EVENTOS && $request->hasHeader('Authorization', 'Bearer access-fresco'));
        $this->assertNull($vieja->fresh()->ultimo_error);
        $this->assertSame('access-fresco', $vieja->fresh()->access_token);
    }

    public function test_si_la_conexion_ya_no_existe_dentro_del_candado_no_hace_nada(): void
    {
        $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        VacationOutlookConnection::whereKey($conexion->id)->delete();
        $this->fakeGraph();

        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(['creados' => 0, 'borrados' => 0], $resultado);
        Http::assertNothingSent();
    }

    public function test_el_comando_sincroniza_todas_y_sigue_si_una_falla(): void
    {
        $primera = $this->conexion();
        $segunda = $this->conexion($this->rh, ['email_outlook' => 'rh@outlook.com']);
        $this->mock(OutlookVacaciones::class, function ($mock) use ($primera) {
            $mock->shouldReceive('sincronizar')->twice()->andReturnUsing(function ($conexion) use ($primera) {
                if ($conexion->id === $primera->id) {
                    throw new \RuntimeException('boom');
                }

                return ['creados' => 0, 'borrados' => 0];
            });
        });

        $this->artisan('vacaciones:sincronizar-outlook')
            ->expectsOutput('Conexiones de Outlook (vacaciones) sincronizadas: 1/2')
            ->assertExitCode(0);

        $this->assertNotNull($primera->fresh()->ultimo_error);
        $this->assertNull($segunda->fresh()->ultimo_error);
    }

    public function test_el_comando_agenda_end_to_end(): void
    {
        $this->aprobadaPorElDirector();
        $this->conexion();
        $this->fakeGraph();

        $this->artisan('vacaciones:sincronizar-outlook')
            ->expectsOutput('Conexiones de Outlook (vacaciones) sincronizadas: 1/1')
            ->assertExitCode(0);

        $this->assertSame(1, VacationOutlookEvent::count());
    }
}
