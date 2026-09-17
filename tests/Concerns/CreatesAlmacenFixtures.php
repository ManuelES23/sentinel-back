<?php

namespace Tests\Concerns;

use App\Models\Application;
use App\Models\Branch;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\InventoryStock;
use App\Models\Module;
use App\Models\MovementType;
use App\Models\Product;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserEntityAccess;
use App\Models\UserSubmodulePermission;

/**
 * Empresa Splendid Farms con dos almacenes de campo (A y B), un insumo,
 * tipos de movimiento básicos y helpers para usuarios con almacenes asignados.
 */
trait CreatesAlmacenFixtures
{
    protected Enterprise $empresa;
    protected Branch $sucursal;
    protected EntityType $tipoCampo;
    protected Entity $almacenA;
    protected Entity $almacenB;
    protected UnitOfMeasure $unidad;
    protected Product $insumo;
    protected MovementType $tipoEntrada;
    protected MovementType $tipoSalida;
    protected MovementType $tipoTransferencia;
    protected MovementType $tipoMerma;
    protected MovementType $tipoAjusteMas;
    protected MovementType $tipoAjusteMenos;

    protected function setUpAlmacenFixtures(): void
    {
        $this->empresa = Enterprise::create([
            'name' => 'Splendid Farms',
            'slug' => 'splendidfarms',
            'description' => 'Empresa agrícola de prueba',
            'is_active' => true,
        ]);

        $this->sucursal = Branch::create([
            'enterprise_id' => $this->empresa->id,
            'code' => 'SUC-01',
            'name' => 'Rancho Norte',
            'slug' => 'rancho-norte',
        ]);

        $this->tipoCampo = EntityType::create(['code' => 'CAMPO', 'name' => 'Campo', 'slug' => 'campo']);

        $this->almacenA = Entity::create([
            'branch_id' => $this->sucursal->id,
            'entity_type_id' => $this->tipoCampo->id,
            'code' => 'ALM-A',
            'name' => 'Almacén Campo A',
        ]);

        $this->almacenB = Entity::create([
            'branch_id' => $this->sucursal->id,
            'entity_type_id' => $this->tipoCampo->id,
            'code' => 'ALM-B',
            'name' => 'Almacén Campo B',
        ]);

        $this->unidad = UnitOfMeasure::create(['code' => 'LT', 'name' => 'Litro', 'abbreviation' => 'L']);
        $this->unidad->enterprises()->attach($this->empresa->id);

        $this->insumo = Product::create([
            'code' => 'PROD-00001',
            'name' => 'Clorotalonil 720',
            'unit_id' => $this->unidad->id,
            'product_type' => 'consumable',
            'track_inventory' => true,
        ]);
        $this->insumo->enterprises()->attach($this->empresa->id);

        $this->tipoEntrada = MovementType::create(['code' => 'COMPRA', 'name' => 'Compra', 'direction' => 'in', 'effect' => 'increase', 'requires_destination_entity' => true]);
        $this->tipoSalida = MovementType::create(['code' => 'CONSUMO', 'name' => 'Consumo', 'direction' => 'out', 'effect' => 'decrease', 'requires_source_entity' => true]);
        $this->tipoTransferencia = MovementType::create(['code' => 'TRANSFERENCIA', 'name' => 'Transferencia', 'direction' => 'transfer', 'effect' => 'neutral', 'requires_source_entity' => true, 'requires_destination_entity' => true]);
        $this->tipoMerma = MovementType::create(['code' => 'MERMA', 'name' => 'Merma', 'direction' => 'out', 'effect' => 'decrease', 'requires_source_entity' => true]);
        $this->tipoAjusteMas = MovementType::create(['code' => 'AJUSTE+', 'name' => 'Ajuste positivo', 'direction' => 'adjustment', 'effect' => 'increase', 'requires_destination_entity' => true]);
        $this->tipoAjusteMenos = MovementType::create(['code' => 'AJUSTE-', 'name' => 'Ajuste negativo', 'direction' => 'adjustment', 'effect' => 'decrease', 'requires_source_entity' => true]);
    }

    protected function crearUsuarioDeCampo(array $almacenes = []): User
    {
        $user = User::factory()->create(['role' => 'user']);
        UserEnterpriseAccess::create(['user_id' => $user->id, 'enterprise_id' => $this->empresa->id, 'is_active' => true]);

        foreach ($almacenes as $almacen) {
            UserEntityAccess::create([
                'user_id' => $user->id,
                'entity_id' => $almacen->id,
                'enterprise_id' => $this->empresa->id,
            ]);
        }

        return $user;
    }

    protected function crearAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    protected function otorgarVerTodos(User $user): void
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $this->empresa->id, 'slug' => 'inventario'],
            ['name' => 'Inventario', 'path' => '/splendidfarms/inventario', 'description' => 'Gestión de inventario agrícola'],
        );
        $modulo = Module::firstOrCreate(['application_id' => $app->id, 'slug' => 'reportes'], ['name' => 'Reportes']);
        $sub = Submodule::firstOrCreate(['module_id' => $modulo->id, 'slug' => 'stock'], ['name' => 'Stock']);
        $tipo = SubmodulePermissionType::firstOrCreate(
            ['submodule_id' => $sub->id, 'slug' => 'ver_todos_almacenes'],
            ['name' => 'Ver todos los almacenes', 'is_active' => true],
        );

        UserSubmodulePermission::create([
            'user_id' => $user->id,
            'submodule_id' => $sub->id,
            'permission_type_id' => $tipo->id,
            'is_granted' => true,
        ]);
    }

    protected function darStock(Entity $almacen, Product $producto, float $cantidad, ?string $lote = null, ?string $caducidad = null): InventoryStock
    {
        return InventoryStock::create([
            'product_id' => $producto->id,
            'entity_id' => $almacen->id,
            'quantity' => $cantidad,
            'reserved_quantity' => 0,
            'unit_cost' => 10,
            'total_cost' => $cantidad * 10,
            'lot_number' => $lote,
            'expiry_date' => $caducidad,
        ]);
    }

    protected function headersEmpresa(): array
    {
        return ['X-Enterprise-Slug' => 'splendidfarms'];
    }
}
