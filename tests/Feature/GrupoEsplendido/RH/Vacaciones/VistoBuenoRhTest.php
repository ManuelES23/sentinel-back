<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Exceptions\RH\VacacionesException;
use App\Mail\RH\VacationReviewMail;
use App\Models\ApprovalFlowStep;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserSubmodulePermission;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\RH\AprobadorVacaciones;
use App\Services\RH\CadenaAprobacionVacaciones;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * Paso «RH (visto bueno)»: RH lo da con un comentario para el director, nunca cierra la
 * solicitud, no puede rechazar, y si después de él nadie decide la solicitud espera en el
 * respaldo de RH (el director tiene la última palabra).
 */
class VistoBuenoRhTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private Employee $jefe;

    private Employee $solicitante;

    private User $rh;

    private User $director;

    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->lunes = today()->next(Carbon::MONDAY)->addWeeks(2);

        $this->jefe = $this->empleadoConUsuario($this->empresa);
        $this->solicitante = $this->empleadoConUsuario($this->empresa, ['reports_to' => $this->jefe->id]);
        $this->rh = User::factory()->create();
        $this->concederVacaciones($this->rh, 'aprobar');
        $this->director = $this->empleadoConUsuario($this->corporativo)->user;
    }

    /** Reglas 1 jefe directo, 2 visto bueno de RH y 3 director (usuario específico). */
    private function cadenaCompleta(): void
    {
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->regla(2, ApprovalFlowStep::TYPE_RH_REVIEW);
        $this->regla(3, ApprovalFlowStep::TYPE_USER, ['user_id' => $this->director->id]);
    }

    private function servicio(): VacationRequestService
    {
        return app(VacationRequestService::class);
    }

    private function nueva(): VacationRequest
    {
        return $this->servicio()->crear($this->solicitante, $this->lunes, $this->lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL);
    }

    private function paso(VacationRequest $solicitud, int $orden): VacationRequestApproval
    {
        return $solicitud->approvals()->where('step_order', $orden)->firstOrFail();
    }

    private function esperar403(string $mensaje, callable $accion): void
    {
        try {
            $accion();
            $this->fail('Se esperaba VacacionesException 403');
        } catch (VacacionesException $e) {
            $this->assertSame(403, $e->status);
            $this->assertStringContainsString($mensaje, $e->getMessage());
        }
    }

    public function test_flujo_jefe_rh_director(): void
    {
        $this->cadenaCompleta();
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);
        $this->assertSame(2, $solicitud->current_step);

        $solicitud = $this->servicio()->darVistoBueno($solicitud, $this->rh, '  El saldo cuadra  ');

        $this->assertSame('pending', $solicitud->status);
        $this->assertSame(3, $solicitud->current_step);
        $paso = $this->paso($solicitud, 2);
        $this->assertSame(VacationRequestApproval::APPROVED, $paso->decision);
        $this->assertSame('El saldo cuadra', $paso->comment);
        $this->assertSame($this->rh->id, $paso->decided_by);
        $this->assertNotNull($paso->decided_at);

        $solicitud = $this->servicio()->aprobarPaso($solicitud, $this->director);
        $this->assertSame('approved', $solicitud->status);
    }

    public function test_visto_bueno_sin_comentario_guarda_null(): void
    {
        $this->cadenaCompleta();
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);

        $solicitud = $this->servicio()->darVistoBueno($solicitud, $this->rh, '   ');

        $this->assertNull($this->paso($solicitud, 2)->comment);
        $this->assertSame(VacationRequestApproval::APPROVED, $this->paso($solicitud, 2)->decision);
    }

    public function test_el_comentario_se_recorta_a_500(): void
    {
        $this->cadenaCompleta();
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);

        $solicitud = $this->servicio()->darVistoBueno($solicitud, $this->rh, str_repeat('a', 700));

        $this->assertSame(500, mb_strlen($this->paso($solicitud, 2)->comment));
    }

    public function test_rh_no_puede_rechazar_ni_aprobar_el_paso_de_visto_bueno(): void
    {
        $this->cadenaCompleta();
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);

        $this->esperar403('visto bueno de RH', fn () => $this->servicio()->rechazar($solicitud, $this->rh, 'No procede'));
        $this->esperar403('visto bueno de RH', fn () => $this->servicio()->aprobarPaso($solicitud, $this->rh));

        $this->assertSame('pending', $solicitud->fresh()->status);
        $this->assertSame(VacationRequestApproval::WAITING, $this->paso($solicitud, 2)->decision);
    }

    public function test_visto_bueno_fuera_de_su_paso_da_403(): void
    {
        $this->cadenaCompleta();
        $solicitud = $this->nueva();

        $this->esperar403('visto bueno', fn () => $this->servicio()->darVistoBueno($solicitud, $this->rh, 'ok'));
        $this->assertSame(VacationRequestApproval::WAITING, $this->paso($solicitud, 1)->decision);
    }

    public function test_quien_no_es_rh_no_puede_dar_el_visto_bueno(): void
    {
        $this->cadenaCompleta();
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);

        $this->esperar403('visto bueno', fn () => $this->servicio()->darVistoBueno($solicitud, User::factory()->create(), 'ok'));
    }

    public function test_sin_permiso_rh_el_paso_se_salta(): void
    {
        $this->cadenaCompleta();
        UserSubmodulePermission::where('user_id', $this->rh->id)->delete();

        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);

        $paso = $this->paso($solicitud, 2);
        $this->assertSame(VacationRequestApproval::SKIPPED, $paso->decision);
        $this->assertSame(CadenaAprobacionVacaciones::SIN_APROBADOR, $paso->comment);
        $this->assertSame(3, $solicitud->current_step);
    }

    public function test_director_saltado_cae_en_respaldo_y_no_se_aprueba(): void
    {
        $this->cadenaCompleta();
        $this->director->employee()->update(['status' => Employee::STATUS_INACTIVE]);

        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);
        $solicitud = $this->servicio()->darVistoBueno($solicitud, $this->rh, 'ok');

        $this->assertSame('pending', $solicitud->status);
        $this->assertSame(VacationRequestApproval::SKIPPED, $this->paso($solicitud, 3)->decision);
        $respaldo = $this->paso($solicitud, 4);
        $this->assertTrue((bool) $respaldo->is_rh_final);
        $this->assertSame(VacationRequestApproval::WAITING, $respaldo->decision);
        $this->assertSame(4, $solicitud->current_step);

        // La misma persona de RH puede cerrar en el respaldo: es el único caso en que RH cierra.
        $solicitud = $this->servicio()->aprobarPaso($solicitud, $this->rh);
        $this->assertSame('approved', $solicitud->status);
    }

    public function test_jefe_saltado_visto_bueno_y_director_saltado_espera_en_respaldo(): void
    {
        $this->cadenaCompleta();
        $this->jefe->update(['status' => Employee::STATUS_INACTIVE]);
        $this->director->employee()->update(['status' => Employee::STATUS_INACTIVE]);

        $solicitud = $this->nueva();
        $this->assertSame(2, $solicitud->current_step);

        $solicitud = $this->servicio()->darVistoBueno($solicitud, $this->rh, null);

        $this->assertSame('pending', $solicitud->status);
        $this->assertTrue((bool) $this->paso($solicitud, 4)->is_rh_final);
        $this->assertSame(4, $solicitud->current_step);
    }

    public function test_respaldo_se_salta_si_el_ultimo_aprobado_no_es_visto_bueno(): void
    {
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->regla(2, ApprovalFlowStep::TYPE_USER, ['user_id' => $this->director->id]);

        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);
        $solicitud = $this->servicio()->aprobarPaso($solicitud, $this->director);

        $this->assertSame('approved', $solicitud->status);
        $this->assertSame(CadenaAprobacionVacaciones::NO_REQUERIDO, $this->paso($solicitud, 3)->comment);
    }

    public function test_siguiente_si_aprueba(): void
    {
        $this->cadenaCompleta();
        $cadena = app(CadenaAprobacionVacaciones::class);

        $solicitud = $this->nueva();
        $this->assertSame(2, $cadena->siguienteSiAprueba($solicitud)->step_order);

        $solicitud = $this->servicio()->aprobarPaso($solicitud, $this->jefe->user);
        $this->assertSame(3, $cadena->siguienteSiAprueba($solicitud)->step_order);

        $solicitud = $this->servicio()->darVistoBueno($solicitud, $this->rh, null);
        $this->assertNull($cadena->siguienteSiAprueba($solicitud));

        // No escribió nada.
        $this->assertSame(VacationRequestApproval::WAITING, $this->paso($solicitud, 3)->decision);
        $this->assertSame(VacationRequestApproval::WAITING, $this->paso($solicitud, 4)->decision);
        $this->assertSame(3, $solicitud->fresh()->current_step);
    }

    public function test_siguiente_si_aprueba_con_director_saltado_devuelve_el_respaldo(): void
    {
        $this->cadenaCompleta();
        $this->director->employee()->update(['status' => Employee::STATUS_INACTIVE]);
        $cadena = app(CadenaAprobacionVacaciones::class);

        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);
        $this->assertSame(2, $solicitud->current_step);

        $siguiente = $cadena->siguienteSiAprueba($solicitud);

        $this->assertTrue((bool) $siguiente->is_rh_final);
        $this->assertSame(VacationRequestApproval::WAITING, $this->paso($solicitud, 3)->decision);
    }

    public function test_siguiente_si_aprueba_sin_visto_bueno_no_devuelve_respaldo_si_el_actual_cierra(): void
    {
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $cadena = app(CadenaAprobacionVacaciones::class);

        $this->assertNull($cadena->siguienteSiAprueba($this->nueva()));
    }

    public function test_reglas_del_aprobador_para_el_paso_de_visto_bueno(): void
    {
        $this->cadenaCompleta();
        $aprobador = app(AprobadorVacaciones::class);
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user)->fresh(['employee', 'approvals']);
        $paso = $this->paso($solicitud, 2);

        $this->assertTrue($aprobador->puedeDecidir($this->rh, $solicitud, $paso));
        $this->assertFalse($aprobador->puedeDecidir($this->rh, $solicitud, $paso, 'rechazar'));
        $this->assertFalse($aprobador->puedeDecidir(User::factory()->create(), $solicitud, $paso));
        $this->assertSame([$this->rh->id], $aprobador->elegiblesDelPaso($solicitud, $paso)->pluck('id')->all());

        // Nunca desde el portal.
        $this->assertFalse($aprobador->puedeDecidirComoEmpleado($this->jefe, $solicitud, $paso));
    }

    public function test_el_solicitante_de_rh_no_se_da_visto_bueno_a_si_mismo(): void
    {
        $this->cadenaCompleta();
        $propia = $this->empleado($this->corporativo, ['user_id' => $this->rh->id, 'reports_to' => $this->jefe->id]);
        $solicitud = $this->servicio()->crear($propia, $this->lunes, $this->lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL);
        $solicitud = $this->servicio()->aprobarPaso($solicitud, $this->jefe->user);

        $this->esperar403('visto bueno', fn () => $this->servicio()->darVistoBueno($solicitud, $this->rh, 'ok'));
    }

    public function test_aviso_del_visto_bueno_va_por_via_rh(): void
    {
        $this->cadenaCompleta();
        Mail::fake();

        $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);

        Mail::assertSent(VacationReviewMail::class, fn (VacationReviewMail $mail) => $mail->hasTo($this->rh->email) && $mail->via === VacationReviewMail::VIA_RH);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $this->rh->id, 'title' => 'Solicitud de vacaciones por aprobar', 'action_url' => null,
        ]);
    }
}
