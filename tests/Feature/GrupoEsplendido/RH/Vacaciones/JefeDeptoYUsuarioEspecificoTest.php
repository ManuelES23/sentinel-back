<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\Employee;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\RH\AprobadorVacaciones;
use App\Services\RH\CadenaAprobacionVacaciones;
use App\Services\RH\VacationRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/** Pasos «Jefe del departamento» (departments.manager_id) y «Usuario específico» (approval_flow_steps.user_id). */
class JefeDeptoYUsuarioEspecificoTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    private function aprobador(): AprobadorVacaciones
    {
        return app(AprobadorVacaciones::class);
    }

    private function paso(VacationRequest $solicitud, array $reglas, int $orden = 1): VacationRequestApproval
    {
        return $solicitud->approvals()->create([
            'step_order' => $orden, 'description' => 'Paso '.$orden,
            'rules' => collect($reglas)->map(fn (ApprovalFlowStep $r) => $r->id)->all(),
            'is_rh_final' => false, 'decision' => VacationRequestApproval::WAITING,
        ]);
    }

    /** Departamento con su jefe (empleado con usuario) y un solicitante dentro de él. */
    private function deptoConJefe(): array
    {
        $depto = $this->departamento($this->empresa, 'VTA');
        $jefe = $this->empleadoConUsuario($this->empresa, ['department_id' => $depto->id]);
        $depto->update(['manager_id' => $jefe->id]);
        $solicitante = $this->empleado($this->empresa, ['department_id' => $depto->id]);

        return [$depto, $jefe, $solicitante];
    }

    private function director(array $atributos = []): User
    {
        return User::factory()->create($atributos);
    }

    public function test_el_jefe_del_departamento_decide_y_otro_no(): void
    {
        [, $jefe, $solicitante] = $this->deptoConJefe();
        $otro = $this->empleadoConUsuario($this->empresa);
        $solicitud = $this->solicitud($solicitante, 'pending', 3);
        $paso = $this->paso($solicitud, [$this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER)]);

        $this->assertTrue($this->aprobador()->puedeDecidir($jefe->user, $solicitud, $paso));
        $this->assertFalse($this->aprobador()->puedeDecidir($otro->user, $solicitud, $paso));
        $this->assertSame([$jefe->user_id], $this->aprobador()->elegiblesDelPaso($solicitud, $paso)->pluck('id')->all());
    }

    public function test_funciona_aunque_el_departamento_se_haya_cargado_sin_manager_id(): void
    {
        [, $jefe, $solicitante] = $this->deptoConJefe();
        $solicitud = $this->solicitud($solicitante, 'pending', 3);
        $paso = $this->paso($solicitud, [$this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER)]);
        $solicitud->load('employee.department:id,name');

        $this->assertTrue($this->aprobador()->puedeDecidir($jefe->user, $solicitud, $paso));
    }

    public function test_departamento_sin_jefe_o_solicitante_sin_departamento_no_tiene_elegibles(): void
    {
        $sinJefe = $this->departamento($this->empresa, 'SIN');
        $regla = $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);

        $enDeptoSinJefe = $this->solicitud($this->empleado($this->empresa, ['department_id' => $sinJefe->id]), 'pending', 2);
        $sinDepto = $this->solicitud($this->empleado($this->empresa), 'pending', 2);

        $this->assertTrue($this->aprobador()->elegiblesDelPaso($enDeptoSinJefe, $this->paso($enDeptoSinJefe, [$regla]))->isEmpty());
        $this->assertTrue($this->aprobador()->elegiblesDelPaso($sinDepto, $this->paso($sinDepto, [$regla]))->isEmpty());
    }

    public function test_el_jefe_del_departamento_no_aprueba_su_propia_solicitud(): void
    {
        [, $jefe] = $this->deptoConJefe();
        $suya = $this->solicitud($jefe, 'pending', 2);
        $paso = $this->paso($suya, [$this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER)]);

        $this->assertFalse($this->aprobador()->puedeDecidir($jefe->user, $suya, $paso));
        $this->assertTrue($this->aprobador()->elegiblesDelPaso($suya, $paso)->isEmpty());
    }

    public function test_un_jefe_de_departamento_sin_usuario_o_dado_de_baja_no_es_elegible(): void
    {
        [$depto, $jefe, $solicitante] = $this->deptoConJefe();
        $solicitud = $this->solicitud($solicitante, 'pending', 2);
        $paso = $this->paso($solicitud, [$this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER)]);

        $jefe->update(['status' => Employee::STATUS_TERMINATED]);
        $this->assertTrue($this->aprobador()->elegiblesDelPaso($solicitud, $paso)->isEmpty());

        $sinUsuario = $this->empleado($this->empresa, ['department_id' => $depto->id]);
        $depto->update(['manager_id' => $sinUsuario->id]);
        $this->assertTrue($this->aprobador()->elegiblesDelPaso($solicitud, $paso)->isEmpty());
    }

    public function test_el_usuario_especifico_decide_sin_necesidad_de_ser_empleado(): void
    {
        $director = $this->director();
        $otro = $this->empleadoConUsuario($this->empresa);
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);
        $paso = $this->paso($solicitud, [$this->regla(1, ApprovalFlowStep::TYPE_USER, ['user_id' => $director->id])]);

        $this->assertNull($director->employee()->first());
        $this->assertTrue($this->aprobador()->puedeDecidir($director, $solicitud, $paso));
        $this->assertFalse($this->aprobador()->puedeDecidir($otro->user, $solicitud, $paso));
        $this->assertSame([$director->id], $this->aprobador()->elegiblesDelPaso($solicitud, $paso)->pluck('id')->all());
    }

    public function test_el_usuario_especifico_con_empleado_de_otra_empresa_tambien_puede(): void
    {
        $directorEmpleado = $this->empleadoConUsuario($this->corporativo);
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);
        $paso = $this->paso($solicitud, [$this->regla(1, ApprovalFlowStep::TYPE_USER, ['user_id' => $directorEmpleado->user_id])]);

        $this->assertTrue($this->aprobador()->puedeDecidir($directorEmpleado->user, $solicitud, $paso));
    }

    public function test_el_usuario_especifico_con_empleado_dado_de_baja_no_decide(): void
    {
        $directorEmpleado = $this->empleadoConUsuario($this->corporativo, ['status' => Employee::STATUS_TERMINATED]);
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);
        $paso = $this->paso($solicitud, [$this->regla(1, ApprovalFlowStep::TYPE_USER, ['user_id' => $directorEmpleado->user_id])]);

        $this->assertFalse($this->aprobador()->puedeDecidir($directorEmpleado->user, $solicitud, $paso));
        $this->assertTrue($this->aprobador()->elegiblesDelPaso($solicitud, $paso)->isEmpty());
    }

    public function test_el_usuario_especifico_no_aprueba_lo_suyo_ni_dos_pasos(): void
    {
        $directorEmpleado = $this->empleadoConUsuario($this->empresa);
        $regla = $this->regla(2, ApprovalFlowStep::TYPE_USER, ['user_id' => $directorEmpleado->user_id]);

        $suya = $this->solicitud($directorEmpleado, 'pending', 2);
        $this->assertFalse($this->aprobador()->puedeDecidir($directorEmpleado->user, $suya, $this->paso($suya, [$regla], 2)));

        $ajena = $this->solicitud($this->empleado($this->empresa), 'pending', 2);
        $this->paso($ajena, [$regla], 1)->update(['decision' => VacationRequestApproval::APPROVED, 'decided_by' => $directorEmpleado->user_id]);
        $this->assertFalse($this->aprobador()->puedeDecidir($directorEmpleado->user, $ajena, $this->paso($ajena, [$regla], 2)));
    }

    public function test_el_usuario_especifico_respeta_can_reject(): void
    {
        $director = $this->director();
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);
        $paso = $this->paso($solicitud, [$this->regla(1, ApprovalFlowStep::TYPE_USER, ['user_id' => $director->id, 'can_reject' => false])]);

        $this->assertTrue($this->aprobador()->puedeDecidir($director, $solicitud, $paso));
        $this->assertFalse($this->aprobador()->puedeDecidir($director, $solicitud, $paso, 'rechazar'));
    }

    public function test_una_regla_de_usuario_sin_usuario_no_deja_elegibles(): void
    {
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);
        $paso = $this->paso($solicitud, [$this->regla(1, ApprovalFlowStep::TYPE_USER)]);

        $this->assertTrue($this->aprobador()->elegiblesDelPaso($solicitud, $paso)->isEmpty());
    }

    public function test_las_alternativas_del_mismo_paso_mezclan_empleados_y_usuarios(): void
    {
        [, $jefe, $solicitante] = $this->deptoConJefe();
        $director = $this->director();
        $solicitud = $this->solicitud($solicitante, 'pending', 3);
        $paso = $this->paso($solicitud, [
            $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER),
            $this->regla(1, ApprovalFlowStep::TYPE_USER, ['user_id' => $director->id]),
        ]);

        $this->assertEqualsCanonicalizing([$jefe->user_id, $director->id], $this->aprobador()->elegiblesDelPaso($solicitud, $paso)->pluck('id')->all());
    }

    public function test_la_cadena_completa_jefe_del_departamento_luego_director_luego_aprueba(): void
    {
        [, $jefe, $solicitante] = $this->deptoConJefe();
        $director = $this->director();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $this->regla(2, ApprovalFlowStep::TYPE_USER, ['user_id' => $director->id]);
        $lunes = Carbon::now()->next(Carbon::MONDAY)->addWeeks(2);
        $servicio = app(VacationRequestService::class);

        $solicitud = $servicio->crear($solicitante, $lunes, $lunes->copy()->addDays(4), null, VacationRequest::SOURCE_RH);

        $pasos = $solicitud->approvals()->get();
        $this->assertSame(['Jefe del departamento', 'Usuario: '.$director->name, CadenaAprobacionVacaciones::DESCRIPCION_RH], $pasos->pluck('description')->all());
        $this->assertSame(1, $solicitud->fresh()->current_step);

        $solicitud = $servicio->aprobarPaso($solicitud, $jefe->user);
        $this->assertSame('pending', $solicitud->status);
        $this->assertSame(2, $solicitud->current_step);

        $solicitud = $servicio->aprobarPaso($solicitud, $director);
        $this->assertSame('approved', $solicitud->status);
        $this->assertSame($director->id, (int) $solicitud->approved_by);
    }

    public function test_si_el_departamento_no_tiene_jefe_la_solicitud_pasa_directo_al_director(): void
    {
        $director = $this->director();
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $this->regla(2, ApprovalFlowStep::TYPE_USER, ['user_id' => $director->id]);
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);

        app(CadenaAprobacionVacaciones::class)->construir($solicitud);

        $primero = $solicitud->approvals()->where('step_order', 1)->first();
        $this->assertSame(VacationRequestApproval::SKIPPED, $primero->decision);
        $this->assertSame(2, $solicitud->fresh()->current_step);
    }

    // ===== Configuración (/admin/approval-processes) =====

    private function comoAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function otroProceso(): ApprovalProcess
    {
        return ApprovalProcess::firstOrCreate(
            ['code' => 'purchase_orders'],
            [
                'name' => 'Órdenes de compra', 'description' => 'Aprobación de órdenes de compra', 'module' => 'compras',
                'entity_type' => PurchaseOrder::class, 'requires_approval' => true, 'is_active' => true,
            ],
        );
    }

    public function test_se_agrega_jefe_del_departamento_en_vacaciones(): void
    {
        $this->comoAdmin();

        $this->postJson("/api/admin/approval-processes/{$this->procesoVacaciones->id}/steps", [
            'approver_type' => 'department_manager', 'step_order' => 1, 'position_id' => 999, 'min_hierarchy_level' => 3,
        ])->assertCreated()->assertJsonPath('data.approver_type', 'department_manager');

        $regla = ApprovalFlowStep::where('approver_type', 'department_manager')->firstOrFail();
        $this->assertNull($regla->position_id);
        $this->assertNull($regla->min_hierarchy_level);
        $this->assertNull($regla->user_id);
    }

    public function test_se_agrega_usuario_especifico_y_la_respuesta_trae_al_usuario(): void
    {
        $this->comoAdmin();
        $director = $this->director();

        $this->postJson("/api/admin/approval-processes/{$this->procesoVacaciones->id}/steps", [
            'approver_type' => 'user', 'step_order' => 2, 'user_id' => $director->id,
        ])->assertCreated()
            ->assertJsonPath('data.user_id', $director->id)
            ->assertJsonPath('data.user.name', $director->name);
    }

    public function test_usuario_especifico_exige_un_usuario_que_exista(): void
    {
        $this->comoAdmin();
        $url = "/api/admin/approval-processes/{$this->procesoVacaciones->id}/steps";

        $this->postJson($url, ['approver_type' => 'user', 'step_order' => 2])->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->postJson($url, ['approver_type' => 'user', 'step_order' => 2, 'user_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertSame(0, ApprovalFlowStep::where('approver_type', 'user')->count());
    }

    public function test_los_tipos_nuevos_solo_se_aceptan_en_vacaciones(): void
    {
        $this->comoAdmin();
        $otro = $this->otroProceso();
        $director = $this->director();

        $this->postJson("/api/admin/approval-processes/{$otro->id}/steps", ['approver_type' => 'department_manager', 'step_order' => 1])
            ->assertStatus(422)->assertJsonPath('message', '«Jefe del departamento» solo se puede usar en el proceso de vacaciones.');
        $this->postJson("/api/admin/approval-processes/{$otro->id}/steps", ['approver_type' => 'user', 'step_order' => 1, 'user_id' => $director->id])
            ->assertStatus(422)->assertJsonPath('message', '«Usuario específico» solo se puede usar en el proceso de vacaciones.');

        $regla = ApprovalFlowStep::create([
            'approval_process_id' => $otro->id, 'step_order' => 1, 'approver_type' => 'hierarchy_level',
            'min_hierarchy_level' => 3, 'approval_scope' => 'enterprise', 'can_approve' => true, 'can_reject' => true, 'is_active' => true,
        ]);
        $this->putJson("/api/admin/approval-processes/{$otro->id}/steps/{$regla->id}", ['approver_type' => 'user', 'user_id' => $director->id])
            ->assertStatus(422);
        $this->assertSame('hierarchy_level', $regla->fresh()->approver_type);
        $this->assertSame(0, ApprovalFlowStep::where('approval_process_id', $otro->id)->where('approver_type', '!=', 'hierarchy_level')->count());
    }

    public function test_cambiar_el_tipo_de_una_regla_limpia_el_usuario_y_el_puesto(): void
    {
        $this->comoAdmin();
        $director = $this->director();
        $puesto = $this->puesto($this->empresa, 'Gerente', 4, true);
        $regla = $this->regla(2, ApprovalFlowStep::TYPE_USER, ['user_id' => $director->id]);
        $url = "/api/admin/approval-processes/{$this->procesoVacaciones->id}/steps/{$regla->id}";

        $this->putJson($url, ['approver_type' => 'position', 'position_id' => $puesto->id])->assertOk();
        $this->assertNull($regla->fresh()->user_id);

        $this->putJson($url, ['approver_type' => 'user', 'user_id' => $director->id])->assertOk();
        $this->assertNull($regla->fresh()->position_id);
        $this->assertSame($director->id, (int) $regla->fresh()->user_id);

        $this->putJson($url, ['approver_type' => 'department_manager'])->assertOk();
        $this->assertNull($regla->fresh()->user_id);
    }

    public function test_el_listado_de_reglas_incluye_al_usuario_y_form_data_ofrece_usuarios(): void
    {
        $this->comoAdmin();
        $director = $this->director();
        $this->regla(2, ApprovalFlowStep::TYPE_USER, ['user_id' => $director->id]);

        $this->getJson("/api/admin/approval-processes/{$this->procesoVacaciones->id}/steps")
            ->assertOk()->assertJsonPath('data.0.user.id', $director->id);
        $this->getJson("/api/admin/approval-processes/{$this->procesoVacaciones->id}")
            ->assertOk()->assertJsonPath('data.steps.0.user.id', $director->id);

        $usuarios = $this->getJson('/api/admin/approval-processes/form-data')->assertOk()->json('data.approver_users');
        $this->assertContains($director->id, collect($usuarios)->pluck('id')->all());
    }
}
