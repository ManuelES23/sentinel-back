<?php

namespace Tests\Feature\Aplicaciones;

use App\Models\CosteoAgricola;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Services\Inventory\ConsumidorInventarioAplicacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\Concerns\CreatesAplicacionesFixtures;
use Tests\TestCase;

class RevertirConsumoAplicacionTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures, CreatesAplicacionesFixtures;

    private ConsumidorInventarioAplicacion $consumidor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAplicacionesFixtures();
        $this->consumidor = app(ConsumidorInventarioAplicacion::class);
    }

    private function movimientosDeAplicacion(): int
    {
        return InventoryMovement::where('reference_type', 'aplicacion')->count();
    }

    public function test_revertir_devuelve_el_stock_y_borra_costeo_y_movimiento(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 5, 'L-1', 10);
        $this->darLote($this->almacenA, $this->insumo, 10, 'L-2', 20);
        $aplicacion = $this->crearAplicacion(); // 8 L repartidos en dos lotes
        $this->consumidor->consumir($aplicacion);
        $this->assertEquals(7, $this->stockTotal($this->almacenA, $this->insumo));

        $this->consumidor->revertir($aplicacion->fresh());

        $this->assertEquals(15, $this->stockTotal($this->almacenA, $this->insumo));
        $l1 = InventoryStock::where('lot_number', 'L-1')->first();
        $l2 = InventoryStock::where('lot_number', 'L-2')->first();
        $this->assertEquals(5, $l1->quantity);
        $this->assertEquals(10, $l1->unit_cost);
        $this->assertEquals(10, $l2->quantity);
        $this->assertEquals(20, $l2->unit_cost);

        $this->assertSame(0, CosteoAgricola::count());
        $this->assertSame(0, $this->movimientosDeAplicacion());
        $this->assertSame(1, InventoryMovement::withTrashed()->where('reference_type', 'aplicacion')->count());
        $this->assertNull($aplicacion->fresh()->inventory_movement_id);
    }

    public function test_revertir_deja_el_kardex_alineado_con_el_stock(): void
    {
        $this->entrarStock($this->almacenA, $this->insumo, 5, 'L-1', 10);
        $this->entrarStock($this->almacenA, $this->insumo, 10, 'L-2', 20);
        $aplicacion = $this->crearAplicacion();

        $this->consumidor->consumir($aplicacion);
        $this->assertEquals(7, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertEquals(7, $this->kardexSaldo($this->almacenA, $this->insumo));

        $this->consumidor->revertir($aplicacion->fresh());

        $this->assertEquals(15, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertEquals(15, $this->kardexSaldo($this->almacenA, $this->insumo));
    }

    public function test_revertir_una_aplicacion_historica_no_hace_nada(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $aplicacion = $this->crearAplicacion(['almacen_id' => null, 'enterprise_id' => null]);

        $this->consumidor->revertir($aplicacion);

        $this->assertEquals(20, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertSame(0, InventoryMovement::withTrashed()->count());
    }

    public function test_reconsumir_aplica_la_dosis_nueva_sin_restos_del_consumo_anterior(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $aplicacion = $this->crearAplicacion(); // 8 L
        $this->consumidor->consumir($aplicacion);
        $movimientoViejo = $aplicacion->fresh()->inventory_movement_id;

        $aplicacion->detalles()->update(['dosis' => 1]); // 4 L
        $this->consumidor->reconsumir($aplicacion->fresh());

        $this->assertEquals(16, $this->stockTotal($this->almacenA, $this->insumo));
        $aplicacion->refresh();
        $this->assertNotNull($aplicacion->inventory_movement_id);
        $this->assertNotSame($movimientoViejo, $aplicacion->inventory_movement_id);
        $this->assertSame(1, $this->movimientosDeAplicacion());
        $this->assertSame(2, InventoryMovement::withTrashed()->where('reference_type', 'aplicacion')->count());
        $costeo = CosteoAgricola::where('fuente_id', $aplicacion->id)->sole();
        $this->assertEquals(4, $costeo->cantidad);
        $this->assertEquals(40, $costeo->costo_total);
    }

    public function test_reconsumir_con_otro_almacen_devuelve_el_stock_al_original(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $this->darLote($this->almacenB, $this->insumo, 20, 'L-1', 10);
        $aplicacion = $this->crearAplicacion();
        $this->consumidor->consumir($aplicacion);
        $this->assertEquals(12, $this->stockTotal($this->almacenA, $this->insumo));

        // La aplicación ya apunta al almacén B cuando se revierte: el retorno debe ir al A (el del movimiento)
        $aplicacion->update(['almacen_id' => $this->almacenB->id]);
        $this->consumidor->reconsumir($aplicacion->fresh());

        $this->assertEquals(20, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertEquals(12, $this->stockTotal($this->almacenB, $this->insumo));
    }

    public function test_si_reconsumir_falla_todo_queda_como_estaba(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $aplicacion = $this->crearAplicacion(); // 8 L consumidos, quedan 12
        $this->consumidor->consumir($aplicacion);
        $movimientoOriginal = $aplicacion->fresh()->inventory_movement_id;

        $aplicacion->detalles()->update(['dosis' => 10]); // 40 L: más de lo que hay aun devolviendo los 8

        try {
            $this->consumidor->reconsumir($aplicacion->fresh());
            $this->fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('productos.0.dosis', $e->errors());
        }

        $this->assertEquals(12, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertSame($movimientoOriginal, $aplicacion->fresh()->inventory_movement_id);
        $this->assertSame(1, $this->movimientosDeAplicacion());
        $this->assertSame(1, CosteoAgricola::count());
        $this->assertEquals(8, CosteoAgricola::first()->cantidad);
    }
}
