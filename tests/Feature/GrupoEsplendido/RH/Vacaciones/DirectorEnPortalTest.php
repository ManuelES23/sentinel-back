<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Mail\RH\VacationReviewMail;
use App\Models\ApprovalFlowStep;
use App\Models\Employee;
use App\Models\User;
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
 * El director (regla «Usuario específico» con empleado ligado) decide también desde el portal
 * (número + PIN), igual que el jefe de departamento. Reglas: 1 jefe directo, 2 visto bueno de RH, 3 director.
 */
class DirectorEnPortalTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const BASE = '/api/portal-vacaciones';

    private Employee $jefe;

    private Employee $solicitante;

    private Employee $director;

    private User $rh;

    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->lunes = today()->next(Carbon::MONDAY)->addWeeks(2);

        $this->jefe = $this->empleadoConUsuario($this->empresa);
        $this->solicitante = $this->empleadoConUsuario($this->empresa, ['reports_to' => $this->jefe->id]);
        $this->director = $this->empleadoConUsuario($this->corporativo, ['email' => 'director@ejemplo.com']);
        $this->rh = User::factory()->create();
        $this->concederVacaciones($this->rh, 'aprobar');

        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->regla(2, ApprovalFlowStep::TYPE_RH_REVIEW);
        $this->regla(3, ApprovalFlowStep::TYPE_USER, ['user_id' => $this->director->user_id]);
    }

    private function servicio(): VacationRequestService
    {
        return app(VacationRequestService::class);
    }

    private function nueva(?Employee $empleado = null): VacationRequest
    {
        $inicio = $this->lunes->copy();

        return $this->servicio()->crear($empleado ?? $this->solicitante, $inicio, $inicio->copy()->addDays(4), 'Descanso', VacationRequest::SOURCE_PORTAL);
    }

    /** Solicitud ya en el paso del director: jefe aprobó y RH dio su visto bueno. */
    private function enPasoDelDirector(): VacationRequest
    {
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);

        return $this->servicio()->darVistoBueno($solicitud, $this->rh);
    }

    private function entrar(Employee $empleado): array
    {
        $token = $this->postJson(self::BASE.'/login', ['employee_number' => $empleado->employee_number, 'pin' => '123456'])
            ->assertOk()
            ->json('data.token');

        return ['X-Portal-Token' => $token];
    }

    private function aprobador(): AprobadorVacaciones
    {
        return app(AprobadorVacaciones::class);
    }

    public function test_director_ve_y_aprueba_en_el_portal(): void
    {
        $solicitud = $this->enPasoDelDirector();
        $this->assertSame(3, $solicitud->current_step);
        $encabezados = $this->entrar($this->director);

        $respuesta = $this->getJson(self::BASE.'/por-revisar', $encabezados)->assertOk();
        $this->assertSame([$solicitud->id], collect($respuesta->json('data'))->pluck('id')->all());
        $this->getJson(self::BASE.'/por-revisar/'.$solicitud->id, $encabezados)->assertOk()->assertJsonPath('data.id', $solicitud->id);

        $this->postJson(self::BASE.'/por-revisar/'.$solicitud->id.'/aprobar', [], $encabezados)
            ->assertOk()
            ->assertJsonPath('data.estado', 'approved');

        $solicitud = $solicitud->fresh();
        $this->assertSame('approved', $solicitud->status);
        $this->assertSame($this->director->user_id, (int) $solicitud->approved_by);
        $paso = $solicitud->approvals()->where('step_order', 3)->first();
        $this->assertSame($this->director->id, (int) $paso->decided_by_employee_id);
        $this->assertSame($this->director->user_id, (int) $paso->decided_by);
    }

    public function test_director_rechaza_en_el_portal_con_motivo(): void
    {
        $solicitud = $this->enPasoDelDirector();

        $this->postJson(self::BASE.'/por-revisar/'.$solicitud->id.'/rechazar', ['reason' => 'Cierre de zafra'], $this->entrar($this->director))
            ->assertOk()
            ->assertJsonPath('data.estado', 'rejected');

        $this->assertSame('rejected', $solicitud->fresh()->status);
    }

    public function test_director_no_ve_la_solicitud_antes_de_su_paso(): void
    {
        $solicitud = $this->nueva();
        $this->assertSame(1, $solicitud->fresh()->current_step);
        $encabezados = $this->entrar($this->director);

        $this->getJson(self::BASE.'/por-revisar', $encabezados)->assertOk()->assertJsonPath('data', []);
        $this->getJson(self::BASE.'/por-revisar/'.$solicitud->id, $encabezados)->assertStatus(404);
        $this->postJson(self::BASE.'/por-revisar/'.$solicitud->id.'/aprobar', [], $encabezados)->assertStatus(404);
        $this->assertSame('pending', $solicitud->fresh()->status);
    }

    public function test_director_no_ve_la_suya(): void
    {
        // El director solicita: su jefe directo es el jefe y él mismo es la regla 3.
        $this->director->update(['reports_to' => $this->jefe->id]);
        $suya = $this->servicio()->aprobarPaso($this->nueva($this->director->fresh()), $this->jefe->user);
        $suya = $this->servicio()->darVistoBueno($suya, $this->rh);

        $paso = $suya->approvals()->where('step_order', 3)->first();
        $this->assertSame(VacationRequestApproval::SKIPPED, $paso->decision);
        $this->assertSame(CadenaAprobacionVacaciones::SIN_APROBADOR, $paso->comment);

        $encabezados = $this->entrar($this->director);
        $this->getJson(self::BASE.'/por-revisar', $encabezados)->assertOk()->assertJsonPath('data', []);
        $this->getJson(self::BASE.'/por-revisar/'.$suya->id, $encabezados)->assertStatus(404);
    }

    public function test_empleado_ligado_a_otro_usuario_no_entra(): void
    {
        $solicitud = $this->enPasoDelDirector();
        $otro = $this->empleadoConUsuario($this->corporativo);
        $encabezados = $this->entrar($otro);

        $this->getJson(self::BASE.'/por-revisar', $encabezados)->assertOk()->assertJsonPath('data', []);
        $this->getJson(self::BASE.'/por-revisar/'.$solicitud->id, $encabezados)->assertStatus(404);
        $this->postJson(self::BASE.'/por-revisar/'.$solicitud->id.'/aprobar', [], $encabezados)->assertStatus(404);
        $this->assertSame('pending', $solicitud->fresh()->status);
    }

    public function test_un_empleado_sin_usuario_no_entra(): void
    {
        $solicitud = $this->enPasoDelDirector();
        $sinUsuario = $this->empleado($this->corporativo);

        $this->getJson(self::BASE.'/por-revisar/'.$solicitud->id, $this->entrar($sinUsuario))->assertStatus(404);
    }

    public function test_el_director_de_baja_no_decide_por_el_portal(): void
    {
        $solicitud = $this->enPasoDelDirector();
        $paso = $solicitud->approvals()->where('step_order', 3)->first();
        $this->director->update(['status' => Employee::STATUS_TERMINATED]);

        $this->assertFalse($this->aprobador()->puedeDecidirComoEmpleado($this->director->fresh(), $solicitud, $paso));
        $this->assertTrue($this->aprobador()->revisoresDelPortal($solicitud, $paso)->isEmpty());
    }

    public function test_los_revisores_del_portal_incluyen_al_director_en_su_paso(): void
    {
        $solicitud = $this->enPasoDelDirector();
        $paso = $solicitud->approvals()->where('step_order', 3)->first();

        $this->assertSame([$this->director->id], $this->aprobador()->revisoresDelPortal($solicitud, $paso)->pluck('id')->all());
        $this->assertTrue($this->aprobador()->hayQuienDecida($solicitud, $paso));
    }

    public function test_el_chequeo_de_jefe_no_aplica_a_la_regla_de_usuario(): void
    {
        // El director no es jefe de ningún departamento y aun así decide por la regla «usuario».
        $solicitud = $this->enPasoDelDirector();
        $paso = $solicitud->approvals()->where('step_order', 3)->first();

        $this->assertTrue($this->aprobador()->puedeDecidirComoEmpleado($this->director, $solicitud, $paso));
        $this->assertTrue($this->aprobador()->puedeDecidirComoEmpleado($this->director, $solicitud, $paso, 'rechazar'));
        // Ni el jefe directo ni el solicitante entran por ahí.
        $this->assertFalse($this->aprobador()->puedeDecidirComoEmpleado($this->jefe, $solicitud, $paso));
        $this->assertFalse($this->aprobador()->puedeDecidirComoEmpleado($this->solicitante, $solicitud, $paso));
    }

    public function test_respeta_can_approve_de_la_regla_de_usuario(): void
    {
        $solicitud = $this->enPasoDelDirector();
        $paso = $solicitud->approvals()->where('step_order', 3)->first();
        ApprovalFlowStep::whereKey($paso->rules)->update(['can_approve' => false]);

        $this->assertFalse($this->aprobador()->puedeDecidirComoEmpleado($this->director, $solicitud, $paso->fresh(), 'aprobar'));
        $this->assertTrue($this->aprobador()->puedeDecidirComoEmpleado($this->director, $solicitud, $paso->fresh(), 'rechazar'));
    }

    public function test_yo_trae_puede_revisar(): void
    {
        $depto = $this->departamento($this->empresa, 'VTA');
        $jefeDeDepto = $this->empleado($this->empresa, ['department_id' => $depto->id]);
        $depto->update(['manager_id' => $jefeDeDepto->id]);
        $normal = $this->empleado($this->empresa);

        $this->getJson(self::BASE.'/yo', $this->entrar($this->director))->assertOk()
            ->assertJsonPath('data.puede_revisar', true)->assertJsonPath('data.es_jefe', false);
        $this->getJson(self::BASE.'/yo', $this->entrar($jefeDeDepto))->assertOk()
            ->assertJsonPath('data.puede_revisar', true)->assertJsonPath('data.es_jefe', true);
        $this->getJson(self::BASE.'/yo', $this->entrar($normal))->assertOk()
            ->assertJsonPath('data.puede_revisar', false)->assertJsonPath('data.es_jefe', false);
    }

    public function test_una_regla_de_usuario_inactiva_no_da_puede_revisar(): void
    {
        ApprovalFlowStep::where('approver_type', ApprovalFlowStep::TYPE_USER)->update(['is_active' => false]);

        $this->getJson(self::BASE.'/yo', $this->entrar($this->director))->assertOk()->assertJsonPath('data.puede_revisar', false);
    }

    public function test_correo_al_director_va_por_portal_o_sentinel(): void
    {
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);

        Mail::fake();
        $this->servicio()->darVistoBueno($solicitud, $this->rh);

        Mail::assertSent(VacationReviewMail::class, 1);
        Mail::assertSent(VacationReviewMail::class, fn (VacationReviewMail $mail) => $mail->hasTo('director@ejemplo.com')
            && $mail->via === VacationReviewMail::VIA_PORTAL_O_SENTINEL);
    }

    public function test_correo_al_director_sin_empleado_ligado_va_por_sentinel(): void
    {
        $this->director->update(['user_id' => null]);
        $sinEmpleado = User::factory()->create(['email' => 'otro-director@ejemplo.com']);
        ApprovalFlowStep::where('approver_type', ApprovalFlowStep::TYPE_USER)->update(['user_id' => $sinEmpleado->id]);
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);

        Mail::fake();
        $this->servicio()->darVistoBueno($solicitud, $this->rh);

        Mail::assertSent(VacationReviewMail::class, fn (VacationReviewMail $mail) => $mail->hasTo('otro-director@ejemplo.com')
            && $mail->via === VacationReviewMail::VIA_SENTINEL);
    }
}
