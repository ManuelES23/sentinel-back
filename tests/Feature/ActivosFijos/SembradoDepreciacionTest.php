<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\Enterprise;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserSubmodulePermission;
use Database\Seeders\FixedAssetsModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SembradoDepreciacionTest extends TestCase
{
    use RefreshDatabase;

    private function sembrar(): void
    {
        foreach (['splendidfarms', 'splendidbyporvenir', 'grupoesplendido'] as $slug) {
            Enterprise::firstOrCreate(['slug' => $slug], ['name' => $slug, 'description' => $slug, 'is_active' => true]);
        }
        $this->artisan('db:seed', ['--class' => FixedAssetsModuleSeeder::class])->assertSuccessful();
    }

    public function test_crea_el_submodulo_depreciacion_con_sus_permisos_en_las_tres_empresas(): void
    {
        $this->sembrar();

        $submodulos = Submodule::where('slug', 'depreciacion')->get();
        $this->assertCount(3, $submodulos);
        foreach ($submodulos as $submodulo) {
            $this->assertTrue((bool) $submodulo->is_active);
            $this->assertSame('Depreciación', $submodulo->name);
            $this->assertEqualsCanonicalizing(
                ['view', 'create', 'edit', 'delete'],
                SubmodulePermissionType::where('submodule_id', $submodulo->id)->pluck('slug')->all(),
            );
        }
    }

    public function test_es_idempotente_y_no_concede_nada_a_los_usuarios_base(): void
    {
        $admin = User::factory()->create(['email' => 'admin@sentinel.com', 'role' => 'admin']);
        $demo = User::factory()->create(['email' => 'demo@sentinel.com', 'role' => 'user']);

        $this->sembrar();
        $this->sembrar();

        $this->assertSame(3, Submodule::where('slug', 'depreciacion')->count());
        $submodulo = Submodule::where('slug', 'depreciacion')->first();

        foreach ([$admin, $demo] as $usuario) {
            $filas = UserSubmodulePermission::where('user_id', $usuario->id)->where('submodule_id', $submodulo->id)->get();
            $this->assertCount(4, $filas);
            $this->assertTrue($filas->every(fn ($fila) => $fila->is_granted === false));
        }
    }
}
