<?php
// tests/Feature/SplendidFarms/Inventory/BrandEnterpriseScopeTest.php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Brand;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrandEnterpriseScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_solo_devuelve_marcas_de_la_empresa_del_header(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $splendidFarms = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $sfBrand = Brand::create(['code' => 'MRC-SF', 'name' => 'CajaMax']);
        $sfBrand->enterprises()->attach($splendidFarms->id);

        $caBrand = Brand::create(['code' => 'MRC-CA', 'name' => 'AgroQuim']);
        $caBrand->enterprises()->attach($canesAgro->id);

        $response = $this->getJson('/api/splendidfarms/inventario/catalogos/marcas', [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('CajaMax'));
        $this->assertFalse($names->contains('AgroQuim'));
    }

    public function test_store_vincula_la_marca_nueva_a_la_empresa_del_header(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/marcas', [
            'name' => 'Nutrisol',
        ], ['X-Enterprise-Slug' => 'canes-agro']);

        $response->assertCreated();
        $this->assertDatabaseHas('enterprise_brand', [
            'enterprise_id' => $canesAgro->id,
            'brand_id' => $response->json('data.id'),
        ]);
    }

    /**
     * Final-review C1: list() (usado por el selector de marcas del frontend)
     * no filtraba por empresa en absoluto.
     */
    public function test_list_solo_devuelve_marcas_activas_de_la_empresa_del_header(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $splendidFarms = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $sfBrand = Brand::create(['code' => 'MRC-SF', 'name' => 'CajaMax']);
        $sfBrand->enterprises()->attach($splendidFarms->id);

        $caBrand = Brand::create(['code' => 'MRC-CA', 'name' => 'AgroQuim']);
        $caBrand->enterprises()->attach($canesAgro->id);

        $response = $this->getJson('/api/splendidfarms/inventario/catalogos/marcas/list', [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('CajaMax'));
        $this->assertFalse($names->contains('AgroQuim'));
    }

    /**
     * Final-review C2: show/update/destroy no verificaban que la marca
     * resuelta por route-model-binding perteneciera a la empresa del header.
     */
    public function test_show_devuelve_404_para_marca_de_otra_empresa(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $caBrand = Brand::create(['code' => 'MRC-CA', 'name' => 'AgroQuim']);
        $caBrand->enterprises()->attach($canesAgro->id);

        $response = $this->getJson("/api/splendidfarms/inventario/catalogos/marcas/{$caBrand->id}", [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertStatus(404);
    }

    public function test_update_devuelve_404_para_marca_de_otra_empresa(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $caBrand = Brand::create(['code' => 'MRC-CA', 'name' => 'AgroQuim']);
        $caBrand->enterprises()->attach($canesAgro->id);

        $response = $this->putJson("/api/splendidfarms/inventario/catalogos/marcas/{$caBrand->id}", [
            'name' => 'Nombre Hackeado',
        ], ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('brands', ['id' => $caBrand->id, 'name' => 'AgroQuim']);
    }

    public function test_destroy_devuelve_404_para_marca_de_otra_empresa(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $caBrand = Brand::create(['code' => 'MRC-CA', 'name' => 'AgroQuim']);
        $caBrand->enterprises()->attach($canesAgro->id);

        $response = $this->deleteJson("/api/splendidfarms/inventario/catalogos/marcas/{$caBrand->id}", [], [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('brands', ['id' => $caBrand->id, 'deleted_at' => null]);
    }
}
