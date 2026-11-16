<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Mail\RH\VacationReviewMail;
use App\Models\ApprovalFlowStep;
use App\Models\Department;
use App\Models\Employee;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\VacationRequest;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * Quien está informado del paso del director (p. ej. su asistente) recibe, cuando la solicitud llega a ese paso,
 * un aviso interno y un correo solo informativos: no tiene que hacer nada ni puede decidir.
 */
class CorreosInformadosVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private Department $depto;

    private Employee $jefe;

    private User $rh;

    private User $director;

    private User $asistente;

    private ApprovalFlowStep $reglaDirector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        Mail::fake();

        $this->depto = $this->departamento($this->empresa, 'VTA');
        $this->jefe = $this->empleadoConUsuario($this->empresa, ['department_id' => $this->depto->id, 'email' => 'jefe@ejemplo.com']);
        $this->depto->update(['manager_id' => $this->jefe->id]);
        $this->rh = $this->empleadoConUsuario($this->corporativo)->user;
        $this->concederVacaciones($this->rh, 'aprobar');
        $this->director = User::factory()->create(['name' => 'Director General', 'email' => 'director@ejemplo.com']);
        $this->asistente = User::factory()->create(['name' => 'Asistente Dirección', 'email' => 'asistente@ejemplo.com']);

        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $this->regla(2, ApprovalFlowStep::TYPE_RH_REVIEW);
        $this->reglaDirector = $this->regla(3, ApprovalFlowStep::TYPE_USER, ['user_id' => $this->director->id]);
        $this->reglaDirector->observers()->attach($this->asistente->id);
    }

    private function servicio(): VacationRequestService
    {
        return app(VacationRequestService::class);
    }

    private function nueva(): VacationRequest
    {
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);
        $empleado = $this->empleado($this->empresa, ['department_id' => $this->depto->id, 'email' => 'ana@ejemplo.com', 'first_name' => 'Ana']);

        return $this->servicio()->crear($empleado, $lunes, $lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL);
    }

    /** El jefe aprobó y RH dio el visto bueno: le toca al director. */
    private function enManosDelDirector(VacationRequest $solicitud): VacationRequest
    {
        $solicitud = $this->servicio()->aprobarPaso($solicitud, $this->jefe->user);

        return $this->servicio()->darVistoBueno($solicitud, $this->rh, 'Todo en orden');
    }

    private function informadaRecibio(): \Closure
    {
        return fn (VacationReviewMail $m) => $m->hasTo('asistente@ejemplo.com') && $m->via === VacationReviewMail::VIA_INFORMADO;
    }

    public function test_la_informada_recibe_el_correo_cuando_llega_al_director(): void
    {
        $solicitud = $this->enManosDelDirector($this->nueva());

        Mail::assertSent(VacationReviewMail::class, fn (VacationReviewMail $m) => $m->hasTo('asistente@ejemplo.com')
            && $m->via === 'informado'
            && $m->aprobador === 'Director General');
        Mail::assertSent(VacationReviewMail::class, function (VacationReviewMail $m) {
            if (! $m->hasTo('asistente@ejemplo.com')) {
                return false;
            }
            $html = $m->render();

            return str_contains($m->envelope()->subject, 'Solicitud de vacaciones para tu información')
                && str_contains($html, 'para tu información')
                && str_contains($html, 'Director General')
                && str_contains($html, config('vacaciones.sentinel_url').'/profile?tab=approvals')
                && ! str_contains($html, 'Apruébala')
                && ! str_contains($html, 'por revisar');
        });
        // El director sigue recibiendo el suyo, el de aprobador.
        Mail::assertSent(VacationReviewMail::class, fn (VacationReviewMail $m) => $m->hasTo('director@ejemplo.com') && $m->via === VacationReviewMail::VIA_SENTINEL);
        $this->assertSame('pending', $solicitud->fresh()->status);
    }

    public function test_no_recibe_nada_en_pasos_anteriores(): void
    {
        $solicitud = $this->nueva();

        Mail::assertNotSent(VacationReviewMail::class, fn (VacationReviewMail $m) => $m->hasTo('asistente@ejemplo.com'));

        $this->servicio()->aprobarPaso($solicitud, $this->jefe->user);

        Mail::assertNotSent(VacationReviewMail::class, fn (VacationReviewMail $m) => $m->hasTo('asistente@ejemplo.com'));
        $this->assertSame(0, SystemNotification::where('user_id', $this->asistente->id)->count());
    }

    public function test_recibe_un_aviso_interno(): void
    {
        $this->enManosDelDirector($this->nueva());

        $avisos = SystemNotification::where('user_id', $this->asistente->id)->get();
        $this->assertCount(1, $avisos);
        $this->assertSame('Solicitud de vacaciones para tu información', $avisos[0]->title);
        $this->assertSame('/profile?tab=approvals', $avisos[0]->action_url);
        $this->assertStringContainsString('está esperando a Director General', $avisos[0]->message);
        $this->assertStringContainsString('solicitó', $avisos[0]->message);
    }

    public function test_aprobador_e_informado_recibe_un_solo_correo(): void
    {
        $this->reglaDirector->observers()->attach($this->director->id);

        $this->enManosDelDirector($this->nueva());

        Mail::assertSent(VacationReviewMail::class, fn (VacationReviewMail $m) => $m->hasTo('director@ejemplo.com'), 1);
        Mail::assertSent(VacationReviewMail::class, fn (VacationReviewMail $m) => $m->hasTo('director@ejemplo.com') && $m->via === VacationReviewMail::VIA_SENTINEL);
        // El aviso interno informativo tampoco se duplica: ya recibe el de aprobador.
        $this->assertSame(0, SystemNotification::where('user_id', $this->director->id)->where('title', 'Solicitud de vacaciones para tu información')->count());
    }

    public function test_al_reprogramar_tambien_se_informa(): void
    {
        // Sin los pasos del jefe y de RH la solicitud espera directo al director (reprogramar no reinicia la cadena).
        ApprovalFlowStep::where('step_order', '<', 3)->update(['is_active' => false]);
        $solicitud = $this->nueva();
        Mail::assertSent(VacationReviewMail::class, $this->informadaRecibio());
        $antes = SystemNotification::where('user_id', $this->asistente->id)->count();
        Mail::fake();

        $lunes = today()->next(Carbon::MONDAY)->addWeeks(4);
        $this->servicio()->reprogramar($solicitud, $lunes, $lunes->copy()->addDays(2), null);

        Mail::assertSent(VacationReviewMail::class, $this->informadaRecibio());
        $this->assertSame($antes + 1, SystemNotification::where('user_id', $this->asistente->id)->count());
    }

    public function test_un_correo_invalido_de_la_informada_no_rompe_la_operacion(): void
    {
        $this->asistente->forceFill(['email' => 'sin-arroba'])->save();

        $solicitud = $this->enManosDelDirector($this->nueva());

        Mail::assertNotSent(VacationReviewMail::class, fn (VacationReviewMail $m) => $m->via === VacationReviewMail::VIA_INFORMADO);
        $this->assertSame('pending', $solicitud->fresh()->status);
        $this->assertSame(1, SystemNotification::where('user_id', $this->asistente->id)->count());
    }
}
