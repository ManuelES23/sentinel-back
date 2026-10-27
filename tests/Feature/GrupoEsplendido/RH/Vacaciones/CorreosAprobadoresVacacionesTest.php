<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Mail\RH\VacationReviewMail;
use App\Mail\RH\VacationStatusMail;
use App\Models\ApprovalFlowStep;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationNotification;
use App\Models\VacationRequest;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * Correos de la cadena: a cada aprobador cuando le toca (con o sin usuario de Sentinel) y al
 * empleado cuando un paso aprueba y la solicitud pasa al siguiente.
 */
class CorreosAprobadoresVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        Mail::fake();
    }

    /** Jefe del departamento (paso 1) y director como usuario específico (paso 2). */
    private function cadena(bool $jefeConUsuario): array
    {
        $depto = $this->departamento($this->empresa, 'VTA');
        $atributos = ['department_id' => $depto->id, 'email' => 'jefe@ejemplo.com', 'first_name' => 'Rosa'];
        $jefe = $jefeConUsuario ? $this->empleadoConUsuario($this->empresa, $atributos) : $this->empleado($this->empresa, $atributos);
        $depto->update(['manager_id' => $jefe->id]);
        $solicitante = $this->empleado($this->empresa, ['department_id' => $depto->id, 'email' => 'ana@ejemplo.com', 'first_name' => 'Ana']);
        $director = User::factory()->create(['name' => 'Director General', 'email' => 'director@ejemplo.com']);

        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $this->regla(2, ApprovalFlowStep::TYPE_USER, ['user_id' => $director->id]);

        return [$jefe, $solicitante, $director];
    }

    private function crear(Employee $empleado): VacationRequest
    {
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);

        return app(VacationRequestService::class)->crear($empleado, $lunes, $lunes->copy()->addDays(4), 'Descanso', VacationRequest::SOURCE_PORTAL);
    }

    private function revisionA(string $correo): \Closure
    {
        return fn (VacationReviewMail $mail) => $mail->hasTo($correo);
    }

    private function alEmpleado(string $evento): \Closure
    {
        return fn (VacationStatusMail $mail) => $mail->hasTo('ana@ejemplo.com') && $mail->evento === $evento;
    }

    public function test_al_crear_le_llega_correo_al_jefe_con_usuario_con_el_portal_y_sentinel(): void
    {
        [, $solicitante] = $this->cadena(true);

        $this->crear($solicitante);

        Mail::assertSent(VacationReviewMail::class, 1);
        Mail::assertSent(VacationReviewMail::class, fn (VacationReviewMail $mail) => $mail->hasTo('jefe@ejemplo.com')
            && str_contains($mail->render(), config('vacaciones.portal_url'))
            && str_contains($mail->render(), config('vacaciones.sentinel_url'))
            && str_contains($mail->render(), $solicitante->full_name));
        Mail::assertNotSent(VacationReviewMail::class, $this->revisionA('director@ejemplo.com'));
        Mail::assertSent(VacationStatusMail::class, $this->alEmpleado(VacationNotification::EVENTO_RECIBIDA));
    }

    public function test_si_la_ficha_del_jefe_no_trae_correo_se_usa_el_de_su_usuario(): void
    {
        [$jefe, $solicitante] = $this->cadena(true);
        $jefe->update(['email' => null]);
        $jefe->user->update(['email' => 'rosa.usuario@ejemplo.com']);

        $this->crear($solicitante);

        Mail::assertSent(VacationReviewMail::class, $this->revisionA('rosa.usuario@ejemplo.com'));
    }

    public function test_cuando_el_jefe_aprueba_le_llega_al_director_y_el_empleado_sabe_que_avanzo(): void
    {
        [$jefe, $solicitante] = $this->cadena(false);
        $solicitud = $this->crear($solicitante);

        app(VacationRequestService::class)->aprobarPaso($solicitud, $jefe);

        Mail::assertSent(VacationReviewMail::class, fn (VacationReviewMail $mail) => $mail->hasTo('director@ejemplo.com')
            && str_contains($mail->render(), config('vacaciones.sentinel_url').'/profile?tab=approvals')
            && ! str_contains($mail->render(), 'PIN'));
        Mail::assertSent(VacationStatusMail::class, fn (VacationStatusMail $mail) => $mail->hasTo('ana@ejemplo.com')
            && $mail->evento === VacationNotification::EVENTO_EN_REVISION
            && str_contains($mail->render(), $jefe->full_name)
            && str_contains($mail->render(), 'Director General'));
        $this->assertDatabaseHas('vacation_notifications', ['vacation_request_id' => $solicitud->id, 'event' => 'en_revision']);
        $this->assertSame('pending', $solicitud->fresh()->status);
    }

    public function test_cuando_el_director_aprueba_al_final_el_empleado_recibe_la_aprobacion_y_nadie_mas_revision(): void
    {
        [$jefe, $solicitante, $director] = $this->cadena(false);
        $solicitud = $this->crear($solicitante);
        app(VacationRequestService::class)->aprobarPaso($solicitud, $jefe);

        app(VacationRequestService::class)->aprobarPaso($solicitud->fresh(), $director);

        $this->assertSame('approved', $solicitud->fresh()->status);
        Mail::assertSent(VacationStatusMail::class, $this->alEmpleado(VacationNotification::EVENTO_APROBADA));
        // Jefe al crear + director al avanzar; aprobar al final no manda otra revisión.
        Mail::assertSent(VacationReviewMail::class, 2);
        Mail::assertSent(VacationStatusMail::class, fn (VacationStatusMail $mail) => $mail->evento === VacationNotification::EVENTO_EN_REVISION, 1);
    }

    public function test_si_el_director_rechaza_el_empleado_recibe_el_rechazo(): void
    {
        [$jefe, $solicitante, $director] = $this->cadena(false);
        $solicitud = $this->crear($solicitante);
        app(VacationRequestService::class)->aprobarPaso($solicitud, $jefe);

        app(VacationRequestService::class)->rechazar($solicitud->fresh(), $director, 'Cierre de mes');

        Mail::assertSent(VacationStatusMail::class, fn (VacationStatusMail $mail) => $mail->hasTo('ana@ejemplo.com')
            && $mail->evento === VacationNotification::EVENTO_RECHAZADA
            && str_contains($mail->render(), 'Cierre de mes'));
    }

    public function test_reprogramar_vuelve_a_avisar_al_aprobador_pero_no_le_dice_al_empleado_que_avanzo(): void
    {
        [, $solicitante] = $this->cadena(false);
        $solicitud = $this->crear($solicitante);
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(4);

        app(VacationRequestService::class)->reprogramar($solicitud, $lunes, $lunes->copy()->addDays(2), null);

        Mail::assertSent(VacationReviewMail::class, $this->revisionA('jefe@ejemplo.com'));
        Mail::assertSent(VacationReviewMail::class, 2);
        Mail::assertNotSent(VacationStatusMail::class, fn (VacationStatusMail $mail) => $mail->evento === VacationNotification::EVENTO_EN_REVISION);
    }

    public function test_sin_reglas_la_solicitud_espera_a_rh_y_le_llega_correo_a_quien_puede_aprobar(): void
    {
        $this->submoduloVacaciones();
        $rh = User::factory()->create(['email' => 'rh@ejemplo.com']);
        $this->concederVacaciones($rh, 'aprobar');
        $solicitante = $this->empleado($this->empresa, ['email' => 'ana@ejemplo.com']);

        $this->crear($solicitante);

        Mail::assertSent(VacationReviewMail::class, fn (VacationReviewMail $mail) => $mail->hasTo('rh@ejemplo.com')
            && str_contains($mail->render(), 'Vacaciones'));
    }

    public function test_un_aprobador_sin_correo_valido_no_rompe_nada(): void
    {
        [$jefe, $solicitante, $director] = $this->cadena(false);
        $director->forceFill(['email' => 'sin-arroba'])->save();
        $solicitud = $this->crear($solicitante);

        app(VacationRequestService::class)->aprobarPaso($solicitud, $jefe);

        Mail::assertNotSent(VacationReviewMail::class, $this->revisionA('sin-arroba'));
        $this->assertSame('pending', $solicitud->fresh()->status);
    }
}
