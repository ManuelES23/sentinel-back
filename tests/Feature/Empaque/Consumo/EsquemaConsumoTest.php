<?php

namespace Tests\Feature\Empaque\Consumo;

use App\Models\Cultivo;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueDetalle;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Temporada;
use App\Support\Empaque\MarcadoConsumoRecetas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class EsquemaConsumoTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures;

    private function renglon(Recipe $receta, string $nombre, int $orden): RecipeItem
    {
        $producto = Product::create([
            'code' => 'PROD-T' . $orden . '-' . $receta->id,
            'name' => $nombre,
            'unit_id' => $this->unidad->id,
            'product_type' => 'consumable',
            'track_inventory' => true,
        ]);

        return RecipeItem::create([
            'enterprise_id' => $this->empresa->id,
            'recipe_id' => $receta->id,
            'product_id' => $producto->id,
            'quantity' => 1,
            'sort_order' => $orden,
        ]);
    }

    private function receta(string $codigo, string $tipo): Recipe
    {
        return Recipe::create([
            'enterprise_id' => $this->empresa->id,
            'code' => $codigo,
            'name' => "Receta $codigo",
            'recipe_type' => $tipo,
        ]);
    }

    public function test_marcado_inicial_de_consumo_por_nombre(): void
    {
        $this->setUpAlmacenFixtures();
        $empaque = $this->receta('REC-EMP', 'empaque');
        $nombres = ['Tarima', 'Esquineros', 'Fleje', 'Grapa', 'Interlock', 'Caja de carton 4.5kg', 'Etiqueta termica 4x2'];
        $items = [];
        foreach ($nombres as $i => $n) {
            $items[$n] = $this->renglon($empaque, $n, $i);
        }
        $agricola = $this->receta('REC-AGR', 'agricola');
        $tarimaAgricola = $this->renglon($agricola, 'Tarima', 1);

        $this->assertSame(RecipeItem::CONSUMO_POR_CAJA, $items['Tarima']->fresh()->consumo);

        $marcados = MarcadoConsumoRecetas::aplicar();

        $this->assertSame(5, $marcados);
        $this->assertSame(RecipeItem::CONSUMO_AL_ARMAR, $items['Tarima']->fresh()->consumo);
        foreach (['Esquineros', 'Fleje', 'Grapa', 'Interlock'] as $n) {
            $this->assertSame(RecipeItem::CONSUMO_AL_CERRAR, $items[$n]->fresh()->consumo, $n);
        }
        $this->assertSame(RecipeItem::CONSUMO_POR_CAJA, $items['Caja de carton 4.5kg']->fresh()->consumo);
        $this->assertSame(RecipeItem::CONSUMO_POR_CAJA, $items['Etiqueta termica 4x2']->fresh()->consumo);
        $this->assertSame(RecipeItem::CONSUMO_POR_CAJA, $tarimaAgricola->fresh()->consumo);
    }

    public function test_negativo_desde_se_marca_y_se_limpia(): void
    {
        $this->setUpAlmacenFixtures();
        $p = $this->insumo->id;
        $a = $this->almacenA->id;
        InventoryStock::updateStock($p, $a, null, 5, 10);

        InventoryStock::updateStock($p, $a, null, -8, 10, null, null, null, true);
        $fila = InventoryStock::where('product_id', $p)->where('entity_id', $a)->first();
        $this->assertEqualsWithDelta(-3, (float) $fila->quantity, 0.0001);
        $this->assertNotNull($fila->negativo_desde);

        $desde = $fila->negativo_desde;
        $this->travel(5)->minutes();
        InventoryStock::updateStock($p, $a, null, -5, 10, null, null, null, true);
        $fila = $fila->fresh();
        $this->assertEqualsWithDelta(-8, (float) $fila->quantity, 0.0001);
        $this->assertEquals($desde, $fila->negativo_desde);

        InventoryStock::updateStock($p, $a, null, 10, 10);
        $fila = $fila->fresh();
        $this->assertEqualsWithDelta(2, (float) $fila->quantity, 0.0001);
        $this->assertNull($fila->negativo_desde);
    }

    public function test_congelado_sin_renglones(): void
    {
        $this->setUpAlmacenFixtures();
        $user = $this->crearAdmin();
        $cultivo = Cultivo::create(['nombre' => 'Mango']);
        $temporada = Temporada::create([
            'cultivo_id' => $cultivo->id, 'nombre' => 'Mango 2026', 'locacion' => 'Sinaloa',
            'folio_temporada' => $cultivo->id . '-001', 'año_inicio' => 2026, 'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-06-30', 'user_id' => $user->id,
        ]);
        $produccion = ProduccionEmpaque::create([
            'temporada_id' => $temporada->id, 'entity_id' => $this->almacenA->id,
            'folio_produccion' => 'PDN-1', 'fecha_produccion' => '2026-02-02', 'total_cajas' => 10,
        ]);

        $this->assertTrue($produccion->congelado());

        $detalle = ProduccionEmpaqueDetalle::create([
            'produccion_id' => $produccion->id, 'numero_entrada' => 1, 'fecha_produccion' => '2026-02-02', 'total_cajas' => 10,
        ]);
        $consumo = $produccion->consumos()->create([
            'detalle_id' => $detalle->id, 'entity_id' => $this->almacenA->id, 'product_id' => $this->insumo->id,
            'tipo' => 'por_caja', 'cajas' => 10, 'cantidad' => 10, 'costo_total' => 100, 'cantidad_devuelta' => 4,
        ]);

        $this->assertFalse($produccion->fresh()->congelado());
        $this->assertEqualsWithDelta(6, $consumo->vigente(), 0.0001);
        $this->assertSame("empaque_consumo:{$consumo->id}", $consumo->referencia());
        $this->assertCount(1, $detalle->consumos);
        $this->assertSame(1, $produccion->consumos()->vigentes()->count());
    }
}
