<?php

namespace Tests\Feature\CRM;

use App\Models\Enterprise;
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

    // ── Quien ya ve al equipo en otra pantalla (dashboard.ejecutivo, mi-dia.equipo) ──

    /** Tipo de permiso `$slug` del submódulo `$modulo/$submodulo` del CRM de la empresa. */
    private function tipoEn(Enterprise $empresa, string $modulo, string $submodulo, string $slug): SubmodulePermissionType
    {
        return SubmodulePermissionType::where('slug', $slug)
            ->whereHas('submodule', fn ($q) => $q->where('slug', $submodulo)
                ->whereHas('module', fn ($m) => $m->where('slug', $modulo)
                    ->whereHas('application', fn ($a) => $a->where('slug', 'crm')->where('enterprise_id', $empresa->id))))
            ->firstOrFail();
    }

    private function concederEn(Enterprise $empresa, User $user, string $modulo, string $submodulo, string $slug, bool $otorgado = true): void
    {
        $tipo = $this->tipoEn($empresa, $modulo, $submodulo, $slug);
        UserSubmodulePermission::create([
            'user_id' => $user->id,
            'submodule_id' => $tipo->submodule_id,
            'permission_type_id' => $tipo->id,
            'is_granted' => $otorgado,
        ]);
    }

    private function tieneEquipoEn(Enterprise $empresa, User $user): bool
    {
        return UserSubmodulePermission::where('user_id', $user->id)
            ->where('permission_type_id', $this->tipoEn($empresa, 'oportunidades', 'oportunidades', 'equipo')->id)
            ->where('is_granted', true)
            ->exists();
    }

    /** CRM sembrado en dos empresas y `equipo` borrado, como antes del despliegue. */
    private function prepararDosEmpresas(): Enterprise
    {
        $this->setUpCrmFixtures();
        $otra = $this->crearOtraEmpresa();
        (new CrmPermisosSeeder)->run();
        $this->tipoEn($this->enterprise, 'oportunidades', 'oportunidades', 'equipo')->delete();
        $this->tipoEn($otra, 'oportunidades', 'oportunidades', 'equipo')->delete();

        return $otra;
    }

    private function bajarMigracion(): void
    {
        (require database_path('migrations/2026_10_09_100000_add_crm_oportunidades_equipo_permission.php'))->down();
    }

    public function test_concede_equipo_a_quien_tiene_dashboard_ejecutivo(): void
    {
        $this->prepararDosEmpresas();
        $ejecutivo = User::factory()->create();
        $soloDashboard = User::factory()->create();
        $this->concederEn($this->enterprise, $ejecutivo, 'dashboard', 'dashboard', 'ejecutivo');
        $this->concederEn($this->enterprise, $soloDashboard, 'dashboard', 'dashboard', 'ver');

        $this->ejecutarMigracion();

        $this->assertTrue($this->tieneEquipoEn($this->enterprise, $ejecutivo));
        $this->assertFalse($this->tieneEquipoEn($this->enterprise, $soloDashboard));
    }

    public function test_concede_equipo_a_quien_tiene_mi_dia_equipo(): void
    {
        $this->prepararDosEmpresas();
        $gerente = User::factory()->create();
        $soloMiDia = User::factory()->create();
        $this->concederEn($this->enterprise, $gerente, 'mi-dia', 'mi-dia', 'equipo');
        $this->concederEn($this->enterprise, $soloMiDia, 'mi-dia', 'mi-dia', 'ver');

        $this->ejecutarMigracion();

        $this->assertTrue($this->tieneEquipoEn($this->enterprise, $gerente));
        $this->assertFalse($this->tieneEquipoEn($this->enterprise, $soloMiDia));
    }

    public function test_un_ejecutivo_o_mi_dia_equipo_revocado_no_cuenta(): void
    {
        $this->prepararDosEmpresas();
        $revocado = User::factory()->create();
        $this->concederEn($this->enterprise, $revocado, 'dashboard', 'dashboard', 'ejecutivo', otorgado: false);
        $this->concederEn($this->enterprise, $revocado, 'mi-dia', 'mi-dia', 'equipo', otorgado: false);

        $this->ejecutarMigracion();

        $this->assertFalse($this->tieneEquipoEn($this->enterprise, $revocado));
    }

    public function test_no_reactiva_un_equipo_revocado_aunque_tenga_cerrar_o_ejecutivo(): void
    {
        $this->setUpCrmFixtures();
        (new CrmPermisosSeeder)->run();
        $usuario = User::factory()->create();
        $this->concederEn($this->enterprise, $usuario, 'oportunidades', 'oportunidades', 'cerrar');
        $this->concederEn($this->enterprise, $usuario, 'dashboard', 'dashboard', 'ejecutivo');
        $this->concederEn($this->enterprise, $usuario, 'oportunidades', 'oportunidades', 'equipo', otorgado: false);

        $this->ejecutarMigracion();

        $this->assertFalse($this->tieneEquipoEn($this->enterprise, $usuario));
        $this->assertSame(1, UserSubmodulePermission::where('user_id', $usuario->id)
            ->where('permission_type_id', $this->tipoEn($this->enterprise, 'oportunidades', 'oportunidades', 'equipo')->id)
            ->count());
    }

    public function test_cada_empresa_se_resuelve_por_separado(): void
    {
        $otra = $this->prepararDosEmpresas();
        $soloEnA = User::factory()->create();
        $soloEnB = User::factory()->create();
        $this->concederEn($this->enterprise, $soloEnA, 'dashboard', 'dashboard', 'ejecutivo');
        $this->concederEn($otra, $soloEnB, 'oportunidades', 'oportunidades', 'cerrar');

        $this->ejecutarMigracion();
        $this->ejecutarMigracion();

        $this->assertTrue($this->tieneEquipoEn($this->enterprise, $soloEnA));
        $this->assertFalse($this->tieneEquipoEn($otra, $soloEnA));
        $this->assertTrue($this->tieneEquipoEn($otra, $soloEnB));
        $this->assertFalse($this->tieneEquipoEn($this->enterprise, $soloEnB));
        // Un solo tipo `equipo` por empresa, aunque se corra dos veces.
        $this->assertSame(2, SubmodulePermissionType::where('slug', 'equipo')
            ->whereHas('submodule', fn ($q) => $q->where('slug', 'oportunidades'))->count());
        $this->assertSame(1, UserSubmodulePermission::where('user_id', $soloEnA->id)
            ->where('permission_type_id', $this->tipoEn($this->enterprise, 'oportunidades', 'oportunidades', 'equipo')->id)
            ->count());
    }

    public function test_down_quita_el_permiso_equipo_y_sus_concesiones_sin_tocar_lo_demas(): void
    {
        $otra = $this->prepararDosEmpresas();
        $gerente = User::factory()->create();
        $this->concederEn($this->enterprise, $gerente, 'oportunidades', 'oportunidades', 'cerrar');
        $this->concederEn($otra, $gerente, 'mi-dia', 'mi-dia', 'equipo');
        $this->ejecutarMigracion();
        $this->assertTrue($this->tieneEquipoEn($this->enterprise, $gerente));
        $this->assertTrue($this->tieneEquipoEn($otra, $gerente));

        $this->bajarMigracion();

        $this->assertSame(0, SubmodulePermissionType::where('slug', 'equipo')
            ->whereHas('submodule', fn ($q) => $q->where('slug', 'oportunidades'))->count());
        // mi-dia.equipo es otro tipo `equipo` y sigue intacto, igual que cerrar.
        $this->assertSame(2, SubmodulePermissionType::where('slug', 'equipo')
            ->whereHas('submodule', fn ($q) => $q->where('slug', 'mi-dia'))->count());
        $this->assertSame(2, UserSubmodulePermission::where('user_id', $gerente->id)->count());
    }

    public function test_sin_estructura_crm_no_hace_nada(): void
    {
        $this->ejecutarMigracion();
        $this->bajarMigracion();

        $this->assertSame(0, SubmodulePermissionType::count());
        $this->assertSame(0, UserSubmodulePermission::count());
    }
}
