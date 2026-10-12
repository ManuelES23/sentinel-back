<?php

namespace Tests\Feature\Inventario\Peps;

use App\Models\InventoryStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class EmpaqueProduccionPepsTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures;

    public function test_consumo_y_reverso_de_produccion_devuelven_las_mismas_capas(): void
    {
        $this->setUpAlmacenFixtures();
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, 10, 100);
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, 10, 150);

        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -12, 7, null, null, null, true, 'empaque_produccion:5');
        $fila = InventoryStock::devolverPorReferencia($this->insumo->id, $this->almacenA->id, 12, 'empaque_produccion:5', 7);

        $this->assertEqualsWithDelta(20, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(2500, (float) $fila->total_cost, 0.0001);
    }
}
