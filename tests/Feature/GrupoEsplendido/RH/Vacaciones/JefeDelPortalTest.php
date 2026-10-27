<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Exceptions\RH\VacacionesException;
use App\Mail\RH\VacationReviewMail;
use App\Models\ApprovalFlowStep;
use App\Models\AttendanceRecord;
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

/** El jefe de un departamento (con o sin usuario de Sentinel) revisa y decide desde el portal con número + PIN. */
class JefeDelPortalTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const BASE = '/api/portal-vacaciones';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    /** Departamento con su jefe (sin usuario por defecto, con correo) y un solicitante dentro de él. */
    private function escenario(bool $jefeConUsuario = false): array
    {
        $depto = $this->departamento($this->empresa, 'VTA');
        $atributos = ['department_id' => $depto->id, 'email' => 'jefe@ejemplo.com'];
        $jefe = $jefeConUsuario ? $this->empleadoConUsuario($this->empresa, $atributos) : $this->empleado($this->empresa, $atributos);
        $depto->update(['manager_id' => $jefe->id]);
        $solicitante = $this->empleado($this->empresa, ['department_id' => $depto->id]);

        return [$depto, $jefe, $solicitante];
    }

    private function lunes(): Carbon
    {
        return today()->next(Carbon::MONDAY)->addWeeks(2);
    }

    private function crearSolicitud(Employee $empleado, int $semanasDespues = 0): VacationRequest
    {
        $inicio = $this->lunes()->addWeeks($semanasDespues);

        return app(VacationRequestService::class)->crear($empleado, $inicio, $inicio->copy()->addDays(4), 'Descanso', VacationRequest::SOURCE_PORTAL);
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

    private function pasoActual(VacationRequest $solicitud): VacationRequestApproval
    {
        return app(CadenaAprobacionVacaciones::class)->pasoActual($solicitud->fresh());
    }

    // ===== Motor =====

    public function test_el_jefe_sin_usuario_decide_por_el_portal_y_la_cadena_no_salta_su_paso(): void
    {
        [, $jefe, $solicitante] = $this->escenario();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);

        $solicitud = $this->crearSolicitud($solicitante);
        $paso = $this->pasoActual($solicitud);

        $this->assertSame(1, $solicitud->fresh()->current_step);
        $this->assertSame(VacationRequestApproval::WAITING, $paso->decision);
        $this->assertTrue($this->aprobador()->elegiblesDelPaso($solicitud, $paso)->isEmpty());
        $this->assertSame([$jefe->id], $this->aprobador()->jefesDelPortal($solicitud, $paso)->pluck('id')->all());
        $this->assertTrue($this->aprobador()->puedeDecidirComoEmpleado($jefe, $solicitud, $paso));
        $this->assertTrue($this->aprobador()->puedeDecidirComoEmpleado($jefe, $solicitud, $paso, 'rechazar'));
    }

    public function test_solo_el_jefe_de_ese_departamento_activo_y_ajeno_a_la_solicitud_puede(): void
    {
        [$depto, $jefe, $solicitante] = $this->escenario();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $solicitud = $this->crearSolicitud($solicitante);
        $paso = $this->pasoActual($solicitud);

        $otroJefe = $this->empleado($this->empresa, ['department_id' => $this->departamento($this->empresa, 'OTR')->id]);
        $this->assertFalse($this->aprobador()->puedeDecidirComoEmpleado($otroJefe, $solicitud, $paso));
        $this->assertFalse($this->aprobador()->puedeDecidirComoEmpleado($solicitante, $solicitud, $paso));

        $jefe->update(['status' => Employee::STATUS_TERMINATED]);
        $this->assertFalse($this->aprobador()->puedeDecidirComoEmpleado($jefe->fresh(), $solicitud, $paso));
        $this->assertTrue($this->aprobador()->jefesDelPortal($solicitud, $paso)->isEmpty());
    }

    public function test_el_jefe_no_aprueba_su_propia_solicitud_y_su_paso_se_salta(): void
    {
        [, $jefe] = $this->escenario();
        $director = User::factory()->create();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $this->regla(2, ApprovalFlowStep::TYPE_USER, ['user_id' => $director->id]);

        $suya = $this->crearSolicitud($jefe);

        $this->assertSame(VacationRequestApproval::SKIPPED, $suya->approvals()->where('step_order', 1)->first()->decision);
        $this->assertSame(2, $suya->fresh()->current_step);
        $this->assertFalse($this->aprobador()->puedeDecidirComoEmpleado($jefe, $suya, $suya->approvals()->where('step_order', 1)->first()));
    }

    public function test_otros_tipos_de_regla_no_abren_el_portal(): void
    {
        [, $jefe, $solicitante] = $this->escenario();
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $solicitante->update(['reports_to' => $jefe->id]);

        $solicitud = $this->crearSolicitud($solicitante);

        // Sin usuario, el jefe directo no es elegible: el paso se salta y espera en RH.
        $this->assertSame(VacationRequestApproval::SKIPPED, $solicitud->approvals()->where('step_order', 1)->first()->decision);
        $this->assertTrue($solicitud->approvals()->where('is_rh_final', true)->first()->decision === VacationRequestApproval::WAITING);
    }

    public function test_respeta_can_approve_y_can_reject_de_la_regla(): void
    {
        [, $jefe, $solicitante] = $this->escenario();
        $regla = $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER, ['can_approve' => false]);
        // Paso armado a mano: con can_approve = false la cadena lo saltaría desde el inicio.
        $solicitud = $this->solicitud($solicitante, 'pending', 3);
        $paso = $solicitud->approvals()->create([
            'step_order' => 1, 'description' => 'Jefe del departamento', 'rules' => [$regla->id],
            'is_rh_final' => false, 'decision' => VacationRequestApproval::WAITING,
        ]);

        $this->assertFalse($this->aprobador()->puedeDecidirComoEmpleado($jefe, $solicitud, $paso, 'aprobar'));
        $this->assertTrue($this->aprobador()->puedeDecidirComoEmpleado($jefe, $solicitud, $paso, 'rechazar'));
    }

    public function test_no_decide_dos_pasos_de_la_misma_solicitud(): void
    {
        [, $jefe, $solicitante] = $this->escenario();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $this->regla(2, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $servicio = app(VacationRequestService::class);
        $solicitud = $this->crearSolicitud($solicitante);

        $solicitud = $servicio->aprobarPaso($solicitud, $jefe);

        // El segundo paso, que también le tocaría, ya no es suyo: se salta (no decide dos pasos) y,
        // como ya hubo una aprobación, el paso final de RH no se requiere.
        $this->assertSame('approved', $solicitud->status);
        $this->assertSame(VacationRequestApproval::SKIPPED, $solicitud->approvals()->where('step_order', 2)->first()->decision);
        $this->assertSame(CadenaAprobacionVacaciones::SIN_APROBADOR, $solicitud->approvals()->where('step_order', 2)->first()->comment);
    }

    // ===== Servicio =====

    public function test_aprobar_como_jefe_sin_usuario_deja_su_empleado_y_pasa_al_director(): void
    {
        [, $jefe, $solicitante] = $this->escenario();
        $director = User::factory()->create();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $this->regla(2, ApprovalFlowStep::TYPE_USER, ['user_id' => $director->id]);
        $servicio = app(VacationRequestService::class);

        $solicitud = $servicio->aprobarPaso($this->crearSolicitud($solicitante), $jefe);

        $paso = $solicitud->approvals()->where('step_order', 1)->first();
        $this->assertSame(VacationRequestApproval::APPROVED, $paso->decision);
        $this->assertSame($jefe->id, (int) $paso->decided_by_employee_id);
        $this->assertNull($paso->decided_by);
        $this->assertSame(2, $solicitud->current_step);

        $solicitud = $servicio->aprobarPaso($solicitud, $director);
        $this->assertSame('approved', $solicitud->status);
        $this->assertSame($director->id, (int) $solicitud->approved_by);
    }

    public function test_si_el_jefe_sin_usuario_cierra_la_cadena_queda_aprobada_sin_approved_by(): void
    {
        [, $jefe, $solicitante] = $this->escenario();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);

        $solicitud = app(VacationRequestService::class)->aprobarPaso($this->crearSolicitud($solicitante), $jefe);

        $this->assertSame('approved', $solicitud->status);
        $this->assertNull($solicitud->approved_by);
        $this->assertSame(CadenaAprobacionVacaciones::NO_REQUERIDO, $solicitud->approvals()->where('is_rh_final', true)->first()->comment);
        $dias = AttendanceRecord::where('employee_id', $solicitante->id)->where('status', 'vacation')->get();
        $this->assertCount(5, $dias);
        $this->assertTrue($dias->every(fn ($registro) => $registro->approved_by === null));
    }

    public function test_rechazar_como_jefe_exige_motivo_y_cierra_la_solicitud(): void
    {
        [, $jefe, $solicitante] = $this->escenario();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $servicio = app(VacationRequestService::class);
        $solicitud = $this->crearSolicitud($solicitante);

        try {
            $servicio->rechazar($solicitud, $jefe, '  ');
            $this->fail('Debió exigir el motivo.');
        } catch (VacacionesException $e) {
            $this->assertSame('El motivo del rechazo es obligatorio.', $e->getMessage());
        }

        $solicitud = $servicio->rechazar($solicitud, $jefe, 'Cierre de zafra');
        $this->assertSame('rejected', $solicitud->status);
        $this->assertSame('Cierre de zafra', $solicitud->rejection_reason);
        $this->assertSame($jefe->id, (int) $solicitud->approvals()->where('step_order', 1)->first()->decided_by_employee_id);
    }

    public function test_un_empleado_que_no_es_el_jefe_recibe_403_del_servicio(): void
    {
        [, , $solicitante] = $this->escenario();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $solicitud = $this->crearSolicitud($solicitante);

        $this->expectException(VacacionesException::class);
        app(VacationRequestService::class)->aprobarPaso($solicitud, $this->empleado($this->empresa));
    }

    // ===== API del portal =====

    public function test_yo_indica_si_el_empleado_es_jefe(): void
    {
        [, $jefe, $solicitante] = $this->escenario();

        $this->getJson(self::BASE.'/yo', $this->entrar($jefe))->assertOk()->assertJsonPath('data.es_jefe', true);
        $this->getJson(self::BASE.'/yo', $this->entrar($solicitante))->assertOk()->assertJsonPath('data.es_jefe', false);
    }

    public function test_el_listado_solo_trae_lo_que_le_toca_al_jefe_ahora(): void
    {
        [, $jefe, $solicitante] = $this->escenario();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $suya = $this->crearSolicitud($solicitante);
        $this->crearSolicitud($jefe, 2); // la del propio jefe no entra

        $ajeno = $this->empleado($this->empresa, ['department_id' => $this->departamento($this->empresa, 'OTR')->id]);
        $this->crearSolicitud($ajeno, 4); // otro departamento: sin jefe, no entra

        $respuesta = $this->getJson(self::BASE.'/por-revisar', $this->entrar($jefe))->assertOk();

        $this->assertSame([$suya->id], collect($respuesta->json('data'))->pluck('id')->all());
    }

    public function test_el_listado_es_una_lista_blanca_sin_saldo_ni_datos_personales(): void
    {
        [, $jefe, $solicitante] = $this->escenario();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $this->crearSolicitud($solicitante);

        $item = $this->getJson(self::BASE.'/por-revisar', $this->entrar($jefe))->assertOk()->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'folio', 'solicitante', 'numero', 'puesto', 'departamento', 'fecha_inicio', 'fecha_fin', 'dias', 'adelanto', 'motivo', 'solicitada_el', 'pasos'],
            array_keys($item),
        );
        $this->assertSame($solicitante->full_name, $item['solicitante']);
        $this->assertSame('Descanso', $item['motivo']);
        $this->assertSame(5, $item['dias']);
    }

    public function test_un_empleado_que_no_es_jefe_ve_la_lista_vacia_y_no_puede_decidir(): void
    {
        [, , $solicitante] = $this->escenario();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $solicitud = $this->crearSolicitud($solicitante);
        $companero = $this->empleado($this->empresa, ['department_id' => $solicitante->department_id]);
        $encabezados = $this->entrar($companero);

        $this->getJson(self::BASE.'/por-revisar', $encabezados)->assertOk()->assertJsonPath('data', []);
        $this->getJson(self::BASE.'/por-revisar/'.$solicitud->id, $encabezados)->assertStatus(404);
        $this->postJson(self::BASE.'/por-revisar/'.$solicitud->id.'/aprobar', [], $encabezados)->assertStatus(404);
        $this->postJson(self::BASE.'/por-revisar/'.$solicitud->id.'/rechazar', ['reason' => 'No'], $encabezados)->assertStatus(404);
        $this->assertSame('pending', $solicitud->fresh()->status);
    }

    public function test_una_solicitud_ajena_o_inexistente_da_el_mismo_404(): void
    {
        [, $jefe, $solicitante] = $this->escenario();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $ajeno = $this->empleado($this->empresa, ['department_id' => $this->departamento($this->empresa, 'OTR')->id]);
        $deOtro = $this->crearSolicitud($ajeno);
        $encabezados = $this->entrar($jefe);

        $esperado = $this->getJson(self::BASE.'/por-revisar/999999', $encabezados)->assertStatus(404)->json();
        $this->getJson(self::BASE.'/por-revisar/'.$deOtro->id, $encabezados)->assertStatus(404)->assertExactJson($esperado);
    }

    public function test_el_jefe_aprueba_por_el_portal_y_la_solicitud_sigue_su_cadena(): void
    {
        [, $jefe, $solicitante] = $this->escenario();
        $director = User::factory()->create();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $this->regla(2, ApprovalFlowStep::TYPE_USER, ['user_id' => $director->id]);
        $solicitud = $this->crearSolicitud($solicitante);
        $encabezados = $this->entrar($jefe);

        $this->getJson(self::BASE.'/por-revisar/'.$solicitud->id, $encabezados)->assertOk()->assertJsonPath('data.id', $solicitud->id);
        $this->postJson(self::BASE.'/por-revisar/'.$solicitud->id.'/aprobar', [], $encabezados)
            ->assertOk()
            ->assertJsonPath('data.estado', 'pending');

        $this->assertSame(2, $solicitud->fresh()->current_step);
        // Ya no le toca: sale de su lista y de su detalle.
        $this->getJson(self::BASE.'/por-revisar', $encabezados)->assertOk()->assertJsonPath('data', []);
        $this->getJson(self::BASE.'/por-revisar/'.$solicitud->id, $encabezados)->assertStatus(404);
        $this->postJson(self::BASE.'/por-revisar/'.$solicitud->id.'/aprobar', [], $encabezados)->assertStatus(404);
    }

    public function test_el_jefe_rechaza_por_el_portal_con_motivo(): void
    {
        [, $jefe, $solicitante] = $this->escenario();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $solicitud = $this->crearSolicitud($solicitante);
        $encabezados = $this->entrar($jefe);

        $this->postJson(self::BASE.'/por-revisar/'.$solicitud->id.'/rechazar', [], $encabezados)
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->assertSame('pending', $solicitud->fresh()->status);

        $this->postJson(self::BASE.'/por-revisar/'.$solicitud->id.'/rechazar', ['reason' => 'Cierre de zafra'], $encabezados)
            ->assertOk()->assertJsonPath('data.estado', 'rejected');

        $this->assertSame('Cierre de zafra', $solicitud->fresh()->rejection_reason);
    }

    public function test_el_solicitante_ve_el_nombre_del_jefe_que_decidio(): void
    {
        [, $jefe, $solicitante] = $this->escenario();
        $director = User::factory()->create();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $this->regla(2, ApprovalFlowStep::TYPE_USER, ['user_id' => $director->id]);
        $solicitud = $this->crearSolicitud($solicitante);
        $this->postJson(self::BASE.'/por-revisar/'.$solicitud->id.'/aprobar', [], $this->entrar($jefe))->assertOk();

        $this->getJson(self::BASE.'/solicitudes/'.$solicitud->id, $this->entrar($solicitante))
            ->assertOk()
            ->assertJsonPath('data.pasos.0.decidido_por', $jefe->full_name);
    }

    public function test_un_jefe_con_usuario_tambien_decide_por_el_portal_y_queda_su_usuario(): void
    {
        [, $jefe, $solicitante] = $this->escenario(true);
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $solicitud = $this->crearSolicitud($solicitante);

        $this->postJson(self::BASE.'/por-revisar/'.$solicitud->id.'/aprobar', [], $this->entrar($jefe))->assertOk()->assertJsonPath('data.estado', 'approved');

        $paso = $solicitud->approvals()->where('step_order', 1)->first();
        $this->assertSame($jefe->id, (int) $paso->decided_by_employee_id);
        $this->assertSame($jefe->user_id, (int) $paso->decided_by);
        $this->assertSame($jefe->user_id, (int) $solicitud->fresh()->approved_by);
    }

    public function test_sin_token_del_portal_no_hay_acceso(): void
    {
        $this->getJson(self::BASE.'/por-revisar')->assertStatus(401);
        $this->postJson(self::BASE.'/por-revisar/1/aprobar')->assertStatus(401);
    }

    // ===== Avisos =====

    public function test_al_crear_la_solicitud_el_jefe_sin_usuario_recibe_un_correo_con_el_enlace_al_portal(): void
    {
        Mail::fake();
        [, $jefe, $solicitante] = $this->escenario();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);

        $this->crearSolicitud($solicitante);

        Mail::assertSent(VacationReviewMail::class, fn (VacationReviewMail $mail) => $mail->hasTo('jefe@ejemplo.com')
            && str_contains($mail->render(), config('vacaciones.portal_url'))
            && str_contains($mail->render(), $solicitante->full_name));
    }

    public function test_el_jefe_con_usuario_tambien_recibe_un_solo_correo_con_la_bandeja_y_el_portal(): void
    {
        Mail::fake();
        [, , $solicitante] = $this->escenario(true);
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);

        $this->crearSolicitud($solicitante);

        Mail::assertSent(VacationReviewMail::class, 1);
        Mail::assertSent(VacationReviewMail::class, fn (VacationReviewMail $mail) => $mail->hasTo('jefe@ejemplo.com')
            && $mail->via === VacationReviewMail::VIA_PORTAL_O_SENTINEL);
    }

    public function test_un_jefe_sin_correo_valido_no_rompe_la_creacion(): void
    {
        Mail::fake();
        [, $jefe, $solicitante] = $this->escenario();
        $jefe->update(['email' => 'no-es-un-correo']);
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);

        $solicitud = $this->crearSolicitud($solicitante);

        $this->assertSame('pending', $solicitud->status);
        Mail::assertNotSent(VacationReviewMail::class);
    }

    public function test_al_llegar_al_paso_del_jefe_tras_otro_paso_tambien_se_le_avisa(): void
    {
        Mail::fake();
        [, , $solicitante] = $this->escenario();
        $director = User::factory()->create();
        $this->regla(1, ApprovalFlowStep::TYPE_USER, ['user_id' => $director->id]);
        $this->regla(2, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $solicitud = $this->crearSolicitud($solicitante);
        // Al crear solo le toca al director (paso 1).
        Mail::assertSent(VacationReviewMail::class, 1);
        Mail::assertNotSent(VacationReviewMail::class, fn (VacationReviewMail $mail) => $mail->hasTo('jefe@ejemplo.com'));

        app(VacationRequestService::class)->aprobarPaso($solicitud, $director);

        Mail::assertSent(VacationReviewMail::class, fn (VacationReviewMail $mail) => $mail->hasTo('jefe@ejemplo.com')
            && $mail->via === VacationReviewMail::VIA_PORTAL);
    }
}
