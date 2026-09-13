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

    /**
     * Final-review C1: tree() no filtraba por empresa en absoluto — devolvía
     * TODO el catálogo global de categorías raíz activas sin importar el
     * header X-Enterprise-Slug.
     */
    public function test_tree_solo_devuelve_categorias_raiz_de_la_empresa_del_header(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $splendidFarms = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $sfCategory = ProductCategory::create(['code' => 'CAT-SF', 'name' => 'Empaque', 'is_active' => true]);
        $sfCategory->enterprises()->attach($splendidFarms->id);

        $caCategory = ProductCategory::create(['code' => 'CAT-CA', 'name' => 'Fertilizantes', 'is_active' => true]);
        $caCategory->enterprises()->attach($canesAgro->id);

        $response = $this->getJson('/api/splendidfarms/inventario/catalogos/categorias/tree', [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Empaque'));
        $this->assertFalse($names->contains('Fertilizantes'));
    }

    /**
     * Final-review C2: show/update/destroy no verificaban que la categoría
     * resuelta por route-model-binding perteneciera a la empresa del header
     * — una vulnerabilidad de escritura cross-tenant.
     */
    public function test_show_devuelve_404_para_categoria_de_otra_empresa(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $caCategory = ProductCategory::create(['code' => 'CAT-CA', 'name' => 'Fertilizantes', 'is_active' => true]);
        $caCategory->enterprises()->attach($canesAgro->id);

        $response = $this->getJson("/api/splendidfarms/inventario/catalogos/categorias/{$caCategory->id}", [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertStatus(404);
    }

    public function test_update_devuelve_404_para_categoria_de_otra_empresa(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $caCategory = ProductCategory::create(['code' => 'CAT-CA', 'name' => 'Fertilizantes', 'is_active' => true]);
        $caCategory->enterprises()->attach($canesAgro->id);

        $response = $this->putJson("/api/splendidfarms/inventario/catalogos/categorias/{$caCategory->id}", [
            'name' => 'Nombre Hackeado',
        ], ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('product_categories', ['id' => $caCategory->id, 'name' => 'Fertilizantes']);
    }

    public function test_destroy_devuelve_404_para_categoria_de_otra_empresa(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $caCategory = ProductCategory::create(['code' => 'CAT-CA', 'name' => 'Fertilizantes', 'is_active' => true]);
        $caCategory->enterprises()->attach($canesAgro->id);

        $response = $this->deleteJson("/api/splendidfarms/inventario/catalogos/categorias/{$caCategory->id}", [], [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('product_categories', ['id' => $caCategory->id, 'deleted_at' => null]);
    }
}
