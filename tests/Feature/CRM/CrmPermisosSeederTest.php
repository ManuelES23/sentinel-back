<?php
// sentinel-back/tests/Feature/CRM/CrmPermisosSeederTest.php

namespace Tests\Feature\CRM;

use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmPermisosSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_el_modulo_cotizaciones_con_sus_permisos(): void
    {
        $enterprise = Enterprise::create([
            'name' => 'Empresa Seeder Test',
            'slug' => 'empresa-seeder-test',
            'description' => 'Prueba de seeder',
            'is_active' => true,
        ]);

        (new CrmPermisosSeeder())->run();

        $app = \App\Models\Application::where('enterprise_id', $enterprise->id)->where('slug', 'crm')->first();
        $this->assertNotNull($app);

        $modulo = Module::where('application_id', $app->id)->where('slug', 'cotizaciones')->first();
        $this->assertNotNull($modulo);

        $submodulo = Submodule::where('module_id', $modulo->id)->where('slug', 'cotizaciones')->first();
        $this->assertNotNull($submodulo);

        $slugs = SubmodulePermissionType::where('submodule_id', $submodulo->id)->pluck('slug')->sort()->values()->all();
        $this->assertSame(['aprobar', 'crear', 'editar', 'rechazar', 'ver'], $slugs);
    }

    public function test_agrega_configuracion_comercial_dentro_de_catalogos(): void
    {
        $enterprise = Enterprise::create([
            'name' => 'Empresa Seeder Test 2',
            'slug' => 'empresa-seeder-test-2',
            'description' => 'Prueba de seeder',
            'is_active' => true,
        ]);

        (new CrmPermisosSeeder())->run();

        $app = \App\Models\Application::where('enterprise_id', $enterprise->id)->where('slug', 'crm')->first();
        $catalogos = Module::where('application_id', $app->id)->where('slug', 'catalogos')->first();
        $submodulo = Submodule::where('module_id', $catalogos->id)->where('slug', 'configuracion-comercial')->first();

        $this->assertNotNull($submodulo);
        $slugs = SubmodulePermissionType::where('submodule_id', $submodulo->id)->pluck('slug')->sort()->values()->all();
        $this->assertSame(['editar', 'ver'], $slugs);
    }
}
