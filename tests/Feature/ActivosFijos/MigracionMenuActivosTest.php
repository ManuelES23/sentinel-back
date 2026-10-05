<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserModuleAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MigracionMenuActivosTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION = 'database/migrations/2026_10_05_100100_mover_activos_fijos_a_administracion.php';

    public function test_reubica_submodulos_conserva_permisos_y_crea_administracion_en_ge(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRACION]);

        $usuario = User::factory()->create(['role' => 'user']);
        $sf = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'SF', 'is_active' => true]);
        $ge = Enterprise::create(['name' => 'Grupo Espléndido', 'slug' => 'grupoesplendido', 'description' => 'GE', 'is_active' => true]);
        Application::create(['enterprise_id' => $sf->id, 'slug' => 'administration', 'name' => 'Administración', 'path' => '/administration', 'description' => 'a']);

        $viejos = [];
        foreach ([$sf, $ge] as $empresa) {
            $inv = Application::create(['enterprise_id' => $empresa->id, 'slug' => 'inventario', 'name' => 'Inventario', 'path' => '/inventario', 'description' => 'i']);
            $mod = Module::create(['application_id' => $inv->id, 'slug' => 'activos-fijos', 'name' => 'Activos Fijos']);
            $activos = Submodule::create(['module_id' => $mod->id, 'slug' => 'activos', 'name' => 'Activos']);
            $tipos = Submodule::create(['module_id' => $mod->id, 'slug' => 'tipos-activo', 'name' => 'Tipos']);
            $ver = SubmodulePermissionType::create(['submodule_id' => $activos->id, 'slug' => 'view', 'name' => 'Ver', 'is_active' => true]);
            UserSubmodulePermission::create(['user_id' => $usuario->id, 'submodule_id' => $activos->id, 'permission_type_id' => $ver->id, 'is_granted' => true]);
            UserModuleAccess::create(['user_id' => $usuario->id, 'module_id' => $mod->id, 'is_active' => true, 'granted_at' => now()]);
            $viejos[$empresa->slug] = compact('mod', 'activos', 'tipos');
        }

        Artisan::call('migrate', ['--path' => self::MIGRACION]);

        foreach ([$sf, $ge] as $empresa) {
            $admin = Application::where('enterprise_id', $empresa->id)->where('slug', 'administration')->first();
            $this->assertNotNull($admin, "Administración en {$empresa->slug}");
            $modulo = Module::where('application_id', $admin->id)->where('slug', 'activos-fijos')->first();
            $this->assertNotNull($modulo);

            $activos = $viejos[$empresa->slug]['activos']->fresh();
            $this->assertSame($modulo->id, $activos->module_id, 'se re-parenta, no se recrea');
            $this->assertDatabaseHas('user_submodule_permissions', ['user_id' => $usuario->id, 'submodule_id' => $activos->id]);
            $this->assertDatabaseHas('user_module_access', ['user_id' => $usuario->id, 'module_id' => $modulo->id, 'is_active' => true]);
            $this->assertDatabaseHas('user_application_access', ['user_id' => $usuario->id, 'application_id' => $admin->id]);
            $this->assertDatabaseMissing('modules', ['id' => $viejos[$empresa->slug]['mod']->id]);
        }

        $this->assertFalse((bool) $viejos['splendidfarms']['tipos']->fresh()->is_active);
        $this->assertTrue((bool) $viejos['grupoesplendido']['tipos']->fresh()->is_active);
    }
}
