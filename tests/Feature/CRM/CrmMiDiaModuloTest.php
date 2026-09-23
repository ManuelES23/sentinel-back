<?php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserModuleAccess;
use App\Models\UserSubmodulePermission;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmMiDiaModuloTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION = 'database/migrations/2026_09_13_100000_add_crm_mi_dia_module.php';

    private function crearEmpresaConCrm(): Application
    {
        $enterprise = Enterprise::create([
            'name' => 'Empresa Mi Día',
            'slug' => 'empresa-mi-dia-'.uniqid(),
            'description' => 'Prueba módulo Mi día',
            'is_active' => true,
        ]);

        (new CrmPermisosSeeder())->run();

        return Application::where('enterprise_id', $enterprise->id)->where('slug', 'crm')->firstOrFail();
    }

    private function moduloMiDia(Application $app): ?Module
    {
        return Module::where('application_id', $app->id)->where('slug', 'mi-dia')->first();
    }

    public function test_el_seeder_crea_mi_dia_primero_con_sus_permisos(): void
    {
        $app = $this->crearEmpresaConCrm();

        $modulo = $this->moduloMiDia($app);
        $this->assertNotNull($modulo);
        $this->assertSame(0, (int) $modulo->order);
        $this->assertSame('Sun', $modulo->icon);

        $sub = Submodule::where('module_id', $modulo->id)->where('slug', 'mi-dia')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['ver', 'equipo'],
            SubmodulePermissionType::where('submodule_id', $sub->id)->pluck('slug')->all(),
        );
    }

    public function test_la_migracion_crea_mi_dia_en_empresas_existentes_es_idempotente_y_reversible(): void
    {
        $app = $this->crearEmpresaConCrm();
        $this->moduloMiDia($app)->delete(); // simula una empresa sembrada antes de la fase 2

        $migracion = require base_path(self::MIGRACION);
        $migracion->up();
        $migracion->up();

        $this->assertSame(1, Module::where('application_id', $app->id)->where('slug', 'mi-dia')->count());
        $modulo = $this->moduloMiDia($app);
        $sub = Submodule::where('module_id', $modulo->id)->firstOrFail();
        $this->assertSame(2, SubmodulePermissionType::where('submodule_id', $sub->id)->count());

        $usuario = User::factory()->create();
        UserModuleAccess::create(['user_id' => $usuario->id, 'module_id' => $modulo->id, 'is_active' => true]);
        UserSubmodulePermission::create([
            'user_id' => $usuario->id,
            'submodule_id' => $sub->id,
            'permission_type_id' => SubmodulePermissionType::where('submodule_id', $sub->id)->value('id'),
            'is_granted' => true,
        ]);

        $migracion->down();

        $this->assertNull($this->moduloMiDia($app));
        $this->assertSame(0, UserSubmodulePermission::where('user_id', $usuario->id)->count());
        $this->assertSame(0, UserModuleAccess::where('user_id', $usuario->id)->count());
    }

    public function test_la_migracion_no_crea_mi_dia_en_aplicaciones_que_no_son_crm(): void
    {
        $enterprise = Enterprise::create([
            'name' => 'Sin CRM', 'slug' => 'sin-crm-'.uniqid(), 'description' => 'x', 'is_active' => true,
        ]);
        $otraApp = Application::create([
            'enterprise_id' => $enterprise->id, 'slug' => 'inventario', 'name' => 'Inventario',
            'description' => 'Otra aplicación', 'path' => '/inventario', 'is_active' => true,
        ]);

        (require base_path(self::MIGRACION))->up();

        $this->assertSame(0, Module::where('application_id', $otraApp->id)->count());
    }
}
