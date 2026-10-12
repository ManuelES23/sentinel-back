<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\Empaque;

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\UnitOfMeasure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesReporteEmpaqueFixtures;
use Tests\TestCase;

/**
 * El consumo de insumos de una producción de Empaque mueve capas PEPS con referencia
 * "empaque_produccion:{id}" y su reverso (editar/borrar) devuelve las mismas porciones.
 */
class ProduccionEmpaquePepsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesReporteEmpaqueFixtures;

    private const URL = '/api/splendidfarms/operacion-agricola/empaque/produccion';

    private Product $insumo;
    private Recipe $receta;
    private int $procesoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReporteEmpaqueFixtures();
        Sanctum::actingAs($this->actingUser);

        $unidad = UnitOfMeasure::create(['code' => 'PZA', 'name' => 'Pieza', 'abbreviation' => 'pza']);
        $unidad->enterprises()->attach($this->enterprise->id);
        $this->insumo = Product::create([
            'code' => 'PROD-CAJA',
            'name' => 'Caja de cartón',
            'unit_id' => $unidad->id,
            'product_type' => 'consumable',
            'track_inventory' => true,
        ]);
        $this->insumo->enterprises()->attach($this->enterprise->id);

        // 1 insumo por caja, con costo de receta distinto al real de las capas.
        $this->receta = Recipe::create([
            'enterprise_id' => $this->enterprise->id,
            'code' => 'RCP-EMP-01',
            'name' => 'Caja mango',
            'output_quantity' => 1,
        ]);
        $this->receta->items()->create([
            'enterprise_id' => $this->enterprise->id,
            'product_id' => $this->insumo->id,
            'quantity' => 1,
            'cost_per_unit' => 7,
        ]);

        InventoryStock::updateStock($this->insumo->id, $this->entity->id, null, 10, 100);
        InventoryStock::updateStock($this->insumo->id, $this->entity->id, null, 10, 150);

        $this->procesoId = $this->crearRecepcionConProduccion($this->productorPrincipal, 'X')['proceso']->id;
    }

    private function crearProduccion(int $cajas): int
    {
        $response = $this->postJson(self::URL, [
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $this->procesoId,
            'recipe_id' => $this->receta->id,
            'numero_pallet' => 'P-PEPS',
            'total_cajas' => $cajas,
            'peso_neto_kg' => $cajas * 10,
            'clasificacion' => 'convencional',
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function fila(): InventoryStock
    {
        return InventoryStock::where('product_id', $this->insumo->id)->where('entity_id', $this->entity->id)->firstOrFail();
    }

    public function test_crear_y_borrar_una_produccion_devuelve_el_valor_exacto_del_inventario(): void
    {
        $id = $this->crearProduccion(12);

        // 12 salen de la capa más vieja (10 a 100) y 2 de la siguiente (a 150).
        $this->assertEqualsWithDelta(8, (float) $this->fila()->quantity, 0.0001);
        $this->assertEqualsWithDelta(1200, (float) $this->fila()->total_cost, 0.0001);

        $this->deleteJson(self::URL."/{$id}")->assertOk();

        $this->assertEqualsWithDelta(20, (float) $this->fila()->quantity, 0.0001);
        $this->assertEqualsWithDelta(2500, (float) $this->fila()->total_cost, 0.0001);
    }

    public function test_editar_las_cajas_revierte_las_porciones_y_vuelve_a_consumir(): void
    {
        $id = $this->crearProduccion(12);

        $this->putJson(self::URL."/{$id}", ['total_cajas' => 5])->assertOk();

        // Se devolvieron las 12 originales y se consumieron 5 de la capa a 100.
        $this->assertEqualsWithDelta(15, (float) $this->fila()->quantity, 0.0001);
        $this->assertEqualsWithDelta(2000, (float) $this->fila()->total_cost, 0.0001);

        $this->deleteJson(self::URL."/{$id}")->assertOk();

        $this->assertEqualsWithDelta(20, (float) $this->fila()->quantity, 0.0001);
        $this->assertEqualsWithDelta(2500, (float) $this->fila()->total_cost, 0.0001);
    }
}
