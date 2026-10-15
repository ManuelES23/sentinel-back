<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class JefeDirectoSoloVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private ApprovalProcess $otroProceso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->otroProceso = ApprovalProcess::firstOrCreate(
            ['code' => 'purchase_orders'],
            [
                'name' => 'Órdenes de compra', 'description' => 'Aprobación de órdenes de compra', 'module' => 'compras',
                'entity_type' => PurchaseOrder::class, 'requires_approval' => true, 'is_active' => true,
            ],
        );
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_no_se_puede_agregar_en_otro_proceso(): void
    {
        $this->postJson("/api/admin/approval-processes/{$this->otroProceso->id}/steps", [
            'approver_type' => 'direct_supervisor', 'step_order' => 1,
        ])->assertStatus(422)->assertJsonPath('message', '«Jefe directo» solo se puede usar en el proceso de vacaciones.');

        $this->assertSame(0, ApprovalFlowStep::where('approval_process_id', $this->otroProceso->id)->count());
    }

    public function test_no_se_puede_cambiar_a_jefe_directo_una_regla_de_otro_proceso(): void
    {
        $regla = ApprovalFlowStep::create([
            'approval_process_id' => $this->otroProceso->id, 'step_order' => 1, 'approver_type' => 'hierarchy_level',
            'min_hierarchy_level' => 3, 'approval_scope' => 'enterprise', 'can_approve' => true, 'can_reject' => true, 'is_active' => true,
        ]);

        $this->putJson("/api/admin/approval-processes/{$this->otroProceso->id}/steps/{$regla->id}", ['approver_type' => 'direct_supervisor'])
            ->assertStatus(422);

        $this->assertSame('hierarchy_level', $regla->fresh()->approver_type);
    }

    public function test_en_vacaciones_sigue_permitido(): void
    {
        $this->postJson("/api/admin/approval-processes/{$this->procesoVacaciones->id}/steps", [
            'approver_type' => 'direct_supervisor', 'step_order' => 1,
        ])->assertCreated();
    }
}
