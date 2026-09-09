<?php
// tests/Feature/CanesAgro/Inventory/RecetasProvisioningTest.php

namespace Tests\Feature\CanesAgro\Inventory;

use Database\Seeders\CanesAgroInventoryModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecetasProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_crea_el_arbol_de_inventario_para_canes_agro(): void
    {
        $enterprise = \App\Models\Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'description' => 'Alimentos para canes', 'is_active' => true]);

        (new CanesAgroInventoryModuleSeeder())->run();

        $this->assertDatabaseHas('applications', ['enterprise_id' => $enterprise->id, 'slug' => 'inventario']);

        $app = \App\Models\Application::where('enterprise_id', $enterprise->id)->where('slug', 'inventario')->first();
        $catalogos = \App\Models\Module::where('application_id', $app->id)->where('slug', 'catalogos')->first();
        $this->assertNotNull($catalogos);

        $submoduleSlugs = \App\Models\Submodule::where('module_id', $catalogos->id)->pluck('slug');
        $this->assertEqualsCanonicalizing(['articulos', 'categorias', 'marcas', 'recetas'], $submoduleSlugs->toArray());
    }

    public function test_seeder_es_idempotente(): void
    {
        \App\Models\Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'description' => 'Alimentos para canes', 'is_active' => true]);

        (new CanesAgroInventoryModuleSeeder())->run();
        (new CanesAgroInventoryModuleSeeder())->run();

        $this->assertDatabaseCount('applications', 1);
    }

    public function test_flujo_completo_receta_de_canes_agro_no_es_visible_para_splendid_farms(): void
    {
        \App\Models\Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'Splendid Farms', 'is_active' => true]);
        \App\Models\Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'description' => 'Alimentos para canes', 'is_active' => true]);

        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\User::factory()->create());

        $unit = \App\Models\UnitOfMeasure::create(['code' => 'LT', 'name' => 'Litro', 'abbreviation' => 'lt', 'type' => 'volume']);
        $category = \App\Models\ProductCategory::create(['code' => 'CAT-FERT', 'name' => 'Fertilizantes', 'is_active' => true]);
        $ingredient = \App\Models\Product::create([
            'code' => 'PROD-N', 'name' => 'Nitrógeno líquido', 'category_id' => $category->id,
            'unit_id' => $unit->id, 'cost_price' => 12,
        ]);

        $response = $this->postJson('/api/canes-agro/inventario/catalogos/recetas', [
            'name' => 'Mezcla Foliar NPK 20-20-20',
            'output_quantity' => 200,
            'output_unit_id' => $unit->id,
            'items' => [['product_id' => $ingredient->id, 'quantity' => 50]],
        ], ['X-Enterprise-Slug' => 'canes-agro']);

        $response->assertOk();
        $this->assertNull($response->json('data.agro_details'));

        $sfList = $this->getJson('/api/splendidfarms/inventario/catalogos/recetas', ['X-Enterprise-Slug' => 'splendidfarms']);
        $this->assertFalse(collect($sfList->json('data'))->pluck('name')->contains('Mezcla Foliar NPK 20-20-20'));
    }
}
