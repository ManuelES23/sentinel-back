<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\FixedAsset;
use App\Models\FixedAssetReceiptUnit;
use App\Models\InventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAltaCompraFixtures;
use Tests\TestCase;

class AltaCompraBloqueoAnulacionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAltaCompraFixtures;

    private const URL_MOVIMIENTOS = '/api/splendidfarms/inventario/operaciones/movimientos';
    private const URL_ACTIVOS = '/api/splendidfarms/administration/activos-fijos/activos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAltaCompraFixtures();
    }

    private function activoDeCompra(string $status = 'disponible'): array
    {
        $rec = $this->recepcionConfirmada(1, 'L-100');
        $unidad = FixedAssetReceiptUnit::where('purchase_receipt_id', $rec->id)->firstOrFail();
        $activo = FixedAsset::create([
            'enterprise_id' => $this->empresa->id, 'code' => 'SF-AF-000901', 'name' => 'Laptop',
            'category_id' => $this->tipoActivo->id, 'branch_id' => $this->sucursal->id, 'entity_id' => $this->almacenA->id,
            'status' => $status, 'is_active' => true,
            'supplier_id' => $rec->supplier_id, 'purchase_receipt_id' => $rec->id,
        ]);
        $unidad->update(['status' => 'registered', 'fixed_asset_id' => $activo->id]);

        return [$rec, $unidad, $activo];
    }

    public function test_cancelar_el_movimiento_de_una_entrada_con_unidades_vigentes_da_422(): void
    {
        $rec = $this->recepcionConfirmada(3, 'L-100');
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $this->postJson(self::URL_MOVIMIENTOS . "/{$rec->inventory_movement_id}/cancel", ['reason' => 'Prueba'], $this->headersEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors('movement');

        $this->assertSame('approved', InventoryMovement::find($rec->inventory_movement_id)->status);
    }

    public function test_cancelar_la_entrada_procede_si_ya_no_hay_unidades_vigentes(): void
    {
        $rec = $this->recepcionConfirmada(3, 'L-100');
        FixedAssetReceiptUnit::where('purchase_receipt_id', $rec->id)->update(['status' => 'discarded', 'discarded_reason' => 'x']);
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $this->postJson(self::URL_MOVIMIENTOS . "/{$rec->inventory_movement_id}/cancel", ['reason' => 'Prueba'], $this->headersEmpresa())
            ->assertOk();
    }

    public function test_cancelar_un_movimiento_que_no_viene_de_una_recepcion_no_cambia(): void
    {
        $movimiento = InventoryMovement::create([
            'document_number' => 'AJ-0001', 'movement_type_id' => $this->tipoAjusteMas->id,
            'movement_date' => now()->toDateString(), 'destination_entity_id' => $this->almacenA->id,
            'destination_entity_type' => 'entity', 'status' => 'pending',
            'created_by' => $this->crearAdmin()->id, 'total_quantity' => 0, 'total_amount' => 0,
        ]);
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $this->postJson(self::URL_MOVIMIENTOS . "/{$movimiento->id}/cancel", ['reason' => 'Prueba'], $this->headersEmpresa())->assertOk();
    }

    public function test_no_se_borra_un_activo_de_compra_que_no_esta_en_baja(): void
    {
        [, , $activo] = $this->activoDeCompra('disponible');
        Sanctum::actingAs($this->usuarioActivos($this->empresa, ['view', 'delete']));

        $this->deleteJson(self::URL_ACTIVOS . "/{$activo->id}")
            ->assertStatus(422)->assertJsonValidationErrors('asset');

        $this->assertNotNull($activo->fresh());
    }

    public function test_un_activo_de_compra_en_baja_si_se_puede_borrar(): void
    {
        [, , $activo] = $this->activoDeCompra('baja');
        Sanctum::actingAs($this->usuarioActivos($this->empresa, ['view', 'delete']));

        $this->deleteJson(self::URL_ACTIVOS . "/{$activo->id}")->assertOk();

        $this->assertSoftDeleted('fixed_assets', ['id' => $activo->id]);
    }

    public function test_un_activo_normal_se_sigue_borrando(): void
    {
        $normal = FixedAsset::create([
            'enterprise_id' => $this->empresa->id, 'code' => 'SF-AF-000902', 'name' => 'Escritorio',
            'category_id' => $this->tipoActivo->id, 'branch_id' => $this->sucursal->id, 'entity_id' => $this->almacenA->id,
            'status' => 'disponible', 'is_active' => true,
        ]);
        Sanctum::actingAs($this->usuarioActivos($this->empresa, ['view', 'delete']));

        $this->deleteJson(self::URL_ACTIVOS . "/{$normal->id}")->assertOk();
    }

    public function test_la_ficha_del_activo_trae_proveedor_y_recepcion(): void
    {
        [$rec, , $activo] = $this->activoDeCompra();
        Sanctum::actingAs($this->usuarioActivos($this->empresa, ['view']));

        $this->getJson(self::URL_ACTIVOS . "/{$activo->id}")
            ->assertOk()
            ->assertJsonPath('data.supplier.business_name', $this->proveedor->business_name)
            ->assertJsonPath('data.purchase_receipt.receipt_number', $rec->receipt_number);
    }
}
