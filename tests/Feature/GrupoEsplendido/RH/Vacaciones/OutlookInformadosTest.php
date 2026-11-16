<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationOutlookConnection;
use App\Models\VacationOutlookEvent;
use App\Models\VacationRequest;
use App\Services\RH\InformadosVacaciones;
use App\Services\RH\OutlookVacaciones;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * El Outlook de la persona informada del paso del director: puede vincularlo y agenda lo que ese paso aprobó,
 * igual que el del director. Cadena: 1 jefe directo, 2 visto bueno de RH, 3 director (con la asistente informada).
 */
class OutlookInformadosTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const EVENTOS = 'https://graph.microsoft.com/v1.0/me/events';

    private Employee $jefe;

    private Employee $solicitante;

    private User $rh;

    private User $director;

    private User $asistente;

    private ApprovalFlowStep $reglaDirector;

    private int $semana = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();

        $this->jefe = $this->empleadoConUsuario($this->empresa);
        $this->solicitante = $this->empleadoConUsuario($this->empresa, [
            'first_name' => 'Ana', 'last_name' => 'Pérez', 'reports_to' => $this->jefe->id,
        ]);
        $this->rh = $this->empleadoConUsuario($this->corporativo)->user;
        $this->concederVacaciones($this->rh, 'aprobar');
        $this->director = $this->empleadoConUsuario($this->corporativo)->user;
        $this->asistente = $this->empleadoConUsuario($this->corporativo, ['reports_to' => $this->jefe->id])->user;

        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->regla(2, ApprovalFlowStep::TYPE_RH_REVIEW);
        $this->reglaDirector = $this->regla(3, ApprovalFlowStep::TYPE_USER, ['user_id' => $this->director->id]);
        $this->reglaDirector->observers()->attach($this->asistente->id);
    }

    private function servicio(): VacationRequestService
    {
        return app(VacationRequestService::class);
    }

    private function outlook(): OutlookVacaciones
    {
        return app(OutlookVacaciones::class);
    }

    private function nueva(?Employee $empleado = null): VacationRequest
    {
        $lunes = today()->next(Carbon::MONDAY)->addWeeks($this->semana++);

        return $this->servicio()->crear($empleado ?? $this->solicitante, $lunes, $lunes->copy()->addDays(4), 'Descanso', VacationRequest::SOURCE_PORTAL);
    }

    private function enPasoDelDirector(?Employee $empleado = null): VacationRequest
    {
        $solicitud = $this->servicio()->aprobarPaso($this->nueva($empleado), $this->jefe->user);

        return $this->servicio()->darVistoBueno($solicitud, $this->rh, 'Va bien');
    }

    private function aprobadaPorElDirector(?Employee $empleado = null): VacationRequest
    {
        return $this->servicio()->aprobarPaso($this->enPasoDelDirector($empleado), $this->director);
    }

    private function conexion(?User $usuario = null): VacationOutlookConnection
    {
        $usuario ??= $this->asistente;

        return VacationOutlookConnection::create([
            'user_id' => $usuario->id,
            'email_outlook' => 'asistente@outlook.com',
            'access_token' => 'access-vigente',
            'refresh_token' => 'refresh-vigente',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function fakeGraph(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'access-nuevo', 'refresh_token' => 'refresh-nuevo', 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/me/events' => Http::sequence()
                ->push(['id' => 'evt-1'], 201)->push(['id' => 'evt-2'], 201)->push(['id' => 'evt-3'], 201),
            'graph.microsoft.com/v1.0/me/events/*' => Http::response(null, 204),
        ]);
    }

    private function posts(): int
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST' && $request->url() === self::EVENTOS)->count();
    }

    public function test_la_informada_puede_vincular(): void
    {
        $this->assertTrue($this->outlook()->disponible($this->asistente));

        Sanctum::actingAs($this->asistente);
        $this->getJson('/api/profile/outlook-vacaciones')->assertOk()->assertJsonPath('data.disponible', true);

        $cualquiera = $this->empleadoConUsuario($this->empresa)->user;
        $this->assertFalse($this->outlook()->disponible($cualquiera));
        Sanctum::actingAs($cualquiera);
        $this->getJson('/api/profile/outlook-vacaciones')->assertOk()->assertJsonPath('data.disponible', false);
    }

    public function test_se_agenda_lo_que_aprobo_el_paso(): void
    {
        $solicitud = $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        $this->fakeGraph();

        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(['creados' => 1, 'borrados' => 0], $resultado);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === self::EVENTOS
            && $request->data()['subject'] === 'Vacaciones · Ana Pérez');
        $this->assertDatabaseHas('vacation_outlook_events', [
            'vacation_outlook_connection_id' => $conexion->id,
            'vacation_request_id' => $solicitud->id,
            'outlook_event_id' => 'evt-1',
        ]);
    }

    public function test_al_aprobar_se_sincroniza_al_instante(): void
    {
        $this->withoutDefer();
        $conexion = $this->conexion();
        $this->fakeGraph();
        $enPaso = $this->enPasoDelDirector();
        $this->assertSame(0, VacationOutlookEvent::count());

        $solicitud = $this->servicio()->aprobarPaso($enPaso, $this->director);

        $this->assertDatabaseHas('vacation_outlook_events', [
            'vacation_outlook_connection_id' => $conexion->id,
            'vacation_request_id' => $solicitud->id,
        ]);
    }

    public function test_cancelar_borra_su_evento(): void
    {
        $this->withoutDefer();
        $conexion = $this->conexion();
        $this->fakeGraph();
        $solicitud = $this->aprobadaPorElDirector();
        $this->assertSame(1, VacationOutlookEvent::where('vacation_outlook_connection_id', $conexion->id)->count());

        $this->servicio()->cancelar($solicitud, VacationRequestService::CANCELA_RH);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/me/events/evt-1'));
        $this->assertSame(0, VacationOutlookEvent::where('vacation_outlook_connection_id', $conexion->id)->count());
    }

    public function test_regla_inactiva_no_agenda_nuevas(): void
    {
        $primera = $this->aprobadaPorElDirector();
        $conexion = $this->conexion();
        $this->fakeGraph();
        $this->outlook()->sincronizar($conexion);
        $this->assertSame(1, VacationOutlookEvent::count());

        $segunda = $this->aprobadaPorElDirector();
        $this->reglaDirector->update(['is_active' => false]);
        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(['creados' => 0, 'borrados' => 0], $resultado);
        $this->assertSame(1, $this->posts());
        $this->assertSame([$primera->id], VacationOutlookEvent::pluck('vacation_request_id')->all());
        $this->assertDatabaseMissing('vacation_outlook_events', ['vacation_request_id' => $segunda->id]);
    }

    public function test_lo_que_aprobo_rh_en_el_respaldo_no_le_llega(): void
    {
        // El director pide sus propias vacaciones: su paso se salta (no puede decidirlas) y RH cierra en el respaldo.
        $directorEmpleado = $this->director->employee()->first();
        $directorEmpleado->update(['reports_to' => $this->jefe->id]);
        $solicitud = $this->servicio()->aprobarPaso($this->nueva($directorEmpleado), $this->jefe->user);
        $solicitud = $this->servicio()->darVistoBueno($solicitud, $this->rh, 'Va bien');
        $this->assertTrue((bool) $solicitud->approvals()->where('step_order', $solicitud->current_step)->value('is_rh_final'));
        $solicitud = $this->servicio()->aprobarPaso($solicitud, $this->rh);
        $this->assertSame('approved', $solicitud->status);

        $conexion = $this->conexion();
        $this->fakeGraph();

        $resultado = $this->outlook()->sincronizar($conexion);

        $this->assertSame(['creados' => 0, 'borrados' => 0], $resultado);
        $this->assertSame(0, $this->posts());
        $this->assertSame(0, VacationOutlookEvent::count());
    }

    public function test_sus_propias_vacaciones_aprobadas_no_le_llegan(): void
    {
        $this->withoutDefer();
        $conexion = $this->conexion();
        $this->fakeGraph();
        $propia = $this->aprobadaPorElDirector($this->asistente->employee()->first());
        $this->assertSame('approved', $propia->status);

        $this->assertSame(0, VacationOutlookEvent::where('vacation_outlook_connection_id', $conexion->id)->count());
        $this->assertSame(['creados' => 0, 'borrados' => 0], $this->outlook()->sincronizar($conexion));
        $this->assertSame(0, $this->posts());

        $informados = app(InformadosVacaciones::class);
        $this->assertSame([], $informados->idsAprobadasQueInforma($this->asistente->id)->all());
        $this->assertSame([], $informados->informadosDeAprobadas($propia)->all());
    }

    public function test_la_de_otra_persona_si_llega_aunque_la_informada_tenga_las_suyas(): void
    {
        $propia = $this->aprobadaPorElDirector($this->asistente->employee()->first());
        $ajena = $this->aprobadaPorElDirector();
        $informados = app(InformadosVacaciones::class);

        $this->assertSame([$ajena->id], $informados->idsAprobadasQueInforma($this->asistente->id)->all());
        $this->assertSame([$this->asistente->id], $informados->informadosDeAprobadas($ajena)->all());
        $this->assertNotContains($propia->id, $informados->idsAprobadasQueInforma($this->asistente->id)->all());
    }
}
