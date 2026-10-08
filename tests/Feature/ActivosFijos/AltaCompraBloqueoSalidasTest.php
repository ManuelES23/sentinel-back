<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\FixedAssetReceiptUnit;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAltaCompraFixtures;
use Tests\TestCase;

class AltaCompraBloqueoSalidasTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAltaCompraFixtures;

    private const URL_MOVIMIENTOS = '/api/splendidfarms/inventario/operaciones/movimientos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAltaCompraFixtures();
    }

    private function stockDe(string $lote): float
    {
        return (float) InventoryStock::where('product_id', $this->insumo->id)
            ->where('entity_id', $this->almacenA->id)->where('lot_number', $lote)->value('quantity');
    }

    public function test_no_se_puede_sacar_stock_que_respalda_unidades_vigentes(): void
    {
        $this->recepcionConfirmada(6, 'L-100'); // 6 en stock y 6 unidades pendientes

        try {
            InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -1, 0, 'L-100');
            $this->fail('Debió rechazar la salida.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        $this->assertEquals(6, $this->stockDe('L-100'));
    }

    public function test_con_stock_sobrante_si_se_permite_sacar_lo_que_no_respalda_nada(): void
    {
        $this->recepcionConfirmada(6, 'L-100');
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, 4, 0, 'L-100'); // 10 en total

        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -4, 0, 'L-100');
        $this->assertEquals(6, $this->stockDe('L-100'));

        $this->expectException(ValidationException::class);
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -1, 0, 'L-100');
    }

    public function test_descartar_las_unidades_libera_el_stock(): void
    {
        $rec = $this->recepcionConfirmada(3, 'L-100');
        FixedAssetReceiptUnit::where('purchase_receipt_id', $rec->id)->update([
            'status' => 'discarded', 'discarded_reason' => 'No era activo',
        ]);

        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -3, 0, 'L-100');

        $this->assertEquals(0, $this->stockDe('L-100'));
    }

    public function test_un_activo_en_baja_libera_el_stock(): void
    {
        $rec = $this->recepcionConfirmada(1, 'L-100');
        $unidad = FixedAssetReceiptUnit::where('purchase_receipt_id', $rec->id)->first();
        $activo = \App\Models\FixedAsset::create([
            'enterprise_id' => $this->empresa->id, 'code' => 'SF-AF-000900', 'name' => 'Equipo',
            'category_id' => $this->tipoActivo->id, 'branch_id' => $this->sucursal->id, 'entity_id' => $this->almacenA->id,
            'status' => 'en_uso', 'is_active' => true,
        ]);
        $unidad->update(['status' => 'registered', 'fixed_asset_id' => $activo->id]);

        try {
            InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -1, 0, 'L-100');
            $this->fail('Con el activo vigente debió rechazar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        $activo->update(['status' => 'baja']);
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -1, 0, 'L-100');

        $this->assertEquals(0, $this->stockDe('L-100'));
    }

    public function test_sin_lote_el_bloqueo_tambien_aplica(): void
    {
        $this->insumo->update(['track_lots' => false, 'track_expiry' => false]);
        $rec = $this->recepcionConfirmada(2, '');
        $this->assertGreaterThan(0, FixedAssetReceiptUnit::where('purchase_receipt_id', $rec->id)->count());

        $this->expectException(ValidationException::class);
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -1, 0, null);
    }

    public function test_un_traspaso_del_stock_respaldado_responde_422_y_no_500(): void
    {
        $this->recepcionConfirmada(5, 'L-100');
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA, $this->almacenB]));

        $this->postJson(self::URL_MOVIMIENTOS, [
            'movement_type_id' => $this->tipoTransferencia->id,
            'source_entity_id' => $this->almacenA->id,
            'destination_entity_id' => $this->almacenB->id,
            'details' => [['product_id' => $this->insumo->id, 'quantity' => 2, 'lot_number' => 'L-100']],
        ], $this->headersEmpresa())->assertStatus(422)->assertJsonValidationErrors('stock');

        $this->assertEquals(5, $this->stockDe('L-100'));
    }

    public function test_aprobar_una_salida_del_stock_respaldado_responde_422(): void
    {
        $this->recepcionConfirmada(5, 'L-100');
        $usuario = $this->crearUsuarioDeCampo([$this->almacenA]);
        Sanctum::actingAs($usuario);

        $respuesta = $this->postJson(self::URL_MOVIMIENTOS, [
            'movement_type_id' => $this->tipoSalida->id,
            'source_entity_id' => $this->almacenA->id,
            'details' => [['product_id' => $this->insumo->id, 'quantity' => 2, 'lot_number' => 'L-100']],
        ], $this->headersEmpresa());

        // Si la salida exige aprobación aparte, se crea pendiente y el rechazo ocurre al aprobar.
        if ($respuesta->status() === 201) {
            $id = $respuesta->json('data.id');
            $this->postJson(self::URL_MOVIMIENTOS . "/$id/approve", [], $this->headersEmpresa())
                ->assertStatus(422)->assertJsonValidationErrors('stock');
            $this->assertNotSame('approved', InventoryMovement::find($id)->status);
        } else {
            $respuesta->assertStatus(422);
        }

        $this->assertEquals(5, $this->stockDe('L-100'));
    }
}
