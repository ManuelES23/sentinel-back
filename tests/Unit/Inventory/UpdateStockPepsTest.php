<?php

namespace Tests\Unit\Inventory;

use App\Models\CapaCosto;
use App\Models\InventoryStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class UpdateStockPepsTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
    }

    public function test_entrada_y_salida_mueven_capas_y_ya_no_promedian(): void
    {
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, 8, 100);
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, 50, 120);
        $fila = InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -10, 0, null, null, null, false, 'empaque_produccion:1');

        $this->assertEqualsWithDelta(48, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(5760, (float) $fila->total_cost, 0.0001);
        $this->assertEqualsWithDelta(48, (float) CapaCosto::sum('cantidad_restante'), 0.0001);
    }

    public function test_devolver_por_referencia_regresa_porciones_y_resto_al_costo_de_respaldo(): void
    {
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, 10, 100);
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -4, 0, null, null, null, true, 'empaque_produccion:7');

        $fila = InventoryStock::devolverPorReferencia($this->insumo->id, $this->almacenA->id, 6, 'empaque_produccion:7', 55);

        // 4 regresan a $100 y 2 sin porciones entran al costo de receta $55.
        $this->assertEqualsWithDelta(12, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(10 * 100 + 2 * 55, (float) $fila->total_cost, 0.0001);
    }
}
