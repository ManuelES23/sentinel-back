<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class MigracionFestivosPermisosTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    private function migrar(): void
    {
        (require database_path('migrations/2026_10_17_100100_add_rh_festivos_submodule.php'))->up();
    }

    private function conAcceso(User $usuario): void
    {
        UserSubmoduleAccess::create([
            'user_id' => $usuario->id,
            'submodule_id' => $this->submoduloVacaciones()->id,
            'is_active' => true,
        ]);
    }

    private function concedido(User $usuario, Submodule $festivos, string $slug): bool
    {
        $tipo = SubmodulePermissionType::where('submodule_id', $festivos->id)->where('slug', $slug)->firstOrFail();

        return (bool) UserSubmodulePermission::where('user_id', $usuario->id)->where('permission_type_id', $tipo->id)->value('is_granted');
    }

    public function test_crea_el_submodulo_y_sus_cuatro_permisos(): void
    {
        $this->submoduloVacaciones();

        $this->migrar();
        $this->migrar();

        $festivos = Submodule::where('slug', 'festivos')->sole();
        $this->assertSame(['create', 'delete', 'edit', 'view'], SubmodulePermissionType::where('submodule_id', $festivos->id)->orderBy('slug')->pluck('slug')->all());
    }

    public function test_quien_administra_vacaciones_administra_festivos_y_los_demas_no(): void
    {
        $admin = User::factory()->create();
        $soloLee = User::factory()->create();
        foreach ([$admin, $soloLee] as $usuario) {
            $this->conAcceso($usuario);
        }
        $this->concederVacaciones($admin, 'view', 'edit');
        $this->concederVacaciones($soloLee, 'view');

        $this->migrar();
        $this->migrar();

        $festivos = Submodule::where('slug', 'festivos')->sole();
        foreach (['view', 'create', 'edit', 'delete'] as $slug) {
            $this->assertTrue($this->concedido($admin, $festivos, $slug), "admin {$slug}");
            $this->assertFalse($this->concedido($soloLee, $festivos, $slug), "soloLee {$slug}");
        }
        $this->assertTrue(UserSubmoduleAccess::where('user_id', $admin->id)->where('submodule_id', $festivos->id)->where('is_active', true)->exists());
        $this->assertFalse(UserSubmoduleAccess::where('user_id', $soloLee->id)->where('submodule_id', $festivos->id)->exists());
    }

    public function test_sin_modulo_de_gestion_no_hace_nada(): void
    {
        $this->migrar();

        $this->assertSame(0, Submodule::where('slug', 'festivos')->count());
    }
}
