<?php

namespace Tests\Feature\Compras;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigracionComprasAdministracionTest extends TestCase
{
    use RefreshDatabase;

    private function correrMigracion(): void
    {
        (require database_path('migrations/2026_09_22_100000_mover_compras_a_administracion.php'))->up();
    }

    private function app(Enterprise $empresa, string $slug): Application
    {
        return Application::firstOrCreate(
            ['enterprise_id' => $empresa->id, 'slug' => $slug],
            ['name' => $slug, 'path' => "/{$slug}", 'description' => $slug],
        );
    }

    private function submodulo(Application $app, string $modulo, string $sub): Submodule
    {
        $m = Module::firstOrCreate(['application_id' => $app->id, 'slug' => $modulo], ['name' => $modulo]);

        return Submodule::firstOrCreate(['module_id' => $m->id, 'slug' => $sub], ['name' => $sub]);
    }

    public function test_migra_permiso_y_acceso_de_quien_cotiza(): void
    {
        $empresa = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'Splendid Farms', 'is_active' => true]);
        $admin = $this->app($empresa, 'administration');
        $oa = $this->app($empresa, 'operacion-agricola');
        $viejo = $this->submodulo($oa, 'agricola', 'requisiciones');
        $tipoViejo = SubmodulePermissionType::create(['submodule_id' => $viejo->id, 'slug' => 'cotizar', 'name' => 'Cotizar', 'is_active' => true]);

        $user = User::factory()->create();
        UserSubmodulePermission::create(['user_id' => $user->id, 'submodule_id' => $viejo->id, 'permission_type_id' => $tipoViejo->id, 'is_granted' => true]);

        $this->correrMigracion();

        $nuevo = Submodule::whereHas('module', fn ($m) => $m->where('slug', 'compras')->where('application_id', $admin->id))
            ->where('slug', 'requisiciones')->firstOrFail();

        foreach (['cotizar', 'view'] as $slug) {
            $tipo = SubmodulePermissionType::where('submodule_id', $nuevo->id)->where('slug', $slug)->firstOrFail();
            $this->assertDatabaseHas('user_submodule_permissions', [
                'user_id' => $user->id, 'submodule_id' => $nuevo->id, 'permission_type_id' => $tipo->id, 'is_granted' => true,
            ]);
        }

        $this->assertDatabaseHas('user_application_access', ['user_id' => $user->id, 'application_id' => $admin->id, 'is_active' => true]);
        $this->assertDatabaseHas('user_submodule_access', ['user_id' => $user->id, 'submodule_id' => $nuevo->id, 'is_active' => true]);
        $this->assertSame(0, SubmodulePermissionType::where('submodule_id', $viejo->id)->where('slug', 'cotizar')->count());
    }

    public function test_reparenta_submodulos_y_conserva_el_acceso_al_modulo(): void
    {
        $empresa = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'Splendid Farms', 'is_active' => true]);
        $admin = $this->app($empresa, 'administration');
        $inv = $this->app($empresa, 'inventario');
        $ordenes = $this->submodulo($inv, 'compras', 'ordenes-compra');
        $recepciones = $this->submodulo($inv, 'compras', 'recepciones');
        $moduloViejo = Module::find($ordenes->module_id);

        $user = User::factory()->create();
        UserModuleAccess::create(['user_id' => $user->id, 'module_id' => $moduloViejo->id, 'is_active' => true]);
        UserSubmoduleAccess::create(['user_id' => $user->id, 'submodule_id' => $ordenes->id, 'is_active' => true]);

        $this->correrMigracion();

        $nuevo = Module::where('application_id', $admin->id)->where('slug', 'compras')->firstOrFail();
        $this->assertSame($nuevo->id, $ordenes->fresh()->module_id);
        $this->assertSame($nuevo->id, $recepciones->fresh()->module_id);
        $this->assertFalse((bool) $moduloViejo->fresh()->is_active);

        $this->assertDatabaseHas('user_module_access', ['user_id' => $user->id, 'module_id' => $nuevo->id, 'is_active' => true]);
        $this->assertDatabaseHas('user_application_access', ['user_id' => $user->id, 'application_id' => $admin->id]);
        $this->assertDatabaseHas('user_submodule_access', ['user_id' => $user->id, 'submodule_id' => $ordenes->id, 'is_active' => true]);
    }

    public function test_renombra_compras_agricolas_y_no_toca_otras_empresas(): void
    {
        $empresa = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'Splendid Farms', 'is_active' => true]);
        $otra = Enterprise::create(['name' => 'Splendid by Porvenir', 'slug' => 'splendidbyporvenir', 'description' => 'Splendid by Porvenir', 'is_active' => true]);
        $admin = $this->app($empresa, 'administration');
        Module::create(['application_id' => $admin->id, 'slug' => 'compras-agricolas', 'name' => 'Compras Agrícolas', 'is_active' => true]);
        $invOtra = $this->app($otra, 'inventario');
        $ordenesOtra = $this->submodulo($invOtra, 'compras', 'ordenes-compra');
        $moduloOtra = Module::find($ordenesOtra->module_id);

        $this->correrMigracion();

        $this->assertDatabaseHas('modules', ['application_id' => $admin->id, 'slug' => 'abastecimiento', 'name' => 'Abastecimiento']);
        $this->assertDatabaseMissing('modules', ['application_id' => $admin->id, 'slug' => 'compras-agricolas']);
        $this->assertSame($moduloOtra->id, $ordenesOtra->fresh()->module_id);
        $this->assertTrue((bool) $moduloOtra->fresh()->is_active);
    }

    public function test_es_idempotente(): void
    {
        $empresa = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'Splendid Farms', 'is_active' => true]);
        $admin = $this->app($empresa, 'administration');
        $inv = $this->app($empresa, 'inventario');
        $this->submodulo($inv, 'compras', 'ordenes-compra');

        $this->correrMigracion();
        $this->correrMigracion();

        $this->assertSame(1, Module::where('application_id', $admin->id)->where('slug', 'compras')->count());
        $this->assertSame(1, Submodule::whereHas('module', fn ($m) => $m->where('application_id', $admin->id)->where('slug', 'compras'))
            ->where('slug', 'ordenes-compra')->count());
    }
}
