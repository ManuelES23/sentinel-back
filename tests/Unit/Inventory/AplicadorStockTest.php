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

    public function test_disminuir_escribe_costo_peps_en_renglon_y_kardex(): void
    {
        $mov = $this->movimiento();
        foreach ([[8, 100, 'L'], [50, 120, 'M']] as [$q, $c, $s]) {
            $e = InventoryMovementDetail::create(['movement_id' => $mov->id, 'product_id' => $this->insumo->id, 'quantity' => $q, 'unit_cost' => $c]);
            app(AplicadorStock::class)->aumentar($e, $this->almacenA->id, 'entity', $mov);
        }
        $salida = InventoryMovementDetail::create(['movement_id' => $mov->id, 'product_id' => $this->insumo->id, 'quantity' => 10, 'unit_cost' => 999]);

        $r = app(AplicadorStock::class)->disminuir($salida, $this->almacenA->id, 'entity', $mov);

        $this->assertEqualsWithDelta(1040, $r->importe, 0.0001);
        $this->assertEqualsWithDelta(104, (float) $salida->fresh()->unit_cost, 0.0001);
        $this->assertEqualsWithDelta(1040, (float) $salida->fresh()->total_cost, 0.0001);
        $k = InventoryKardex::where('movement_id', $mov->id)->latest('id')->first();
        $this->assertSame('decrease', $k->transaction_type);
        $this->assertEqualsWithDelta(1040, (float) $k->total_cost, 0.0001);
        $this->assertEqualsWithDelta(5760, (float) $k->balance_value, 0.0001);
    }

    public function test_devolver_registra_kardex_como_aumento_con_descripcion(): void
    {
        $mov = $this->movimiento();
        $e = InventoryMovementDetail::create(['movement_id' => $mov->id, 'product_id' => $this->insumo->id, 'quantity' => 5, 'unit_cost' => 100]);
        app(AplicadorStock::class)->aumentar($e, $this->almacenA->id, 'entity', $mov);
        $s = InventoryMovementDetail::create(['movement_id' => $mov->id, 'product_id' => $this->insumo->id, 'quantity' => 2]);
        app(AplicadorStock::class)->disminuir($s, $this->almacenA->id, 'entity', $mov);

        app(AplicadorStock::class)->devolver($s, $this->almacenA->id, 'entity', $mov, 2, null, null, 'Cancelación de prueba');

        $k = InventoryKardex::where('movement_id', $mov->id)->latest('id')->first();
        $this->assertSame('increase', $k->transaction_type);
        $this->assertSame('Cancelación de prueba', $k->description);
        $this->assertEqualsWithDelta(500, (float) $k->balance_value, 0.0001);
    }
}
