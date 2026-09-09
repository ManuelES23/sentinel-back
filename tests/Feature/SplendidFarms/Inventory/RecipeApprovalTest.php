<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Position;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRecipeFixtures;
use Tests\TestCase;

class RecipeApprovalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRecipeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecipeFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_enviar_a_revision_cambia_el_estado_a_pending_approval(): void
    {
        $create = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(), ['X-Enterprise-Slug' => 'splendidfarms']);
        $recipeId = $create->json('data.id');

        $response = $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/submit-for-approval",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertOk();
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'status' => 'pending_approval']);
    }

    public function test_no_se_puede_enviar_a_revision_una_receta_que_no_esta_en_borrador(): void
    {
        $create = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(), ['X-Enterprise-Slug' => 'splendidfarms']);
        $recipeId = $create->json('data.id');
        $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/submit-for-approval",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        // Ya está pending_approval: un segundo submit debe rechazarse.
        $response = $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/submit-for-approval",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'status' => 'pending_approval']);
    }

    public function test_aprobar_una_receta_pendiente_la_marca_activa(): void
    {
        [$approver] = $this->createApproverWithFlowStep();

        $recipeId = $this->createAndSubmitRecipe();

        Sanctum::actingAs($approver);
        $response = $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/approve",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertOk();
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'status' => 'active']);
    }

    public function test_rechazar_una_receta_pendiente_la_regresa_a_borrador_con_motivo(): void
    {
        [$approver] = $this->createApproverWithFlowStep();

        $recipeId = $this->createAndSubmitRecipe();

        Sanctum::actingAs($approver);
        $response = $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/reject",
            ['reason' => 'Faltan ingredientes por definir'], ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertOk();
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'status' => 'draft']);
        $recipe = Recipe::find($recipeId);
        $this->assertStringContainsString('Faltan ingredientes por definir', $recipe->notes);
    }

    public function test_rechazar_sin_motivo_falla_la_validacion(): void
    {
        [$approver] = $this->createApproverWithFlowStep();

        $recipeId = $this->createAndSubmitRecipe();

        Sanctum::actingAs($approver);
        $response = $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/reject",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'status' => 'pending_approval']);
    }

    public function test_un_empleado_sin_nivel_jerarquico_suficiente_no_puede_aprobar(): void
    {
        // Step exige nivel <= 3; este empleado tiene nivel 5 (menos autoridad) -> no matchea.
        $position = Position::create([
            'enterprise_id' => $this->enterprise->id,
            'name' => 'Auxiliar de Inventario',
            'hierarchy_level' => 5,
            'can_approve' => true,
        ]);
        $unauthorizedUser = User::factory()->create();
        Employee::create([
            'enterprise_id' => $this->enterprise->id,
            'employee_number' => 'EMP-002',
            'first_name' => 'Luis',
            'last_name' => 'Pérez',
            'position_id' => $position->id,
            'hire_date' => now()->toDateString(),
            'qr_code' => 'QR-EMP-002',
            'user_id' => $unauthorizedUser->id,
        ]);

        $process = ApprovalProcess::findByCode('recipe_approval');
        ApprovalFlowStep::create([
            'approval_process_id' => $process->id, 'step_order' => 1,
            'approver_type' => 'hierarchy_level', 'min_hierarchy_level' => 3,
            'approval_scope' => 'enterprise', 'can_approve' => true, 'can_reject' => true, 'is_active' => true,
        ]);

        $recipeId = $this->createAndSubmitRecipe();

        Sanctum::actingAs($unauthorizedUser);
        $response = $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/approve",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'status' => 'pending_approval']);
    }

    public function test_no_se_puede_operar_una_receta_de_otra_empresa(): void
    {
        $otherEnterprise = Enterprise::create([
            'name' => 'Otra Empresa', 'slug' => 'otra-empresa', 'description' => 'Otra empresa de prueba', 'is_active' => true,
        ]);
        $foreignRecipe = Recipe::create([
            'enterprise_id' => $otherEnterprise->id,
            'code' => 'RCP-FOREIGN',
            'name' => 'Receta ajena',
            'output_quantity' => 1,
            'status' => 'draft',
        ]);

        $response = $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$foreignRecipe->id}/submit-for-approval",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('recipes', ['id' => $foreignRecipe->id, 'status' => 'draft']);
    }

    public function test_inbox_de_pendientes_por_aprobar_incluye_recetas_pendientes(): void
    {
        [$approver] = $this->createApproverWithFlowStep();

        $this->createAndSubmitRecipe();
        $this->createAndSubmitRecipe(['name' => 'Caja Elote Premium 20lb #2', 'code' => 'RCP-00099']);

        Sanctum::actingAs($approver);
        $response = $this->getJson('/api/pending-approvals/summary');

        $response->assertOk();
        $entry = collect($response->json('data.processes'))->firstWhere('code', 'recipe_approval');
        $this->assertNotNull($entry, 'La entrada recipe_approval debe aparecer en el inbox de pendientes.');
        $this->assertSame(2, $entry['pending_count']);
        $this->assertGreaterThanOrEqual(2, $response->json('data.total_pending'));
    }

    /**
     * Crea al aprobador (Employee + Position con jerarquía suficiente) y el
     * step de aprobación sobre el proceso 'recipe_approval' ya sembrado por
     * la migración 2026_09_09_160100_seed_recipe_approval_process.php.
     *
     * @return array{0: User, 1: ApprovalProcess}
     */
    private function createApproverWithFlowStep(): array
    {
        $position = Position::create([
            'enterprise_id' => $this->enterprise->id,
            'name' => 'Gerente de Inventario',
            'hierarchy_level' => 2,
            'can_approve' => true,
        ]);
        $approver = User::factory()->create();
        Employee::create([
            'enterprise_id' => $this->enterprise->id,
            'employee_number' => 'EMP-001',
            'first_name' => 'Ana',
            'last_name' => 'Gómez',
            'position_id' => $position->id,
            'hire_date' => now()->toDateString(),
            'qr_code' => 'QR-EMP-001',
            'user_id' => $approver->id,
        ]);

        $process = ApprovalProcess::findByCode('recipe_approval');
        ApprovalFlowStep::create([
            'approval_process_id' => $process->id, 'step_order' => 1,
            'approver_type' => 'hierarchy_level', 'min_hierarchy_level' => 3,
            'approval_scope' => 'enterprise', 'can_approve' => true, 'can_reject' => true, 'is_active' => true,
        ]);

        return [$approver, $process];
    }

    private function createAndSubmitRecipe(array $overrides = []): int
    {
        $create = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload($overrides), ['X-Enterprise-Slug' => 'splendidfarms']);
        $recipeId = $create->json('data.id');
        $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/submit-for-approval",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        return $recipeId;
    }
}
