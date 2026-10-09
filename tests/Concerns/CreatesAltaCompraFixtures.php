<?php

namespace Tests\Concerns;

use App\Models\Application;
use App\Models\AssetCategory;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\PurchaseReceipt;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Support\Str;

/**
 * Sobre CreatesComprasFixtures (Splendid Farms con almacenes, proveedor, OC y
 * permisos de compras): agrega el módulo Activos Fijos, tipos de activo, la
 * empresa corporativa y un helper que deja una recepción ya confirmada.
 */
trait CreatesAltaCompraFixtures
{
    use CreatesComprasFixtures;

    protected const URL_RECEPCIONES = '/api/splendidfarms/administration/compras/recepciones';
    protected const URL_ALTAS = '/api/splendidfarms/administration/activos-fijos/altas-pendientes';

    protected User $encargadoCompra;
    protected User $comprasUser;
    protected AssetCategory $tipoActivo;
    protected AssetCategory $subtipoActivo;
    protected Enterprise $corporativo;

    protected function setUpAltaCompraFixtures(): void
    {
        $this->setUpComprasFixtures();

        $this->empresa->update(['asset_code_prefix' => 'SF']);
        $this->insumo->update(['is_fixed_asset' => true]);

        $this->tipoActivo = AssetCategory::create(['code' => 'TAC-001', 'name' => 'Equipos de cómputo', 'is_active' => true]);
        $this->subtipoActivo = AssetCategory::create([
            'code' => 'TAC-002', 'name' => 'Laptops', 'parent_id' => $this->tipoActivo->id, 'is_active' => true,
        ]);

        $this->corporativo = Enterprise::create([
            'name' => 'Grupo Espléndido', 'slug' => 'grupoesplendido', 'description' => 'Corporativo',
            'is_active' => true, 'asset_code_prefix' => 'GE',
        ]);
        $this->moduloActivosDe($this->corporativo);
        $this->moduloActivosDe($this->empresa);

        $this->encargadoCompra = $this->crearUsuarioDeCampo([$this->almacenA]);
        $this->comprasUser = $this->crearUsuarioDeCampo([$this->almacenA]);
        $this->otorgarConfirmar($this->comprasUser);
        $this->otorgarVerTodos($this->comprasUser);
    }

    protected function moduloActivosDe(Enterprise $empresa): Module
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $empresa->id, 'slug' => 'administration'],
            ['name' => 'Administración', 'path' => "/{$empresa->slug}/administration", 'description' => 'administration'],
        );

        return Module::firstOrCreate(['application_id' => $app->id, 'slug' => 'activos-fijos'], ['name' => 'Activos Fijos']);
    }

    /** Usuario con acceso a la empresa y permisos de `activos` (por defecto solo `create`). */
    protected function usuarioActivos(?Enterprise $empresa = null, array $permisos = ['create']): User
    {
        $empresa ??= $this->empresa;
        $user = User::factory()->create(['role' => 'user']);
        UserEnterpriseAccess::create(['user_id' => $user->id, 'enterprise_id' => $empresa->id, 'is_active' => true]);

        $sub = Submodule::firstOrCreate(
            ['module_id' => $this->moduloActivosDe($empresa)->id, 'slug' => 'activos'],
            ['name' => 'Activos'],
        );
        foreach ($permisos as $slug) {
            $tipo = SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $sub->id, 'slug' => $slug],
                ['name' => Str::headline($slug), 'is_active' => true],
            );
            UserSubmodulePermission::create([
                'user_id' => $user->id, 'submodule_id' => $sub->id, 'permission_type_id' => $tipo->id, 'is_granted' => true,
            ]);
        }

        return $user;
    }

    /**
     * Captura, envía y confirma una recepción de $cantidad unidades del insumo (marcado
     * como activo fijo en setUpAltaCompraFixtures) contra una OC nueva de 10 unidades.
     */
    protected function recepcionConfirmada(float $cantidad, string $lote = 'L-100'): PurchaseReceipt
    {
        $req = $this->crearRequisicion($this->encargadoCompra, $this->almacenA, 'orden_generada');
        $oc = $this->crearOrden(['status' => 'approved', 'requisicion_campo_id' => $req->id], 10, 100);

        $id = $this->actingAs($this->encargadoCompra)->postJson(self::URL_RECEPCIONES, [
            'purchase_order_id' => $oc->id,
            'receipt_date' => now()->toDateString(),
            'supplier_document' => 'FAC-' . $oc->id,
            'details' => [[
                'purchase_order_detail_id' => $oc->details->first()->id,
                'quantity_received' => $cantidad, 'lot_number' => $lote,
                'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ], $this->headersEmpresa())->assertCreated()->json('data.id');

        $this->actingAs($this->encargadoCompra)->postJson(self::URL_RECEPCIONES . "/$id/submit", [], $this->headersEmpresa())->assertOk();
        $this->actingAs($this->comprasUser)->postJson(self::URL_RECEPCIONES . "/$id/confirmar", [], $this->headersEmpresa())->assertOk();

        return PurchaseReceipt::findOrFail($id);
    }
}
