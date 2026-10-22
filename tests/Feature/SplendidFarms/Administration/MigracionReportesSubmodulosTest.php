<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigracionReportesSubmodulosTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION = 'database/migrations/2026_10_22_100000_ensure_reportes_empaque_productores_submodules.php';

    public function test_crea_empaque_y_completa_permisos_de_productores_sin_duplicar(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRACION]);

        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);
        $otro = User::factory()->create();
        $sf = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'SF', 'is_active' => true]);
        $app = Application::create(['enterprise_id' => $sf->id, 'slug' => 'administration', 'name' => 'Administración', 'path' => '/administration', 'description' => 'a']);
        $reportes = Module::create(['application_id' => $app->id, 'slug' => 'reportes', 'name' => 'Reportes']);
        // Como lo deja MasterStructureSeeder: el submódulo existe, sin tipos de permiso.
        $productores = Submodule::create(['module_id' => $reportes->id, 'slug' => 'productores', 'name' => 'Productores']);

        Artisan::call('migrate', ['--path' => self::MIGRACION]);
        // Idempotente: correrla otra vez no duplica nada.
        Artisan::call('migrate:rollback', ['--path' => self::MIGRACION]);
        Artisan::call('migrate', ['--path' => self::MIGRACION]);

        $empaque = Submodule::where('module_id', $reportes->id)->where('slug', 'empaque')->first();
        $this->assertNotNull($empaque);
        $this->assertSame(1, Submodule::where('module_id', $reportes->id)->where('slug', 'productores')->count());

        foreach ([$empaque->id, $productores->id] as $submoduleId) {
            $this->assertEqualsCanonicalizing(
                ['view', 'create', 'edit', 'delete'],
                DB::table('submodule_permission_types')->where('submodule_id', $submoduleId)->pluck('slug')->all(),
            );
            $this->assertDatabaseHas('user_submodule_access', ['user_id' => $admin->id, 'submodule_id' => $submoduleId, 'is_active' => true]);
            $this->assertSame(4, DB::table('user_submodule_permissions')
                ->where('user_id', $admin->id)->where('submodule_id', $submoduleId)->where('is_granted', true)->count());
            $this->assertDatabaseMissing('user_submodule_access', ['user_id' => $otro->id, 'submodule_id' => $submoduleId]);
        }
    }
}
