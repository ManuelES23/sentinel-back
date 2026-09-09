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
}
