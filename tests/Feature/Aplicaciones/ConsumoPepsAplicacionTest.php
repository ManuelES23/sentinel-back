<?php

namespace Tests\Feature\Aplicaciones;

use App\Models\Aplicacion;
use App\Models\CapaCosto;
use App\Models\CosteoAgricola;
use App\Models\InventoryStock;
use App\Services\Inventory\ConsumidorInventarioAplicacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\Concerns\CreatesAplicacionesFixtures;
use Tests\TestCase;

class ConsumoPepsAplicacionTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures, CreatesAplicacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAplicacionesFixtures();
    }

    private function costoDe(Aplicacion $apl): float
    {
        return (float) CosteoAgricola::where('fuente_id', $apl->id)->sum('costo_total');
    }

    public function test_consumo_toma_el_lote_mas_viejo_aunque_su_fila_sea_mas_nueva(): void
    {
        // Fila B creada primero pero con capa más reciente; fila A creada después con capa más vieja.
        $this->entrarStock($this->almacenA, $this->insumo, 10, 'L-B', 200);
        $this->entrarStock($this->almacenA, $this->insumo, 10, 'L-A', 100);
        CapaCosto::where('lot_number', 'L-B')->update(['fecha_entrada' => '2026-10-05']);
        CapaCosto::where('lot_number', 'L-A')->update(['fecha_entrada' => '2026-10-01']);

        $apl = $this->crearAplicacion(['superficie_aplicada' => 2]); // dosis 2 x 2 = 4 L
        app(ConsumidorInventarioAplicacion::class)->consumir($apl);

        $this->assertEqualsWithDelta(400, $this->costoDe($apl), 0.0001);
        $this->assertEqualsWithDelta(6, (float) InventoryStock::where('lot_number', 'L-A')->value('quantity'), 0.0001);
        $this->assertEqualsWithDelta(10, (float) InventoryStock::where('lot_number', 'L-B')->value('quantity'), 0.0001);
    }

    public function test_editar_aplicacion_tras_compra_a_otro_precio_conserva_su_costo(): void
    {
        $this->entrarStock($this->almacenA, $this->insumo, 10, null, 100);
        $apl = $this->crearAplicacion(['superficie_aplicada' => 2]);
        $consumidor = app(ConsumidorInventarioAplicacion::class);
        $consumidor->consumir($apl);

        $this->comprar($this->almacenA, $this->insumo, 50, 150); // capa nueva a otro precio
        $consumidor->reconsumir(Aplicacion::find($apl->id));

        $this->assertEqualsWithDelta(400, $this->costoDe($apl), 0.0001);
    }

    public function test_borrar_aplicacion_devuelve_al_costo_original(): void
    {
        $this->entrarStock($this->almacenA, $this->insumo, 10, null, 100);
        $apl = $this->crearAplicacion(['superficie_aplicada' => 2]);
        app(ConsumidorInventarioAplicacion::class)->consumir($apl);
        $this->comprar($this->almacenA, $this->insumo, 50, 150);

        app(ConsumidorInventarioAplicacion::class)->revertir(Aplicacion::find($apl->id));

        $fila = InventoryStock::where('product_id', $this->insumo->id)->where('entity_id', $this->almacenA->id)->first();
        $this->assertEqualsWithDelta(60, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(10 * 100 + 50 * 150, (float) $fila->total_cost, 0.0001);
    }
}
