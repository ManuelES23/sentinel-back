<?php

namespace Tests\Feature\CRM;

use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserSubmodulePermission;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Migración que agrega oportunidades.equipo a las empresas ya sembradas y se
 * lo concede a quien ya tenía oportunidades.cerrar (la gerencia actual).
 */
class OportunidadEquipoMigracionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private function tipo(string $slug): SubmodulePermissionType
    {
        return SubmodulePermissionType::where('slug', $slug)
            ->whereHas('submodule', fn ($q) => $q->where('slug', 'oportunidades'))
            ->firstOrFail();
    }

    private function conceder(User $user, string $slug): void
    {
        $tipo = $this->tipo($slug);
        UserSubmodulePermission::create([
            'user_id' => $user->id,
            'submodule_id' => $tipo->submodule_id,
            'permission_type_id' => $tipo->id,
            'is_granted' => true,
        ]);
    }

    private function ejecutarMigracion(): void
    {
        (require database_path('migrations/2026_10_09_100000_add_crm_oportunidades_equipo_permission.php'))->up();
    }

    private function tieneEquipo(User $user): bool
    {
        return UserSubmodulePermission::where('user_id', $user->id)
            ->where('permission_type_id', $this->tipo('equipo')->id)
            ->where('is_granted', true)
            ->exists();
    }

    public function test_crea_el_permiso_y_se_lo_concede_a_quien_tenia_cerrar(): void
    {
        $this->setUpCrmFixtures();
        (new CrmPermisosSeeder)->run();
        $gerente = User::factory()->create();
        $vendedor = User::factory()->create();
        $this->conceder($gerente, 'cerrar');
        $this->conceder($vendedor, 'ver');
        // Estado previo al despliegue: el permiso `equipo` aún no existe.
        $this->tipo('equipo')->delete();

        $this->ejecutarMigracion();

        $this->assertTrue($this->tieneEquipo($gerente));
        $this->assertFalse($this->tieneEquipo($vendedor));
    }

    public function test_es_idempotente_y_no_reactiva_un_cerrar_revocado(): void
    {
        $this->setUpCrmFixtures();
        (new CrmPermisosSeeder)->run();
        $revocado = User::factory()->create();
        $this->conceder($revocado, 'cerrar');
        UserSubmodulePermission::where('user_id', $revocado->id)->update(['is_granted' => false]);

        $this->ejecutarMigracion();
        $this->ejecutarMigracion();

        $this->assertFalse($this->tieneEquipo($revocado));
        $this->assertSame(1, SubmodulePermissionType::where('slug', 'equipo')
            ->whereHas('submodule', fn ($q) => $q->where('slug', 'oportunidades'))->count());
    }
}
