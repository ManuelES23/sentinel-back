<?php
// sentinel-back/tests/Feature/ActivosFijos/SembradoAsignacionesTest.php

namespace Tests\Feature\ActivosFijos;

use App\Models\Enterprise;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserSubmodulePermission;
use Database\Seeders\FixedAssetsModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SembradoAsignacionesTest extends TestCase
{
    use RefreshDatabase;

    private function sembrar(): void
    {
        $this->artisan('db:seed', ['--class' => FixedAssetsModuleSeeder::class])->assertSuccessful();
    }

    public function test_crea_el_submodulo_asignaciones_con_sus_permisos_en_las_tres_empresas(): void
    {
        foreach (['splendidfarms', 'splendidbyporvenir', 'grupoesplendido'] as $slug) {
            Enterprise::create(['name' => $slug, 'slug' => $slug, 'description' => $slug, 'is_active' => true]);
        }

        $this->sembrar();

        $submodulos = Submodule::where('slug', 'asignaciones')->get();
        $this->assertCount(3, $submodulos);
        foreach ($submodulos as $submodulo) {
            $this->assertTrue((bool) $submodulo->is_active);
            $this->assertSame('Asignaciones', $submodulo->name);
            $this->assertEqualsCanonicalizing(
                ['view', 'create', 'edit', 'delete'],
                SubmodulePermissionType::where('submodule_id', $submodulo->id)->pluck('slug')->all(),
            );
        }
    }

    public function test_es_idempotente_y_crea_las_filas_de_permisos_de_los_usuarios_base_sin_concederlas(): void
    {
        foreach (['splendidfarms', 'splendidbyporvenir', 'grupoesplendido'] as $slug) {
            Enterprise::create(['name' => $slug, 'slug' => $slug, 'description' => $slug, 'is_active' => true]);
        }
        $admin = User::factory()->create(['email' => 'admin@sentinel.com', 'role' => 'admin']);
        $demo = User::factory()->create(['email' => 'demo@sentinel.com', 'role' => 'user']);

        $this->sembrar();
        $this->sembrar();

        $this->assertSame(3, Submodule::where('slug', 'asignaciones')->count());
        $submodulo = Submodule::where('slug', 'asignaciones')->first();

        foreach ([$admin, $demo] as $usuario) {
            $filas = UserSubmodulePermission::where('user_id', $usuario->id)->where('submodule_id', $submodulo->id)->get();
            $this->assertCount(4, $filas);
            // El seeder usa firstOrCreate sin is_granted: la columna toma su valor por defecto (false).
            // Las filas existen para que el menú muestre la entrada, pero no conceden nada; admin@ opera por su rol.
            $this->assertTrue($filas->every(fn ($fila) => $fila->is_granted === false));
        }
    }
}
