<?php

namespace Tests\Unit\Inventory;

use App\Models\InventoryKardex;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementDetail;
use App\Models\InventoryStock;
use App\Services\Inventory\AplicadorStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class AplicadorStockTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
    }

    private function movimiento(): InventoryMovement
    {
        $admin = $this->crearAdmin();

        return InventoryMovement::create([
            'document_number' => 'in-TEST-00001',
            'movement_type_id' => $this->tipoEntrada->id,
            'movement_date' => now()->toDateString(),
            'destination_entity_id' => $this->almacenA->id,
            'destination_entity_type' => 'entity',
            'status' => 'approved',
            'created_by' => $admin->id,
        ]);
    }

    public function test_aumentar_crea_stock_por_lote_y_kardex(): void
    {
        $mov = $this->movimiento();
        $det = InventoryMovementDetail::create([
            'movement_id' => $mov->id, 'product_id' => $this->insumo->id, 'quantity' => 5,
            'unit_cost' => 12, 'total_cost' => 60, 'lot_number' => 'L-1', 'expiry_date' => '2027-01-31',
        ]);

        app(AplicadorStock::class)->aumentar($det, $this->almacenA->id, 'entity', $mov);

        $stock = InventoryStock::where('entity_id', $this->almacenA->id)->where('lot_number', 'L-1')->first();
        $this->assertNotNull($stock);
        $this->assertEquals(5, (float) $stock->quantity);
        $this->assertSame('2027-01-31', $stock->expiry_date->toDateString());
        $this->assertSame(1, InventoryKardex::where('movement_id', $mov->id)->count());
    }

    public function test_disminuir_resta_stock(): void
    {
        $this->darStock($this->almacenA, $this->insumo, 8, 'L-1', '2027-01-31');
        $mov = $this->movimiento();
        $det = InventoryMovementDetail::create([
            'movement_id' => $mov->id, 'product_id' => $this->insumo->id, 'quantity' => 3,
            'unit_cost' => 10, 'total_cost' => 30, 'lot_number' => 'L-1',
        ]);

        app(AplicadorStock::class)->disminuir($det, $this->almacenA->id, 'entity', $mov);

        $this->assertEquals(5, (float) InventoryStock::where('entity_id', $this->almacenA->id)->where('lot_number', 'L-1')->value('quantity'));
    }
}
