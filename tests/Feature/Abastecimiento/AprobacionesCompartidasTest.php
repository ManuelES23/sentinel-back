<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * Configuración de la cadena (/api/admin/approval-processes) compartida con vacaciones y compras:
 * el proceso de liquidaciones de abastecimiento solo admite «Usuario específico» y «Puesto».
 */
class AprobacionesCompartidasTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private ApprovalProcess $liquidaciones;
    private ApprovalProcess $compras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();

        $this->liquidaciones = ApprovalProcess::firstOrCreate(
            ['code' => ApprovalProcess::SUPPLY_SETTLEMENTS],
            ['name' => 'Liquidaciones de abastecimiento', 'description' => 'Revisión de liquidaciones', 'module' => 'abastecimiento',
                'entity_type' => null, 'requires_approval' => true, 'is_active' => true],
        );
        $this->compras = ApprovalProcess::firstOrCreate(
            ['code' => 'purchase_orders'],
            ['name' => 'Órdenes de compra', 'description' => 'Aprobación de órdenes de compra', 'module' => 'compras',
                'entity_type' => PurchaseOrder::class, 'requires_approval' => true, 'is_active' => true],
        );
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function url(ApprovalProcess $proceso): string
    {
        return "/api/admin/approval-processes/{$proceso->id}/steps";
    }

    private function mensajePermitidos(): string
    {
        return 'En las liquidaciones de abastecimiento solo se pueden usar los tipos «Usuario específico» y «Puesto».';
    }

    private function reglaLiquidacion(int $paso, string $tipo, array $atributos = []): ApprovalFlowStep
    {
        return ApprovalFlowStep::create(array_merge([
            'approval_process_id' => $this->liquidaciones->id, 'enterprise_id' => null, 'step_order' => $paso,
            'approver_type' => $tipo, 'approval_scope' => 'enterprise', 'can_approve' => true, 'can_reject' => true, 'is_active' => true,
        ], $atributos));
    }

    public function test_liquidaciones_acepta_usuario_especifico_con_alcance_empresa(): void
    {
        $usuario = User::factory()->create();

        $id = $this->postJson($this->url($this->liquidaciones), [
            'approver_type' => 'user', 'step_order' => 1, 'user_id' => $usuario->id,
            'enterprise_id' => $this->empresa->id, 'approval_scope' => 'own_department',
        ])->assertCreated()->json('data.id');

        $paso = ApprovalFlowStep::findOrFail($id);
        $this->assertSame('user', $paso->approver_type);
        $this->assertSame($usuario->id, $paso->user_id);
        $this->assertSame('enterprise', $paso->approval_scope);
        $this->assertSame($this->empresa->id, $paso->enterprise_id);
    }

    public function test_liquidaciones_acepta_puesto_con_alcance_empresa(): void
    {
        $puesto = $this->puesto($this->empresa, 'Gerente de compras', 3, true);

        $id = $this->postJson($this->url($this->liquidaciones), [
            'approver_type' => 'position', 'step_order' => 2, 'position_id' => $puesto->id, 'approval_scope' => 'child_departments',
        ])->assertCreated()->json('data.id');

        $paso = ApprovalFlowStep::findOrFail($id);
        $this->assertSame('position', $paso->approver_type);
        $this->assertSame($puesto->id, $paso->position_id);
        $this->assertSame('enterprise', $paso->approval_scope);
        $this->assertNull($paso->enterprise_id);
    }

    public function test_liquidaciones_sin_alcance_en_la_peticion_tambien_guarda_empresa(): void
    {
        $puesto = $this->puesto($this->empresa, 'Contador', 4, true);

        $id = $this->postJson($this->url($this->liquidaciones), ['approver_type' => 'position', 'position_id' => $puesto->id])
            ->assertCreated()->json('data.id');

        $this->assertSame('enterprise', ApprovalFlowStep::findOrFail($id)->approval_scope);
    }

    public function test_usuario_exige_user_id_y_puesto_exige_position_id_en_liquidaciones(): void
    {
        $this->postJson($this->url($this->liquidaciones), ['approver_type' => 'user', 'step_order' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->postJson($this->url($this->liquidaciones), ['approver_type' => 'position', 'step_order' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('position_id');

        $this->assertSame(0, ApprovalFlowStep::where('approval_process_id', $this->liquidaciones->id)->count());
    }

    public function test_liquidaciones_rechaza_los_demas_tipos_con_mensaje_claro(): void
    {
        foreach (['direct_supervisor', 'department_manager', 'rh_review', 'hierarchy_level'] as $tipo) {
            $this->postJson($this->url($this->liquidaciones), ['approver_type' => $tipo, 'step_order' => 1, 'min_hierarchy_level' => 2])
                ->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', $this->mensajePermitidos());
        }

        $this->assertSame(0, ApprovalFlowStep::where('approval_process_id', $this->liquidaciones->id)->count());
    }

    public function test_actualizar_no_puede_mover_un_paso_a_un_tipo_no_permitido(): void
    {
        $usuario = User::factory()->create();
        $paso = $this->reglaLiquidacion(1, 'user', ['user_id' => $usuario->id]);

        foreach (['direct_supervisor', 'department_manager', 'rh_review', 'hierarchy_level'] as $tipo) {
            $this->putJson("{$this->url($this->liquidaciones)}/{$paso->id}", ['approver_type' => $tipo, 'min_hierarchy_level' => 2])
                ->assertStatus(422)->assertJsonPath('message', $this->mensajePermitidos());
        }

        $paso->refresh();
        $this->assertSame('user', $paso->approver_type);
        $this->assertSame($usuario->id, $paso->user_id);
    }

    public function test_actualizar_entre_usuario_y_puesto_limpia_campos_y_conserva_alcance_empresa(): void
    {
        $usuario = User::factory()->create();
        $puesto = $this->puesto($this->empresa, 'Tesorero', 3, true);
        $paso = $this->reglaLiquidacion(1, 'user', ['user_id' => $usuario->id]);

        $this->putJson("{$this->url($this->liquidaciones)}/{$paso->id}", [
            'approver_type' => 'position', 'position_id' => $puesto->id, 'approval_scope' => 'own_department',
        ])->assertOk();

        $paso->refresh();
        $this->assertSame('position', $paso->approver_type);
        $this->assertSame($puesto->id, $paso->position_id);
        $this->assertNull($paso->user_id);
        $this->assertSame('enterprise', $paso->approval_scope);

        // Cambiar a usuario sin user_id: sigue el guard de siempre.
        $this->putJson("{$this->url($this->liquidaciones)}/{$paso->id}", ['approver_type' => 'user'])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');

        // Cambiar a puesto sin puesto: tampoco queda un paso vacío.
        $otro = $this->reglaLiquidacion(2, 'user', ['user_id' => $usuario->id]);
        $this->putJson("{$this->url($this->liquidaciones)}/{$otro->id}", ['approver_type' => 'position'])
            ->assertStatus(422)->assertJsonValidationErrors('position_id');
        $this->assertSame('user', $otro->fresh()->approver_type);
    }

    public function test_actualizar_solo_el_orden_o_el_alcance_de_un_paso_valido_funciona(): void
    {
        $paso = $this->reglaLiquidacion(1, 'user', ['user_id' => User::factory()->create()->id]);

        $this->putJson("{$this->url($this->liquidaciones)}/{$paso->id}", ['step_order' => 3, 'approval_scope' => 'own_department'])
            ->assertOk();

        $paso->refresh();
        $this->assertSame(3, $paso->step_order);
        $this->assertSame('enterprise', $paso->approval_scope);
    }

    public function test_usuario_y_puesto_pueden_compartir_numero_de_paso_en_liquidaciones(): void
    {
        $usuario = User::factory()->create();
        $puesto = $this->puesto($this->empresa, 'Director', 2, true);
        $this->postJson($this->url($this->liquidaciones), ['approver_type' => 'user', 'step_order' => 1, 'user_id' => $usuario->id])->assertCreated();
        $this->postJson($this->url($this->liquidaciones), ['approver_type' => 'position', 'step_order' => 1, 'position_id' => $puesto->id])->assertCreated();

        $this->assertSame(2, ApprovalFlowStep::where('approval_process_id', $this->liquidaciones->id)->where('step_order', 1)->count());
    }

    public function test_usuario_en_ordenes_de_compra_sigue_dando_el_422_de_solo_vacaciones(): void
    {
        $this->postJson($this->url($this->compras), ['approver_type' => 'user', 'step_order' => 1, 'user_id' => User::factory()->create()->id])
            ->assertStatus(422)->assertJsonPath('message', '«Usuario específico» solo se puede usar en el proceso de vacaciones.');

        $this->assertSame(0, ApprovalFlowStep::where('approval_process_id', $this->compras->id)->count());
    }

    public function test_los_tipos_solo_vacaciones_siguen_rechazados_fuera_de_vacaciones_y_liquidaciones(): void
    {
        $mensajes = [
            'direct_supervisor' => '«Jefe directo» solo se puede usar en el proceso de vacaciones.',
            'department_manager' => '«Jefe del departamento» solo se puede usar en el proceso de vacaciones.',
            'rh_review' => '«RH (visto bueno)» solo se puede usar en el proceso de vacaciones.',
        ];
        foreach ($mensajes as $tipo => $mensaje) {
            $this->postJson($this->url($this->compras), ['approver_type' => $tipo, 'step_order' => 1])
                ->assertStatus(422)->assertJsonPath('message', $mensaje);
        }

        // Y el puesto y el nivel jerárquico siguen valiendo en compras, con el alcance que pidan.
        $puesto = $this->puesto($this->empresa, 'Jefe de compras', 3, true);
        $id = $this->postJson($this->url($this->compras), [
            'approver_type' => 'position', 'step_order' => 1, 'position_id' => $puesto->id, 'approval_scope' => 'own_department',
        ])->assertCreated()->json('data.id');
        $this->assertSame('own_department', ApprovalFlowStep::findOrFail($id)->approval_scope);
    }

    public function test_vacaciones_conserva_sus_cuatro_tipos(): void
    {
        $usuario = User::factory()->create();
        $url = $this->url($this->procesoVacaciones);

        $this->postJson($url, ['approver_type' => 'direct_supervisor', 'step_order' => 1])->assertCreated();
        $this->postJson($url, ['approver_type' => 'department_manager', 'step_order' => 2])->assertCreated();
        $this->postJson($url, ['approver_type' => 'rh_review', 'step_order' => 3])->assertCreated();
        $this->postJson($url, ['approver_type' => 'user', 'step_order' => 4, 'user_id' => $usuario->id, 'approval_scope' => 'own_department'])
            ->assertCreated();

        $this->assertSame(4, ApprovalFlowStep::where('approval_process_id', $this->procesoVacaciones->id)->count());
        // En vacaciones el alcance que se pide se respeta (no se fuerza a empresa).
        $this->assertSame('own_department', ApprovalFlowStep::where('approver_type', 'user')->value('approval_scope'));
    }

    public function test_vacaciones_sigue_validando_user_id_y_choque_con_visto_bueno(): void
    {
        $url = $this->url($this->procesoVacaciones);

        $this->postJson($url, ['approver_type' => 'user', 'step_order' => 1])->assertStatus(422)->assertJsonValidationErrors('user_id');

        $this->postJson($url, ['approver_type' => 'user', 'step_order' => 2, 'user_id' => User::factory()->create()->id])->assertCreated();
        $this->postJson($url, ['approver_type' => 'rh_review', 'step_order' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('step_order');
    }
}
