<?php

namespace Tests\Feature\Compras;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Position;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Services\Compras\AprobadorOrdenCompra;
use App\Services\Compras\AvisosCompras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

/**
 * Un empleado del corporativo (Grupo Espléndido) puede autorizar órdenes de
 * compra de otra empresa si una regla de purchase_orders para esa empresa (o
 * para todas) incluye su puesto. Para él no aplica el alcance por departamento.
 */
class AprobadorCorporativoTest extends TestCase
{
    use RefreshDatabase, CreatesComprasFixtures;

    private Enterprise $corporativo;
    private Enterprise $porvenir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpComprasFixtures();
        $this->corporativo = Enterprise::create(['name' => 'Grupo Espléndido', 'slug' => 'grupoesplendido', 'description' => 'Corporativo', 'is_active' => true]);
        $this->porvenir = Enterprise::create(['name' => 'Splendid by Porvenir', 'slug' => 'splendidbyporvenir', 'description' => 'Empaque', 'is_active' => true]);
    }

    /** Empleado de $empresaEmpleado con puesto aprobador y regla para $empresaRegla (null = todas). */
    private function aprobadorDe(Enterprise $empresaEmpleado, ?Enterprise $empresaRegla, string $alcance = 'own_department'): User
    {
        $user = User::factory()->create(['role' => 'user']);
        $depto = Department::firstOrCreate(['enterprise_id' => $empresaEmpleado->id, 'code' => 'DIR'], ['name' => 'Dirección']);
        $puesto = Position::create([
            'enterprise_id' => $empresaEmpleado->id, 'code' => 'DIR-' . $user->id, 'name' => 'Director de Compras',
            'hierarchy_level' => 2, 'can_approve' => true, 'approval_scope' => $alcance,
        ]);
        Employee::create([
            'enterprise_id' => $empresaEmpleado->id, 'employee_number' => 'C' . $user->id,
            'first_name' => 'Directora', 'last_name' => 'Corporativa', 'hire_date' => '2026-01-01',
            'qr_code' => Str::random(32), 'department_id' => $depto->id, 'position_id' => $puesto->id,
            'status' => 'active', 'user_id' => $user->id,
        ]);
        ApprovalFlowStep::create([
            'approval_process_id' => ApprovalProcess::findByCode('purchase_orders')->id,
            'enterprise_id' => $empresaRegla?->id, 'step_order' => 1,
            'approver_type' => 'position', 'position_id' => $puesto->id, 'approval_scope' => $alcance,
            'can_approve' => true, 'can_reject' => true, 'is_active' => true,
        ]);

        return $user;
    }

    public function test_corporativo_con_regla_de_la_empresa_aprueba_sin_importar_departamento(): void
    {
        $directora = $this->aprobadorDe($this->corporativo, $this->empresa, 'own_department');
        $oc = $this->crearOrden(['status' => 'pending']); // creador sin empleado ni departamento
        $aprobador = app(AprobadorOrdenCompra::class);

        $this->assertTrue($aprobador->hayAprobadores($this->empresa->id));
        $this->assertTrue($aprobador->puedeAprobar($directora, $oc));
        $this->assertSame([$directora->id], $aprobador->aprobadoresDe($oc)->pluck('id')->all());
    }

    public function test_corporativo_con_regla_para_todas_las_empresas_aprueba(): void
    {
        $directora = $this->aprobadorDe($this->corporativo, null);

        $this->assertTrue(app(AprobadorOrdenCompra::class)->puedeAprobar($directora, $this->crearOrden(['status' => 'pending'])));
    }

    public function test_corporativo_con_regla_de_otra_empresa_no_aprueba(): void
    {
        $directora = $this->aprobadorDe($this->corporativo, $this->porvenir);
        $oc = $this->crearOrden(['status' => 'pending']);

        $this->assertFalse(app(AprobadorOrdenCompra::class)->puedeAprobar($directora, $oc));
        $this->assertTrue(app(AprobadorOrdenCompra::class)->aprobadoresDe($oc)->isEmpty());
    }

    public function test_corporativo_no_aprueba_su_propia_orden(): void
    {
        $directora = $this->aprobadorDe($this->corporativo, $this->empresa);
        $oc = $this->crearOrden(['status' => 'pending', 'created_by' => $directora->id]);

        $this->assertFalse(app(AprobadorOrdenCompra::class)->puedeAprobar($directora, $oc));
    }

    public function test_empleado_de_otra_empresa_no_corporativa_sigue_sin_poder(): void
    {
        $deporvenir = $this->aprobadorDe($this->porvenir, $this->empresa, 'enterprise');

        $this->assertFalse(app(AprobadorOrdenCompra::class)->puedeAprobar($deporvenir, $this->crearOrden(['status' => 'pending'])));
    }

    public function test_empleado_de_la_empresa_sigue_respetando_su_alcance(): void
    {
        $gerente = $this->aprobadorDe($this->empresa, $this->empresa, 'own_department');

        $this->assertFalse(app(AprobadorOrdenCompra::class)->puedeAprobar($gerente, $this->crearOrden(['status' => 'pending'])));
    }

    public function test_corporativo_ve_la_orden_en_aprobaciones_pendientes_y_la_aprueba(): void
    {
        $directora = $this->aprobadorDe($this->corporativo, $this->empresa);
        $compras = $this->crearUsuarioDeCampo();
        $oc = $this->crearOrden(['status' => 'pending', 'created_by' => $compras->id]);
        $this->crearOrden(['status' => 'pending', 'created_by' => $compras->id, 'enterprise_id' => $this->porvenir->id]);

        $resumen = collect($this->actingAs($directora)->getJson('/api/pending-approvals/summary')->assertOk()->json('data.processes'))
            ->firstWhere('code', 'purchase_orders');
        $this->assertSame(1, $resumen['pending_count'] ?? null);

        $items = collect($this->actingAs($directora)->getJson('/api/pending-approvals')->assertOk()->json('data.processes'))
            ->flatMap(fn ($p) => $p['items'] ?? [])->where('type', 'purchase_order');
        $this->assertSame([$oc->id], $items->pluck('id')->values()->all());

        $this->actingAs($directora)->postJson("/api/pending-approvals/purchase_order/{$oc->id}/approve")->assertOk();
        $this->assertSame('approved', $oc->fresh()->status);
    }

    public function test_corporativo_recibe_aviso_de_orden_por_autorizar(): void
    {
        $directora = $this->aprobadorDe($this->corporativo, $this->empresa);

        app(AvisosCompras::class)->ordenPorAutorizar($this->crearOrden(['status' => 'pending']));

        $this->assertSame(1, SystemNotification::where('user_id', $directora->id)->count());
    }

    public function test_corporativo_puede_abrir_y_autorizar_la_orden_desde_compras(): void
    {
        $directora = $this->aprobadorDe($this->corporativo, $this->empresa);
        $oc = $this->crearOrden(['status' => 'pending']);
        $url = "/api/splendidfarms/administration/compras/ordenes/{$oc->id}";

        // Abrir la pantalla de Compras de la empresa exige acceso a ella (se da en Admin).
        $this->actingAs($directora)->getJson("$url/capacidades", $this->headersEmpresa())->assertForbidden();
        UserEnterpriseAccess::create(['user_id' => $directora->id, 'enterprise_id' => $this->empresa->id, 'is_active' => true]);

        $this->actingAs($directora)->getJson("$url/capacidades", $this->headersEmpresa())->assertOk()->assertJsonPath('data.puede_aprobar', true);
        $this->actingAs($directora)->postJson("$url/approve", [], $this->headersEmpresa())->assertOk();
    }
}
