<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\Enterprise;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\RH\CadenaAprobacionVacaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class ConfigVistoBuenoRhTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const CHOQUE = 'Un paso de visto bueno de RH no puede compartir número con otro tipo de aprobador.';

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

    private function urlPasos(): string
    {
        return "/api/admin/approval-processes/{$this->procesoVacaciones->id}/steps";
    }

    public function test_rh_review_se_acepta_en_vacaciones_y_limpia_campos(): void
    {
        $usuario = User::factory()->create();
        $puesto = $this->puesto($this->empresa, 'Coordinador', 3, true);

        $id = $this->postJson($this->urlPasos(), [
            'approver_type' => 'rh_review', 'step_order' => 2, 'user_id' => $usuario->id,
            'position_id' => $puesto->id, 'min_hierarchy_level' => 3,
        ])->assertCreated()->json('data.id');

        $regla = ApprovalFlowStep::findOrFail($id);
        $this->assertSame('rh_review', $regla->approver_type);
        $this->assertNull($regla->user_id);
        $this->assertNull($regla->position_id);
        $this->assertNull($regla->min_hierarchy_level);
        $this->assertSame('RH (visto bueno)', $regla->approver_description);
    }

    public function test_rh_review_en_otro_proceso_da_422(): void
    {
        $this->postJson("/api/admin/approval-processes/{$this->otroProceso->id}/steps", [
            'approver_type' => 'rh_review', 'step_order' => 1,
        ])->assertStatus(422)->assertJsonPath('message', '«RH (visto bueno)» solo se puede usar en el proceso de vacaciones.');

        $this->assertSame(0, ApprovalFlowStep::where('approval_process_id', $this->otroProceso->id)->count());
    }

    public function test_no_se_mezcla_con_otro_tipo_en_el_mismo_paso_y_empresa(): void
    {
        // Ya hay una regla user global en el paso 2: rh_review en el paso 2 choca.
        $this->regla(2, 'user', ['user_id' => User::factory()->create()->id]);

        $this->postJson($this->urlPasos(), ['approver_type' => 'rh_review', 'step_order' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('step_order')
            ->assertJsonPath('errors.step_order.0', self::CHOQUE);

        // Al revés: ya hay un rh_review en el paso 3, otro tipo en el 3 choca.
        $this->regla(3, 'rh_review');
        $this->postJson($this->urlPasos(), ['approver_type' => 'department_manager', 'step_order' => 3])
            ->assertStatus(422)->assertJsonValidationErrors('step_order');

        // Dos rh_review en el mismo paso sí conviven.
        $this->postJson($this->urlPasos(), ['approver_type' => 'rh_review', 'step_order' => 3])->assertCreated();

        $this->assertSame(1, ApprovalFlowStep::where('step_order', 2)->count());
        $this->assertSame(2, ApprovalFlowStep::where('step_order', 3)->count());
    }

    public function test_el_choque_tambien_aplica_al_actualizar(): void
    {
        $usuario = $this->regla(2, 'user', ['user_id' => User::factory()->create()->id]);
        $rh = $this->regla(3, 'rh_review');
        $otra = $this->regla(4, 'department_manager');

        // Mover el rh_review al paso del user.
        $this->putJson("{$this->urlPasos()}/{$rh->id}", ['step_order' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('step_order');
        // Mover el otro tipo al paso del rh_review.
        $this->putJson("{$this->urlPasos()}/{$otra->id}", ['step_order' => 3])
            ->assertStatus(422)->assertJsonValidationErrors('step_order');
        // Cambiar de tipo la regla user a rh_review en un paso con otro tipo.
        $this->regla(2, 'department_manager');
        $this->putJson("{$this->urlPasos()}/{$usuario->id}", ['approver_type' => 'rh_review'])
            ->assertStatus(422)->assertJsonValidationErrors('step_order');

        $this->assertSame(3, $rh->fresh()->step_order);
        $this->assertSame(4, $otra->fresh()->step_order);
        $this->assertSame('user', $usuario->fresh()->approver_type);

        // Mover a un paso libre sí se puede.
        $this->putJson("{$this->urlPasos()}/{$rh->id}", ['step_order' => 5])->assertOk();
        $this->assertSame(5, $rh->fresh()->step_order);
    }

    public function test_una_regla_inactiva_no_choca(): void
    {
        $this->regla(2, 'user', ['user_id' => User::factory()->create()->id, 'is_active' => false]);

        $this->postJson($this->urlPasos(), ['approver_type' => 'rh_review', 'step_order' => 2])->assertCreated();
    }

    public function test_mismo_paso_en_empresas_distintas_si_se_permite(): void
    {
        $sbp = Enterprise::create(['name' => 'Splendid by Porvenir', 'slug' => 'splendidbyporvenir', 'description' => 'SBP', 'is_active' => true]);
        $this->regla(2, 'user', ['user_id' => User::factory()->create()->id, 'enterprise_id' => $sbp->id]);

        $this->postJson($this->urlPasos(), [
            'approver_type' => 'rh_review', 'step_order' => 2, 'enterprise_id' => $this->empresa->id,
        ])->assertCreated();

        // Una regla global (enterprise_id null) sí choca con cualquier empresa.
        $this->postJson($this->urlPasos(), ['approver_type' => 'department_manager', 'step_order' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('step_order');
    }

    public function test_construir_congela_is_rh_review(): void
    {
        $this->regla(1, 'department_manager');
        $this->regla(2, 'rh_review');
        $this->regla(3, 'user', ['user_id' => User::factory()->create()->id]);
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);

        app(CadenaAprobacionVacaciones::class)->construir($solicitud);

        $pasos = $solicitud->approvals()->orderBy('step_order')->get();
        $this->assertCount(4, $pasos);
        $this->assertSame([false, true, false, false], $pasos->pluck('is_rh_review')->all());
        $this->assertTrue($pasos->last()->is_rh_final);
    }
}
