<?php

namespace Tests\Concerns;

use App\Models\Application;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\Cultivo;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Module;
use App\Models\Position;
use App\Models\PurchaseOrder;
use App\Models\RequisicionCampo;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\Supplier;
use App\Models\Temporada;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Support\Str;

/**
 * Sobre CreatesAlmacenFixtures: proveedores, temporada, permisos de compras,
 * un aprobador de OC por flujo y helpers de requisición/OC.
 */
trait CreatesComprasFixtures
{
    use CreatesAlmacenFixtures;

    protected Supplier $proveedor;
    protected Supplier $proveedor2;
    protected Temporada $temporada;

    protected function setUpComprasFixtures(): void
    {
        $this->setUpAlmacenFixtures();
        $this->insumo->update(['track_lots' => true, 'track_expiry' => true]);

        $this->proveedor = Supplier::create(['code' => 'PRV-1', 'business_name' => 'Agroquímicos del Norte', 'is_active' => true, 'has_credit' => true, 'payment_terms' => 15]);
        $this->proveedor2 = Supplier::create(['code' => 'PRV-2', 'business_name' => 'Fertilizantes del Valle', 'is_active' => true]);

        $cultivo = Cultivo::create(['nombre' => 'Chile']);
        $this->temporada = Temporada::create([
            'cultivo_id' => $cultivo->id, 'nombre' => 'Chile 2026', 'locacion' => 'Sinaloa',
            'folio_temporada' => $cultivo->id . '-001', 'año_inicio' => 2026, 'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31', 'user_id' => User::factory()->create()->id,
        ]);
    }

    protected function otorgarPermiso(User $user, string $app, string $modulo, string $sub, string $slug): void
    {
        $aplicacion = Application::firstOrCreate(
            ['enterprise_id' => $this->empresa->id, 'slug' => $app],
            ['name' => Str::headline($app), 'path' => "/splendidfarms/$app", 'description' => $app],
        );
        $mod = Module::firstOrCreate(['application_id' => $aplicacion->id, 'slug' => $modulo], ['name' => Str::headline($modulo)]);
        $submodulo = Submodule::firstOrCreate(['module_id' => $mod->id, 'slug' => $sub], ['name' => Str::headline($sub)]);
        $tipo = SubmodulePermissionType::firstOrCreate(
            ['submodule_id' => $submodulo->id, 'slug' => $slug],
            ['name' => Str::headline($slug), 'is_active' => true],
        );

        UserSubmodulePermission::create([
            'user_id' => $user->id,
            'submodule_id' => $submodulo->id,
            'permission_type_id' => $tipo->id,
            'is_granted' => true,
        ]);
    }

    protected function otorgarCotizar(User $user): void
    {
        $this->otorgarPermiso($user, 'operacion-agricola', 'agricola', 'requisiciones', 'cotizar');
    }

    protected function otorgarConfirmar(User $user): void
    {
        $this->otorgarPermiso($user, 'inventario', 'compras', 'recepciones', 'confirmar');
    }

    /** Usuario con empleado en un puesto aprobador del proceso purchase_orders. */
    protected function crearAprobador(string $alcance = 'enterprise'): User
    {
        $user = User::factory()->create(['role' => 'user']);
        UserEnterpriseAccess::create(['user_id' => $user->id, 'enterprise_id' => $this->empresa->id, 'is_active' => true]);

        $depto = Department::firstOrCreate(['enterprise_id' => $this->empresa->id, 'code' => 'DIR'], ['name' => 'Dirección']);
        $puesto = Position::create([
            'enterprise_id' => $this->empresa->id, 'code' => 'GER-' . $user->id, 'name' => 'Gerente',
            'hierarchy_level' => 2, 'can_approve' => true, 'approval_scope' => $alcance,
        ]);
        Employee::create([
            'enterprise_id' => $this->empresa->id, 'employee_number' => 'E' . $user->id,
            'first_name' => 'Gerente', 'last_name' => 'Campo', 'hire_date' => '2026-01-01',
            'qr_code' => Str::random(32), 'department_id' => $depto->id, 'position_id' => $puesto->id,
            'status' => 'active', 'user_id' => $user->id,
        ]);

        $proceso = ApprovalProcess::findByCode('purchase_orders');
        ApprovalFlowStep::create([
            'approval_process_id' => $proceso->id, 'enterprise_id' => $this->empresa->id, 'step_order' => 1,
            'approver_type' => 'position', 'position_id' => $puesto->id, 'approval_scope' => $alcance,
            'can_approve' => true, 'can_reject' => true, 'is_active' => true,
        ]);

        return $user;
    }

    protected function crearRequisicion(User $solicitante, ?Entity $almacen = null, string $status = 'borrador', float $cantidad = 10): RequisicionCampo
    {
        $req = RequisicionCampo::create([
            'numero_requisicion' => 'RC-2026-' . str_pad((string) (RequisicionCampo::withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT),
            'enterprise_id' => $this->empresa->id,
            'almacen_id' => ($almacen ?? $this->almacenA)->id,
            'temporada_id' => $this->temporada->id,
            'solicitante_user_id' => $solicitante->id,
            'fecha_solicitud' => now()->toDateString(),
            'prioridad' => 'media',
            'status' => $status,
            'enviada_at' => $status === 'borrador' ? null : now(),
        ]);
        $req->detalles()->create([
            'product_id' => $this->insumo->id, 'nombre_producto' => $this->insumo->name,
            'cantidad' => $cantidad, 'unit_id' => $this->unidad->id,
        ]);

        return $req;
    }

    protected function crearOrden(array $attrs = [], float $cantidad = 10, float $precio = 100): PurchaseOrder
    {
        $oc = PurchaseOrder::create(array_merge([
            'order_number' => 'OC-2026-' . str_pad((string) (PurchaseOrder::withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT),
            'enterprise_id' => $this->empresa->id,
            'almacen_destino_id' => $this->almacenA->id,
            'supplier_id' => $this->proveedor->id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'currency_code' => 'MXN',
            'created_by' => User::factory()->create()->id,
        ], $attrs));
        $oc->details()->create([
            'product_id' => $this->insumo->id, 'quantity_ordered' => $cantidad, 'unit_id' => $this->unidad->id,
            'unit_price' => $precio, 'tax_rate' => 16, 'line_number' => 1,
        ]);

        return $oc->fresh();
    }
}
