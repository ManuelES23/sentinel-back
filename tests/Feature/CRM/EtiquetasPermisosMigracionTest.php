<?php

namespace Tests\Feature\CRM;

use App\Models\Enterprise;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Migración que agrega catalogos.etiquetas-actividad y oportunidades.cobro a
 * los CRM ya sembrados (spec 2026-10-30 §9).
 */
class EtiquetasPermisosMigracionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MIGRACION = 'migrations/2026_10_30_100400_add_crm_etiquetas_y_cobro_permisos.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    private function subir(): void
    {
        (require database_path(self::MIGRACION))->up();
    }

    private function bajar(): void
    {
        (require database_path(self::MIGRACION))->down();
    }

    private function submoduloEn(Enterprise $empresa, string $modulo, string $slug): ?Submodule
    {
        return Submodule::where('slug', $slug)
            ->whereHas('module', fn ($m) => $m->where('slug', $modulo)
                ->whereHas('application', fn ($a) => $a->where('slug', 'crm')->where('enterprise_id', $empresa->id)))
            ->first();
    }

    private function tipoEn(Enterprise $empresa, string $modulo, string $submodulo, string $slug): ?SubmodulePermissionType
    {
        $sub = $this->submoduloEn($empresa, $modulo, $submodulo);

        return $sub ? SubmodulePermissionType::where('submodule_id', $sub->id)->where('slug', $slug)->first() : null;
    }

    /** Estado previo al despliegue: sin el submódulo ni el permiso nuevos. */
    private function sinNovedades(Enterprise ...$empresas): void
    {
        (new CrmPermisosSeeder)->run();
        foreach ($empresas as $empresa) {
            $sub = $this->submoduloEn($empresa, 'catalogos', 'etiquetas-actividad');
            SubmodulePermissionType::where('submodule_id', $sub->id)->delete();
            $sub->delete();
            $this->tipoEn($empresa, 'oportunidades', 'oportunidades', 'cobro')->delete();
        }
    }

    private function conceder(Enterprise $empresa, User $user, string $modulo, string $submodulo, string $slug, bool $otorgado = true): void
    {
        $tipo = $this->tipoEn($empresa, $modulo, $submodulo, $slug);
        UserSubmodulePermission::create([
            'user_id' => $user->id,
            'submodule_id' => $tipo->submodule_id,
            'permission_type_id' => $tipo->id,
            'is_granted' => $otorgado,
        ]);
    }

    private function tiene(Enterprise $empresa, User $user, string $modulo, string $submodulo, string $slug): bool
    {
        $tipo = $this->tipoEn($empresa, $modulo, $submodulo, $slug);

        return $tipo !== null && UserSubmodulePermission::where('user_id', $user->id)
            ->where('permission_type_id', $tipo->id)->where('is_granted', true)->exists();
    }

    public function test_crea_submodulo_y_cobro_en_cada_crm(): void
    {
        $otra = $this->crearOtraEmpresa();
        $this->sinNovedades($this->enterprise, $otra);
        $this->assertNull($this->submoduloEn($this->enterprise, 'catalogos', 'etiquetas-actividad'));

        $this->subir();

        foreach ([$this->enterprise, $otra] as $empresa) {
            $sub = $this->submoduloEn($empresa, 'catalogos', 'etiquetas-actividad');
            $this->assertNotNull($sub);
            $this->assertSame('Etiquetas de actividad', $sub->name);
            $this->assertSame('Tag', $sub->icon);
            $this->assertSame(9, (int) $sub->order);
            $this->assertTrue((bool) $sub->is_active);
            $this->assertSame(
                ['crear', 'editar', 'eliminar', 'ver'],
                SubmodulePermissionType::where('submodule_id', $sub->id)->pluck('slug')->sort()->values()->all(),
            );
            $this->assertSame(
                [1, 2, 3, 4],
                SubmodulePermissionType::where('submodule_id', $sub->id)->orderBy('order')->pluck('order')->map(fn ($o) => (int) $o)->all(),
            );

            $cobro = $this->tipoEn($empresa, 'oportunidades', 'oportunidades', 'cobro');
            $this->assertNotNull($cobro);
            $this->assertSame('Marcar cobro', $cobro->name);
            $this->assertSame(7, (int) $cobro->order);
            $this->assertTrue((bool) $cobro->is_active);
        }
    }

    public function test_concede_cobro_a_quien_tiene_cerrar(): void
    {
        $this->sinNovedades($this->enterprise);
        $gerente = User::factory()->create();
        $vendedor = User::factory()->create();
        $cerrarRevocado = User::factory()->create();
        $this->conceder($this->enterprise, $gerente, 'oportunidades', 'oportunidades', 'cerrar');
        $this->conceder($this->enterprise, $vendedor, 'oportunidades', 'oportunidades', 'ver');
        $this->conceder($this->enterprise, $cerrarRevocado, 'oportunidades', 'oportunidades', 'cerrar', otorgado: false);

        $this->subir();

        $this->assertTrue($this->tiene($this->enterprise, $gerente, 'oportunidades', 'oportunidades', 'cobro'));
        $this->assertFalse($this->tiene($this->enterprise, $vendedor, 'oportunidades', 'oportunidades', 'cobro'));
        $this->assertFalse($this->tiene($this->enterprise, $cerrarRevocado, 'oportunidades', 'oportunidades', 'cobro'));
    }

    public function test_concede_catalogo_a_quien_edita_configuracion_comercial(): void
    {
        $this->sinNovedades($this->enterprise);
        $editor = User::factory()->create();
        $soloVer = User::factory()->create();
        $this->conceder($this->enterprise, $editor, 'catalogos', 'configuracion-comercial', 'editar');
        $this->conceder($this->enterprise, $soloVer, 'catalogos', 'configuracion-comercial', 'ver');

        $this->subir();

        $sub = $this->submoduloEn($this->enterprise, 'catalogos', 'etiquetas-actividad');
        foreach (['ver', 'crear', 'editar', 'eliminar'] as $permiso) {
            $this->assertTrue($this->tiene($this->enterprise, $editor, 'catalogos', 'etiquetas-actividad', $permiso));
            $this->assertFalse($this->tiene($this->enterprise, $soloVer, 'catalogos', 'etiquetas-actividad', $permiso));
        }
        $this->assertTrue(UserSubmoduleAccess::where('user_id', $editor->id)->where('submodule_id', $sub->id)->where('is_active', true)->exists());
        $this->assertFalse(UserSubmoduleAccess::where('user_id', $soloVer->id)->where('submodule_id', $sub->id)->exists());
    }

    public function test_no_revive_concesion_revocada(): void
    {
        (new CrmPermisosSeeder)->run();
        $usuario = User::factory()->create();
        $this->conceder($this->enterprise, $usuario, 'oportunidades', 'oportunidades', 'cerrar');
        $this->conceder($this->enterprise, $usuario, 'oportunidades', 'oportunidades', 'cobro', otorgado: false);
        $this->conceder($this->enterprise, $usuario, 'catalogos', 'configuracion-comercial', 'editar');
        $this->conceder($this->enterprise, $usuario, 'catalogos', 'etiquetas-actividad', 'crear', otorgado: false);
        $sub = $this->submoduloEn($this->enterprise, 'catalogos', 'etiquetas-actividad');
        UserSubmoduleAccess::create([
            'user_id' => $usuario->id, 'submodule_id' => $sub->id, 'is_active' => false, 'granted_at' => now(),
        ]);

        $this->subir();

        $this->assertFalse($this->tiene($this->enterprise, $usuario, 'oportunidades', 'oportunidades', 'cobro'));
        $this->assertFalse($this->tiene($this->enterprise, $usuario, 'catalogos', 'etiquetas-actividad', 'crear'));
        $this->assertTrue($this->tiene($this->enterprise, $usuario, 'catalogos', 'etiquetas-actividad', 'ver'));
        $this->assertFalse((bool) UserSubmoduleAccess::where('user_id', $usuario->id)->where('submodule_id', $sub->id)->value('is_active'));
        $this->assertSame(1, UserSubmoduleAccess::where('user_id', $usuario->id)->where('submodule_id', $sub->id)->count());
    }

    public function test_es_idempotente(): void
    {
        $otra = $this->crearOtraEmpresa();
        $this->sinNovedades($this->enterprise, $otra);
        $gerente = User::factory()->create();
        $this->conceder($this->enterprise, $gerente, 'oportunidades', 'oportunidades', 'cerrar');
        $this->conceder($this->enterprise, $gerente, 'catalogos', 'configuracion-comercial', 'editar');

        $this->subir();
        $this->subir();

        $this->assertSame(2, Submodule::where('slug', 'etiquetas-actividad')->count());
        $this->assertSame(8, SubmodulePermissionType::whereHas('submodule', fn ($q) => $q->where('slug', 'etiquetas-actividad'))->count());
        $this->assertSame(2, SubmodulePermissionType::where('slug', 'cobro')->count());
        // 1 cerrar + 1 editar de configuración + 4 de etiquetas + 1 cobro = 7 filas.
        $this->assertSame(7, UserSubmodulePermission::where('user_id', $gerente->id)->count());
        $this->assertSame(1, UserSubmoduleAccess::where('user_id', $gerente->id)->count());
    }

    public function test_down_quita_lo_que_creo(): void
    {
        $this->sinNovedades($this->enterprise);
        $gerente = User::factory()->create();
        $this->conceder($this->enterprise, $gerente, 'oportunidades', 'oportunidades', 'cerrar');
        $this->conceder($this->enterprise, $gerente, 'catalogos', 'configuracion-comercial', 'editar');
        $this->subir();

        $this->bajar();

        $this->assertSame(0, Submodule::where('slug', 'etiquetas-actividad')->count());
        $this->assertSame(0, SubmodulePermissionType::where('slug', 'cobro')->count());
        $this->assertSame(0, UserSubmoduleAccess::where('user_id', $gerente->id)->count());
        // Solo quedan los dos permisos previos (cerrar y editar configuración).
        $this->assertSame(2, UserSubmodulePermission::where('user_id', $gerente->id)->count());
    }

    public function test_el_seeder_registra_submodulo_y_cobro_para_empresas_nuevas(): void
    {
        (new CrmPermisosSeeder)->run();

        $sub = $this->submoduloEn($this->enterprise, 'catalogos', 'etiquetas-actividad');
        $this->assertNotNull($sub);
        $this->assertSame('Etiquetas de actividad', $sub->name);
        $this->assertSame('Tag', $sub->icon);
        $this->assertSame(9, (int) $sub->order);
        $this->assertNotNull($this->tipoEn($this->enterprise, 'oportunidades', 'oportunidades', 'cobro'));
    }

    public function test_sin_estructura_crm_no_hace_nada(): void
    {
        $this->subir();
        $this->bajar();

        $this->assertSame(0, SubmodulePermissionType::count());
    }
}
