<?php

namespace Tests\Feature\Aplicaciones;

use App\Models\Aplicacion;
use App\Models\CosteoAgricola;
use App\Models\InventoryKardex;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\MovementType;
use App\Models\Product;
use App\Services\Inventory\ConsumidorInventarioAplicacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\Concerns\CreatesAplicacionesFixtures;
use Tests\TestCase;

class ConsumoInventarioAplicacionTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures, CreatesAplicacionesFixtures;

    private ConsumidorInventarioAplicacion $consumidor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAplicacionesFixtures();
        $this->consumidor = app(ConsumidorInventarioAplicacion::class);
    }

    private function insumoExtra(string $codigo, string $nombre, $unidad): Product
    {
        $producto = Product::create([
            'code' => $codigo, 'name' => $nombre, 'unit_id' => $unidad->id,
            'product_type' => 'consumable', 'track_inventory' => true,
        ]);
        $producto->enterprises()->attach($this->empresa->id);

        return $producto;
    }

    private function esperarError(Aplicacion $aplicacion, string $clave): void
    {
        try {
            $this->consumidor->consumir($aplicacion);
            $this->fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($clave, $e->errors());
        }
    }

    private function assertSinCambios(float $stockEsperado): void
    {
        $this->assertEquals($stockEsperado, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertSame(0, InventoryMovement::withTrashed()->count());
        $this->assertSame(0, CosteoAgricola::withTrashed()->count());
    }

    public function test_consume_de_un_solo_lote_y_deja_movimiento_y_costeo(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $aplicacion = $this->crearAplicacion(); // 2 L/ha x 4 ha = 8 L

        $this->consumidor->consumir($aplicacion);

        $this->assertEquals(12, $this->stockTotal($this->almacenA, $this->insumo));

        $aplicacion->refresh();
        $movimiento = InventoryMovement::with('details')->findOrFail($aplicacion->inventory_movement_id);
        $this->assertSame($this->tipoSalida->id, (int) $movimiento->movement_type_id);
        $this->assertSame($this->almacenA->id, (int) $movimiento->source_entity_id);
        $this->assertSame('aplicacion', $movimiento->reference_type);
        $this->assertSame($aplicacion->id, (int) $movimiento->reference_id);
        $this->assertSame('approved', $movimiento->status);
        $this->assertCount(1, $movimiento->details);
        $this->assertSame('L-1', $movimiento->details[0]->lot_number);
        $this->assertEquals(8, $movimiento->details[0]->quantity);
        $this->assertEquals(10, $movimiento->details[0]->unit_cost);
        $this->assertSame(1, InventoryKardex::where('movement_id', $movimiento->id)->where('transaction_type', 'decrease')->count());

        $detalle = $aplicacion->detalles()->first();
        $this->assertEquals(8, $detalle->base_quantity);
        $this->assertEquals(1, $detalle->conversion_factor);

        $costeo = CosteoAgricola::where('tipo_fuente', 'movimiento_inventario')->where('fuente_id', $aplicacion->id)->sole();
        $this->assertSame($this->temporada->id, (int) $costeo->temporada_id);
        $this->assertSame($this->lote->id, (int) $costeo->lote_id);
        $this->assertNull($costeo->etapa_id);
        $this->assertSame('agroquimico', $costeo->categoria);
        $this->assertSame($this->insumo->id, (int) $costeo->product_id);
        $this->assertEquals(8, $costeo->cantidad);
        $this->assertSame($this->unidad->id, (int) $costeo->unit_id);
        $this->assertEquals(10, $costeo->costo_unitario);
        $this->assertEquals(80, $costeo->costo_total);
        $this->assertSame($this->agronomo->id, (int) $costeo->user_id);
        $this->assertStringContainsString($aplicacion->folio, $costeo->descripcion);
        $this->assertStringContainsString('Clorotalonil 720', $costeo->descripcion);
    }

    public function test_reparte_entre_lotes_y_costea_con_promedio_ponderado(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 5, 'L-1', 10);
        $this->darLote($this->almacenA, $this->insumo, 10, 'L-2', 20);
        $aplicacion = $this->crearAplicacion(); // 8 L: 5 de L-1 (50) + 3 de L-2 (60)

        $this->consumidor->consumir($aplicacion);

        $this->assertEquals(0, InventoryStock::where('lot_number', 'L-1')->value('quantity'));
        $this->assertEquals(7, InventoryStock::where('lot_number', 'L-2')->value('quantity'));

        $movimiento = InventoryMovement::with('details')->findOrFail($aplicacion->fresh()->inventory_movement_id);
        $this->assertCount(2, $movimiento->details);
        $this->assertSame('L-1', $movimiento->details[0]->lot_number);
        $this->assertEquals(5, $movimiento->details[0]->quantity);
        $this->assertSame('L-2', $movimiento->details[1]->lot_number);
        $this->assertEquals(3, $movimiento->details[1]->quantity);

        $costeo = CosteoAgricola::where('fuente_id', $aplicacion->id)->sole();
        $this->assertEquals(8, $costeo->cantidad);
        $this->assertEquals(110, $costeo->costo_total);
        $this->assertEquals(13.75, $costeo->costo_unitario);
    }

    public function test_convierte_kilos_por_hectarea_a_gramos_de_stock(): void
    {
        $polvo = $this->insumoExtra('PROD-00002', 'Sulfato de cobre', $this->gramo);
        $this->darLote($this->almacenA, $polvo, 5000, 'G-1', 0.02);
        $aplicacion = $this->crearAplicacion(['superficie_aplicada' => 2], [
            ['product' => $polvo, 'dosis' => 1.5, 'unidad' => $this->kilo], // 1.5 kg/ha x 2 ha = 3 kg = 3000 g
        ]);

        $this->consumidor->consumir($aplicacion);

        $this->assertEquals(2000, $this->stockTotal($this->almacenA, $polvo));
        $detalle = $aplicacion->detalles()->first();
        $this->assertEquals(1000, $detalle->conversion_factor);
        $this->assertEquals(3000, $detalle->base_quantity);

        $costeo = CosteoAgricola::where('fuente_id', $aplicacion->id)->sole();
        $this->assertEquals(3000, $costeo->cantidad);
        $this->assertSame($this->gramo->id, (int) $costeo->unit_id);
        $this->assertEquals(0.02, $costeo->costo_unitario);
        $this->assertEquals(60, $costeo->costo_total);
    }

    public function test_convierte_litros_por_hectarea_a_mililitros_de_stock(): void
    {
        $liquido = $this->insumoExtra('PROD-00003', 'Adherente', $this->mililitro);
        $this->darLote($this->almacenA, $liquido, 5000, 'M-1', 0.05);
        $aplicacion = $this->crearAplicacion(['superficie_aplicada' => 2], [
            ['product' => $liquido, 'dosis' => 0.5, 'unidad' => $this->unidad], // 0.5 L/ha x 2 ha = 1 L = 1000 mL
        ]);

        $this->consumidor->consumir($aplicacion);

        $this->assertEquals(4000, $this->stockTotal($this->almacenA, $liquido));
        $this->assertEquals(1000, $aplicacion->detalles()->first()->base_quantity);
        $this->assertEquals(50, CosteoAgricola::where('fuente_id', $aplicacion->id)->sole()->costo_total);
    }

    public function test_stock_insuficiente_da_422_y_no_deja_nada(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 5, 'L-1', 10);
        $aplicacion = $this->crearAplicacion(); // necesita 8 L

        $this->esperarError($aplicacion, 'productos.0.dosis');

        $this->assertSinCambios(5);
    }

    public function test_dos_renglones_del_mismo_producto_no_se_disputan_el_mismo_stock(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 10, 'L-1', 10);
        $aplicacion = $this->crearAplicacion([], [
            ['product' => $this->insumo, 'dosis' => 2, 'unidad' => $this->unidad], // 8 L
            ['product' => $this->insumo, 'dosis' => 1, 'unidad' => $this->unidad], // 4 L: 12 > 10
        ]);

        $this->esperarError($aplicacion, 'productos.1.dosis');

        $this->assertSinCambios(10);
    }

    public function test_si_un_renglon_falla_no_se_aplica_ninguno(): void
    {
        $otro = $this->insumoExtra('PROD-00004', 'Otro insumo', $this->unidad);
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10); // alcanza
        // $otro no tiene stock
        $aplicacion = $this->crearAplicacion([], [
            ['product' => $this->insumo, 'dosis' => 2, 'unidad' => $this->unidad],
            ['product' => $otro, 'dosis' => 1, 'unidad' => $this->unidad],
        ]);

        $this->esperarError($aplicacion, 'productos.1.dosis');

        $this->assertSinCambios(20);
    }

    public function test_unidad_de_dosis_de_otro_tipo_que_la_del_producto_da_422(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $aplicacion = $this->crearAplicacion([], [
            ['product' => $this->insumo, 'dosis' => 1, 'unidad' => $this->kilo], // kg contra un producto en litros
        ]);

        $this->esperarError($aplicacion, 'productos.0.unidad_dosis_id');

        $this->assertSinCambios(20);
    }

    public function test_producto_sin_unidad_de_stock_da_422(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $aplicacion = $this->crearAplicacion();
        $this->insumo->update(['unit_id' => null]);

        $this->esperarError($aplicacion, 'productos.0.product_id');

        $this->assertSinCambios(20);
    }

    public function test_sin_almacen_da_422(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $aplicacion = $this->crearAplicacion(['almacen_id' => null]);

        $this->esperarError($aplicacion, 'almacen_id');

        $this->assertSinCambios(20);
    }

    public function test_sin_superficie_da_422(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $aplicacion = $this->crearAplicacion(['superficie_aplicada' => null]);

        $this->esperarError($aplicacion, 'superficie_aplicada');

        $this->assertSinCambios(20);
    }

    public function test_sin_tipo_de_movimiento_consumo_da_422_y_no_truena(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $aplicacion = $this->crearAplicacion();
        MovementType::where('code', 'CONSUMO')->delete();

        $this->esperarError($aplicacion, 'movimiento');

        $this->assertSinCambios(20);
    }

    public function test_no_consume_stock_reservado(): void
    {
        $stock = $this->darLote($this->almacenA, $this->insumo, 10, 'L-1', 10);
        $stock->update(['reserved_quantity' => 4]); // disponibles: 6
        $aplicacion = $this->crearAplicacion(); // necesita 8

        $this->esperarError($aplicacion, 'productos.0.dosis');

        $this->assertSinCambios(10);
    }

    public function test_renglon_con_dosis_cero_no_genera_costeo_ni_truena(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $aplicacion = $this->crearAplicacion([], [
            ['product' => $this->insumo, 'dosis' => 0, 'unidad' => $this->unidad],
        ]);

        $this->consumidor->consumir($aplicacion);

        $this->assertEquals(20, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertSame(0, CosteoAgricola::count());
    }
}
