<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationOutlookConnection;
use App\Models\VacationOutlookEvent;
use App\Models\VacationRequest;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * Al cerrarse la cadena (aprobada) se agenda en el Outlook de quien autoriza, y al cancelarse una aprobada se borra.
 * Se dispara con `defer` (tras la respuesta) y nunca rompe aprobar ni cancelar. Cadena: jefe, visto bueno de RH, director.
 */
class OutlookVacacionesDisparoTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const EVENTOS = 'https://graph.microsoft.com/v1.0/me/events';

    private Employee $jefe;

    private Employee $solicitante;

    private User $rh;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer(); // el defer corre al instante
        $this->setUpVacaciones();

        $this->jefe = $this->empleadoConUsuario($this->empresa);
        $this->solicitante = $this->empleadoConUsuario($this->empresa, ['first_name' => 'Ana', 'last_name' => 'Pérez', 'reports_to' => $this->jefe->id]);
        $this->rh = $this->empleadoConUsuario($this->corporativo)->user;
        $this->concederVacaciones($this->rh, 'aprobar');
        $this->director = $this->empleadoConUsuario($this->corporativo)->user;

        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->regla(2, ApprovalFlowStep::TYPE_RH_REVIEW);
        $this->regla(3, ApprovalFlowStep::TYPE_USER, ['user_id' => $this->director->id]);

        VacationOutlookConnection::create([
            'user_id' => $this->director->id,
            'email_outlook' => 'director@outlook.com',
            'access_token' => 'access-vigente',
            'refresh_token' => 'refresh-vigente',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function servicio(): VacationRequestService
    {
        return app(VacationRequestService::class);
    }

    private function nueva(): VacationRequest
    {
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);

        return $this->servicio()->crear($this->solicitante, $lunes, $lunes->copy()->addDays(4), 'Descanso familiar', VacationRequest::SOURCE_PORTAL);
    }

    private function enPasoDelDirector(): VacationRequest
    {
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);

        return $this->servicio()->darVistoBueno($solicitud, $this->rh, 'Va bien');
    }

    private function fakeGraph(int $estadoCrear = 201): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'access-nuevo', 'refresh_token' => 'refresh-nuevo', 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/me/events' => Http::response(['id' => 'evt-1'], $estadoCrear),
            'graph.microsoft.com/v1.0/me/events/*' => Http::response(null, 204),
        ]);
    }

    private function posts(): int
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST' && $request->url() === self::EVENTOS)->count();
    }

    public function test_al_cerrar_la_aprobacion_se_agenda_en_el_outlook_del_director(): void
    {
        $solicitud = $this->enPasoDelDirector();
        $this->fakeGraph();

        $solicitud = $this->servicio()->aprobarPaso($solicitud, $this->director);

        $this->assertSame('approved', $solicitud->status);
        $this->assertSame(1, $this->posts());
        $this->assertSame([$solicitud->id], VacationOutlookEvent::pluck('vacation_request_id')->all());
    }

    public function test_aprobar_un_paso_intermedio_no_llama_a_outlook(): void
    {
        $this->fakeGraph();

        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);
        $this->servicio()->darVistoBueno($solicitud, $this->rh, 'Va bien');

        Http::assertNothingSent();
        $this->assertSame(0, VacationOutlookEvent::count());
    }

    public function test_cancelar_una_aprobada_la_borra_de_outlook(): void
    {
        $this->fakeGraph();
        $solicitud = $this->servicio()->aprobarPaso($this->enPasoDelDirector(), $this->director);
        $this->assertSame(1, VacationOutlookEvent::count(), 'quedó agendada al aprobar');

        $cancelada = $this->servicio()->cancelar($solicitud, VacationRequestService::CANCELA_RH);

        $this->assertSame('cancelled', $cancelada->status);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/me/events/evt-1'));
        $this->assertSame(0, VacationOutlookEvent::count());
    }

    public function test_si_outlook_falla_la_aprobacion_igual_queda(): void
    {
        $solicitud = $this->enPasoDelDirector();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response('boom', 500),
            'graph.microsoft.com/*' => Http::response('boom', 500),
        ]);
        Sanctum::actingAs($this->director);

        $this->postJson("/api/pending-approvals/vacation_request/{$solicitud->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame('approved', $solicitud->fresh()->status);
        $this->assertSame(0, VacationOutlookEvent::count());
    }

    public function test_si_el_servicio_de_outlook_lanza_aprobar_y_cancelar_siguen_funcionando(): void
    {
        $this->app->bind(\App\Services\RH\OutlookVacaciones::class, fn () => throw new \RuntimeException('boom'));

        $solicitud = $this->servicio()->aprobarPaso($this->enPasoDelDirector(), $this->director);
        $this->assertSame('approved', $solicitud->status);

        $cancelada = $this->servicio()->cancelar($solicitud, VacationRequestService::CANCELA_RH);
        $this->assertSame('cancelled', $cancelada->status);
    }

    public function test_cancelar_una_pendiente_o_rechazar_no_llama_a_outlook(): void
    {
        $this->fakeGraph();
        $pendiente = $this->nueva();
        $this->servicio()->cancelar($pendiente, VacationRequestService::CANCELA_EMPLEADO);

        $otra = $this->servicio()->crear($this->solicitante, today()->next(Carbon::MONDAY)->addWeeks(6), today()->next(Carbon::MONDAY)->addWeeks(6)->addDays(2), null, VacationRequest::SOURCE_PORTAL);
        $this->servicio()->rechazar($otra, $this->jefe->user, 'Cierre de mes');

        Http::assertNothingSent();
    }
}
