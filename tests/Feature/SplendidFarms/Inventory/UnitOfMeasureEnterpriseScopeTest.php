<?php
// tests/Feature/SplendidFarms/Inventory/UnitOfMeasureEnterpriseScopeTest.php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Enterprise;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitOfMeasureEnterpriseScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_solo_devuelve_unidades_de_la_empresa_del_header(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $splendidFarms = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $sfUnit = UnitOfMeasure::create(['code' => 'PZA', 'name' => 'Pieza', 'abbreviation' => 'pza', 'type' => 'unit']);
        $sfUnit->enterprises()->attach($splendidFarms->id);

        $caUnit = UnitOfMeasure::create(['code' => 'LT', 'name' => 'Litro', 'abbreviation' => 'lt', 'type' => 'volume']);
        $caUnit->enterprises()->attach($canesAgro->id);

        $response = $this->getJson('/api/splendidfarms/inventario/catalogos/unidades', [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Pieza'));
        $this->assertFalse($names->contains('Litro'));
    }

    /**
     * Final-review C2: show/update/destroy no verificaban que la unidad
     * resuelta por route-model-binding perteneciera a la empresa del header.
     */
    public function test_show_devuelve_404_para_unidad_de_otra_empresa(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $caUnit = UnitOfMeasure::create(['code' => 'LT', 'name' => 'Litro', 'abbreviation' => 'lt', 'type' => 'volume']);
        $caUnit->enterprises()->attach($canesAgro->id);

        $response = $this->getJson("/api/splendidfarms/inventario/catalogos/unidades/{$caUnit->id}", [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertStatus(404);
    }

    public function test_update_devuelve_404_para_unidad_de_otra_empresa(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $caUnit = UnitOfMeasure::create(['code' => 'LT', 'name' => 'Litro', 'abbreviation' => 'lt', 'type' => 'volume']);
        $caUnit->enterprises()->attach($canesAgro->id);

        $response = $this->putJson("/api/splendidfarms/inventario/catalogos/unidades/{$caUnit->id}", [
            'name' => 'Nombre Hackeado',
        ], ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('units_of_measure', ['id' => $caUnit->id, 'name' => 'Litro']);
    }

    public function test_destroy_devuelve_404_para_unidad_de_otra_empresa(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'Test enterprise']);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Test enterprise']);

        $caUnit = UnitOfMeasure::create(['code' => 'LT', 'name' => 'Litro', 'abbreviation' => 'lt', 'type' => 'volume']);
        $caUnit->enterprises()->attach($canesAgro->id);

        $response = $this->deleteJson("/api/splendidfarms/inventario/catalogos/unidades/{$caUnit->id}", [], [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('units_of_measure', ['id' => $caUnit->id, 'deleted_at' => null]);
    }
}
