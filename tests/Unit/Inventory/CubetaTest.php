<?php

namespace Tests\Unit\Inventory;

use App\Models\CapaCosto;
use App\Support\Inventario\Cubeta;
use App\Support\Inventario\ResultadoMovimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class CubetaTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures;

    public function test_cubeta_normaliza_lote_vacio(): void
    {
        $this->assertNull((new Cubeta(1, 2, null, ''))->lote);
        $this->assertNull((new Cubeta(1, 2, null, '   '))->lote);
        $this->assertSame('L-1', (new Cubeta(1, 2, null, ' L-1 '))->lote);
    }

    public function test_tablas_y_scope_de_cubeta(): void
    {
        $this->setUpAlmacenFixtures();
        $this->assertTrue(Schema::hasTable('inventory_cost_layers'));
        $this->assertTrue(Schema::hasTable('inventory_cost_layer_consumptions'));

        $base = ['product_id' => $this->insumo->id, 'entity_id' => $this->almacenA->id, 'fecha_entrada' => now(),
            'unit_cost' => 10, 'cantidad_inicial' => 5, 'cantidad_restante' => 5, 'origen' => CapaCosto::ORIGEN_INICIAL];
        CapaCosto::create($base + ['lot_number' => null]);
        CapaCosto::create($base + ['lot_number' => 'L-1']);

        $this->assertSame(1, CapaCosto::deCubeta(new Cubeta($this->insumo->id, $this->almacenA->id))->count());
        $this->assertSame(1, CapaCosto::deCubeta(new Cubeta($this->insumo->id, $this->almacenA->id, null, 'L-1'))->count());
    }

    public function test_resultado_costo_unitario(): void
    {
        $this->assertEqualsWithDelta(104.0, (new ResultadoMovimiento(10, 1040))->costoUnitario(), 0.000001);
        $this->assertSame(0.0, ResultadoMovimiento::vacio()->costoUnitario());
    }
}
