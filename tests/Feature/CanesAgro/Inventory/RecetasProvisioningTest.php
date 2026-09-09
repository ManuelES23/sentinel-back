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
}
