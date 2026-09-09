<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Enterprise;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRecipeFixtures;
use Tests\TestCase;

class RecipeEnterpriseScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRecipeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecipeFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_index_solo_devuelve_recetas_de_la_empresa_del_header(): void
    {
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(['name' => 'Caja Elote Premium 20lb']),
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(['name' => 'Mezcla Foliar NPK 20-20-20']),
            ['X-Enterprise-Slug' => 'canes-agro']);

        $response = $this->getJson('/api/splendidfarms/inventario/catalogos/recetas', [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Caja Elote Premium 20lb'));
        $this->assertFalse($names->contains('Mezcla Foliar NPK 20-20-20'));
    }

    public function test_recipe_items_hereda_enterprise_id_del_padre(): void
    {
        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload([
                'items' => [['product_id' => $this->productA->id, 'quantity' => 2]],
            ]),
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $recipe = Recipe::find($response->json('data.id'));
        $this->assertSame($this->enterprise->id, $recipe->enterprise_id);
        $this->assertSame($this->enterprise->id, $recipe->items->first()->enterprise_id);
    }
}
