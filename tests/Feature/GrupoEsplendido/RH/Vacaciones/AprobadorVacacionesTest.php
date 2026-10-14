<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\RH\AprobadorVacaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class AprobadorVacacionesTest extends TestCase
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

    private function paso(VacationRequest $solicitud, array $reglas, int $orden = 1, bool $rhFinal = false): VacationRequestApproval
    {
        return $solicitud->approvals()->create([
            'step_order' => $orden, 'description' => 'Paso '.$orden,
            'rules' => collect($reglas)->map(fn (ApprovalFlowStep $r) => $r->id)->all(),
            'is_rh_final' => $rhFinal, 'decision' => VacationRequestApproval::WAITING,
        ]);
    }

    public function test_el_jefe_directo_decide_y_otro_no(): void
    {
        $jefe = $this->empleadoConUsuario($this->empresa);
        $otro = $this->empleadoConUsuario($this->empresa);
        $solicitante = $this->empleado($this->empresa, ['reports_to' => $jefe->id]);
        $solicitud = $this->solicitud($solicitante, 'pending', 3);
        $paso = $this->paso($solicitud, [$this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR)]);

        $this->assertTrue($this->aprobador()->puedeDecidir($jefe->user, $solicitud, $paso));
        $this->assertFalse($this->aprobador()->puedeDecidir($otro->user, $solicitud, $paso));
        $this->assertSame([$jefe->user_id], $this->aprobador()->elegiblesDelPaso($solicitud, $paso)->pluck('id')->all());
    }

    public function test_un_jefe_sin_usuario_no_es_elegible(): void
    {
        $jefe = $this->empleado($this->empresa);
        $solicitud = $this->solicitud($this->empleado($this->empresa, ['reports_to' => $jefe->id]), 'pending', 3);
        $paso = $this->paso($solicitud, [$this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR)]);

        $this->assertTrue($this->aprobador()->elegiblesDelPaso($solicitud, $paso)->isEmpty());
    }

    public function test_el_puesto_respeta_el_departamento_y_no_truena_sin_departamento(): void
    {
        $deptoA = $this->departamento($this->empresa, 'A');
        $deptoB = $this->departamento($this->empresa, 'B');
        $gerente = $this->puesto($this->empresa, 'Gerente', 4, true, 'own_department');
        $aprobador = $this->empleadoConUsuario($this->empresa, ['position_id' => $gerente->id, 'department_id' => $deptoA->id]);
        $regla = $this->regla(1, 'position', ['position_id' => $gerente->id, 'approval_scope' => 'own_department']);

        $mismoDepto = $this->solicitud($this->empleado($this->empresa, ['department_id' => $deptoA->id]), 'pending', 2);
        $otroDepto = $this->solicitud($this->empleado($this->empresa, ['department_id' => $deptoB->id]), 'pending', 2);
        $sinDepto = $this->solicitud($this->empleado($this->empresa), 'pending', 2);

        $this->assertTrue($this->aprobador()->puedeDecidir($aprobador->user, $mismoDepto, $this->paso($mismoDepto, [$regla])));
        $this->assertFalse($this->aprobador()->puedeDecidir($aprobador->user, $otroDepto, $this->paso($otroDepto, [$regla])));
        $this->assertFalse($this->aprobador()->puedeDecidir($aprobador->user, $sinDepto, $this->paso($sinDepto, [$regla])));

        $hijos = $this->regla(1, 'position', ['position_id' => $gerente->id, 'approval_scope' => 'child_departments']);
        $this->assertFalse($this->aprobador()->puedeDecidir($aprobador->user, $sinDepto, $this->paso($sinDepto, [$hijos], 2)));
    }

    public function test_el_corporativo_aprueba_otra_empresa_solo_por_puesto(): void
    {
        $coordinador = $this->puesto($this->corporativo, 'Coordinador de RH', 3, true, 'enterprise');
        $rh = $this->empleadoConUsuario($this->corporativo, ['position_id' => $coordinador->id]);
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);

        $general = $this->paso($solicitud, [$this->regla(1, 'position', ['position_id' => $coordinador->id])]);
        $this->assertTrue($this->aprobador()->puedeDecidir($rh->user, $solicitud, $general));

        $otraEmpresa = Enterprise::create(['name' => 'Porvenir', 'slug' => 'splendidbyporvenir', 'description' => 'x', 'is_active' => true]);
        $deOtra = $this->paso($solicitud, [$this->regla(2, 'position', ['position_id' => $coordinador->id, 'enterprise_id' => $otraEmpresa->id])], 2);
        $this->assertFalse($this->aprobador()->puedeDecidir($rh->user, $solicitud, $deOtra));

        $porNivel = $this->paso($solicitud, [$this->regla(3, 'hierarchy_level', ['min_hierarchy_level' => 3])], 3);
        $this->assertFalse($this->aprobador()->puedeDecidir($rh->user, $solicitud, $porNivel));
    }

    public function test_nadie_decide_su_propia_solicitud(): void
    {
        $gerente = $this->puesto($this->empresa, 'Gerente', 2, true, 'enterprise');
        $solicitante = $this->empleadoConUsuario($this->empresa, ['position_id' => $gerente->id]);
        $solicitud = $this->solicitud($solicitante, 'pending', 3);
        $paso = $this->paso($solicitud, [$this->regla(1, 'hierarchy_level', ['min_hierarchy_level' => 3])]);

        $this->assertFalse($this->aprobador()->puedeDecidir($solicitante->user, $solicitud, $paso));
    }

    public function test_el_paso_de_rh_lo_decide_quien_tiene_el_permiso_aprobar(): void
    {
        $rh = User::factory()->create();
        $this->concederVacaciones($rh, 'aprobar');
        $sinPermiso = User::factory()->create();
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);
        $paso = $this->paso($solicitud, [], 1, true);

        $this->assertTrue($this->aprobador()->puedeDecidir($rh, $solicitud, $paso));
        $this->assertFalse($this->aprobador()->puedeDecidir($sinPermiso, $solicitud, $paso));
        $this->assertSame([$rh->id], $this->aprobador()->elegiblesDelPaso($solicitud, $paso)->pluck('id')->all());

        $propia = $this->solicitud($this->empleado($this->corporativo, ['user_id' => $rh->id]), 'pending', 3);
        $this->assertFalse($this->aprobador()->puedeDecidir($rh, $propia, $this->paso($propia, [], 1, true)));
    }

    public function test_un_usuario_con_dos_empleados_no_decide_la_solicitud_de_ninguno(): void
    {
        $gerente = $this->puesto($this->empresa, 'Gerente', 2, true, 'enterprise');
        $usuario = User::factory()->create();
        $this->concederVacaciones($usuario, 'aprobar');
        $this->empleado($this->empresa, ['position_id' => $gerente->id, 'user_id' => $usuario->id]);
        $segundo = $this->empleado($this->empresa, ['user_id' => $usuario->id]);
        $solicitud = $this->solicitud($segundo, 'pending', 3);

        $regular = $this->paso($solicitud, [$this->regla(1, 'hierarchy_level', ['min_hierarchy_level' => 3])]);
        $this->assertFalse($this->aprobador()->puedeDecidir($usuario, $solicitud, $regular));

        $rh = $this->paso($solicitud, [], 2, true);
        $this->assertFalse($this->aprobador()->puedeDecidir($usuario, $solicitud, $rh));
        $this->assertTrue($this->aprobador()->elegiblesDelPaso($solicitud, $rh)->isEmpty());
    }

    public function test_quien_ya_decidio_un_paso_no_decide_otro(): void
    {
        $gerente = $this->puesto($this->empresa, 'Gerente', 2, true, 'enterprise');
        $jefe = $this->empleadoConUsuario($this->empresa, ['position_id' => $gerente->id]);
        $solicitud = $this->solicitud($this->empleado($this->empresa, ['reports_to' => $jefe->id]), 'pending', 3);
        $paso1 = $this->paso($solicitud, [$this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR)]);
        $paso2 = $this->paso($solicitud, [$this->regla(2, 'position', ['position_id' => $gerente->id])], 2);
        $paso1->update(['decision' => VacationRequestApproval::APPROVED, 'decided_by' => $jefe->user_id, 'decided_at' => now()]);

        $this->assertFalse($this->aprobador()->puedeDecidir($jefe->user, $solicitud, $paso2));
    }

    public function test_una_regla_que_no_permite_rechazar(): void
    {
        $jefe = $this->empleadoConUsuario($this->empresa);
        $solicitud = $this->solicitud($this->empleado($this->empresa, ['reports_to' => $jefe->id]), 'pending', 3);
        $paso = $this->paso($solicitud, [$this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR, ['can_reject' => false])]);

        $this->assertTrue($this->aprobador()->puedeDecidir($jefe->user, $solicitud, $paso, 'aprobar'));
        $this->assertFalse($this->aprobador()->puedeDecidir($jefe->user, $solicitud, $paso, 'rechazar'));
    }

    public function test_pasos_para_agrupa_por_orden_y_filtra_por_empresa(): void
    {
        $otraEmpresa = Enterprise::create(['name' => 'Porvenir', 'slug' => 'splendidbyporvenir', 'description' => 'x', 'is_active' => true]);
        $gerente = $this->puesto($this->empresa, 'Gerente', 3, true);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->regla(1, 'position', ['position_id' => $gerente->id, 'enterprise_id' => $this->empresa->id]);
        $this->regla(2, 'position', ['position_id' => $gerente->id, 'enterprise_id' => $otraEmpresa->id]);
        $this->regla(3, 'hierarchy_level', ['min_hierarchy_level' => 2]);
        $this->regla(4, 'hierarchy_level', ['min_hierarchy_level' => 2, 'is_active' => false]);

        $pasos = $this->aprobador()->pasosPara($this->empresa->id);

        $this->assertSame([1, 3], $pasos->keys()->all());
        $this->assertCount(2, $pasos[1]);
    }

    public function test_la_configuracion_acepta_jefe_directo(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $this->postJson("/api/admin/approval-processes/{$this->procesoVacaciones->id}/steps", [
            'approver_type' => 'direct_supervisor',
            'step_order' => 1,
            'position_id' => 999999,
        ])->assertCreated()
            ->assertJsonPath('data.approver_type', 'direct_supervisor')
            ->assertJsonPath('data.position_id', null);
    }
}
