<?php

namespace Tests\Feature\Inventario\Peps;

use App\Models\CapaCosto;
use App\Models\ConsumoCapa;
use App\Models\InventoryStock;
use App\Services\Inventory\CapasCosto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class ReferenciaProduccionPepsTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures;

    private function entra(float $cantidad, float $costo): void
    {
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, $cantidad, $costo);
    }

    private function sale(float $cantidad, string $referencia): void
    {
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -$cantidad, 0, null, null, null, true, $referencia);
    }

    private function devolverExacto(float $cantidad, string $referencia)
    {
        return InventoryStock::devolverPorReferenciaExacta($this->insumo->id, $this->almacenA->id, $cantidad, $referencia);
    }

    private function fila(): InventoryStock
    {
        return InventoryStock::where('product_id', $this->insumo->id)->where('entity_id', $this->almacenA->id)->firstOrFail();
    }

    public function test_devolver_exacto_no_crea_stock_si_se_pide_de_mas(): void
    {
        $this->setUpAlmacenFixtures();
        $this->entra(10, 100);
        $this->sale(4, 'empaque_consumo:1');
        $capasAntes = CapaCosto::count();

        $r = $this->devolverExacto(6, 'empaque_consumo:1');

        $this->assertEqualsWithDelta(4, $r->cantidad, 0.0001);
        $this->assertEqualsWithDelta(400, $r->importe, 0.0001);
        $this->assertEqualsWithDelta(10, (float) $this->fila()->quantity, 0.0001);
        $this->assertEqualsWithDelta(1000, (float) $this->fila()->total_cost, 0.0001);
        $this->assertSame($capasAntes, CapaCosto::count());
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_partir_referencia_conserva_costos(): void
    {
        $this->setUpAlmacenFixtures();
        $this->entra(5, 100);
        $this->entra(5, 200);
        $this->sale(8, 'A');

        $movido = app(CapasCosto::class)->partirReferencia('A', 'B', 3);
        // Las últimas porciones de A: 3 de la capa a $200.
        $this->assertEqualsWithDelta(600, $movido, 0.0001);
        $this->assertEqualsWithDelta(2, (float) $this->fila()->quantity, 0.0001);

        $rB = $this->devolverExacto(3, 'B');
        $this->assertEqualsWithDelta(3, $rB->cantidad, 0.0001);
        $this->assertEqualsWithDelta(600, $rB->importe, 0.0001);
        $this->assertEqualsWithDelta(5, (float) $this->fila()->quantity, 0.0001);
        $this->assertEqualsWithDelta(2 * 200 + 3 * 200,(float) $this->fila()->total_cost, 0.0001);

        $rA = $this->devolverExacto(5, 'A');
        $this->assertEqualsWithDelta(5, $rA->cantidad, 0.0001);
        $this->assertEqualsWithDelta(500, $rA->importe, 0.0001);
        $this->assertEqualsWithDelta(10, (float) $this->fila()->quantity, 0.0001);
        $this->assertEqualsWithDelta(1500, (float) $this->fila()->total_cost, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_partir_mas_de_lo_vigente_mueve_solo_lo_que_existe(): void
    {
        $this->setUpAlmacenFixtures();
        $this->entra(5, 100);
        $this->entra(5, 200);
        $this->sale(8, 'A');
        $capas = CapaCosto::orderBy('id')->get(['id', 'cantidad_restante'])->toArray();

        $movido = app(CapasCosto::class)->partirReferencia('A', 'B', 20);

        $this->assertEqualsWithDelta(5 * 100 + 3 * 200, $movido, 0.0001);
        $this->assertEqualsWithDelta(8, (float) ConsumoCapa::where('referencia', 'B')->sum('cantidad'), 0.0001);
        $this->assertEqualsWithDelta(0, (float) ConsumoCapa::where('referencia', 'A')->sum('cantidad'), 0.0001);
        $this->assertSame($capas, CapaCosto::orderBy('id')->get(['id', 'cantidad_restante'])->toArray());
        $this->assertEqualsWithDelta(2, (float) $this->fila()->quantity, 0.0001);

        $r = $this->devolverExacto(10, 'B');
        $this->assertEqualsWithDelta(8, $r->cantidad, 0.0001);
        $this->assertEqualsWithDelta(1100, $r->importe, 0.0001);
        $this->assertEqualsWithDelta(10, (float) $this->fila()->quantity, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_partir_respeta_lo_ya_devuelto_de_la_porcion(): void
    {
        $this->setUpAlmacenFixtures();
        $this->entra(10, 100);
        $this->sale(6, 'A');
        $this->devolverExacto(2, 'A'); // la porción queda cantidad 6, devuelta 2 → vigente 4

        $movido = app(CapasCosto::class)->partirReferencia('A', 'B', 10);

        $this->assertEqualsWithDelta(400, $movido, 0.0001);
        $original = ConsumoCapa::where('referencia', 'A')->sole();
        $this->assertEqualsWithDelta(2, (float) $original->cantidad, 0.0001);
        $this->assertEqualsWithDelta(2, (float) $original->cantidad_devuelta, 0.0001);
        $nueva = ConsumoCapa::where('referencia', 'B')->sole();
        $this->assertEqualsWithDelta(4, (float) $nueva->cantidad, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $nueva->cantidad_devuelta, 0.0001);
        $this->assertSame($original->layer_id, $nueva->layer_id);
        $this->assertEqualsWithDelta(100, (float) $nueva->unit_cost, 0.0001);

        $this->assertEqualsWithDelta(0, $this->devolverExacto(1, 'A')->cantidad, 0.0001);
        $this->assertEqualsWithDelta(4, $this->devolverExacto(4, 'B')->cantidad, 0.0001);
        $this->assertEqualsWithDelta(10, (float) $this->fila()->quantity, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_referencia_sin_porciones_no_mueve_nada(): void
    {
        $this->setUpAlmacenFixtures();
        $this->entra(10, 100);

        $this->assertEqualsWithDelta(0, app(CapasCosto::class)->partirReferencia('X', 'Y', 3), 0.0001);
        $this->assertSame(0, ConsumoCapa::count());

        $r = $this->devolverExacto(3, 'X');
        $this->assertEqualsWithDelta(0, $r->cantidad, 0.0001);
        $this->assertEqualsWithDelta(0, $r->importe, 0.0001);
        $this->assertEqualsWithDelta(10, (float) $this->fila()->quantity, 0.0001);
        $this->assertSame(1, CapaCosto::count());
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }
}
