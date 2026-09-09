<?php
// tests/Feature/SplendidFarms/Inventory/ProductCategoryEnterpriseScopeTest.php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Enterprise;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCategoryEnterpriseScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_solo_devuelve_categorias_de_la_empresa_del_header(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $splendidFarms = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $sfCategory = ProductCategory::create(['code' => 'CAT-SF', 'name' => 'Empaque', 'is_active' => true]);
        $sfCategory->enterprises()->attach($splendidFarms->id);

        $caCategory = ProductCategory::create(['code' => 'CAT-CA', 'name' => 'Fertilizantes', 'is_active' => true]);
        $caCategory->enterprises()->attach($canesAgro->id);

        $response = $this->getJson('/api/splendidfarms/inventario/catalogos/categorias', [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Empaque'));
        $this->assertFalse($names->contains('Fertilizantes'));
    }

    public function test_store_vincula_la_categoria_nueva_a_la_empresa_del_header(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/categorias', [
            'name' => 'Agroquímicos',
        ], ['X-Enterprise-Slug' => 'canes-agro']);

        $response->assertCreated();
        $categoryId = $response->json('data.id');

        $this->assertDatabaseHas('enterprise_product_category', [
            'enterprise_id' => $canesAgro->id,
            'product_category_id' => $categoryId,
        ]);
    }
}
