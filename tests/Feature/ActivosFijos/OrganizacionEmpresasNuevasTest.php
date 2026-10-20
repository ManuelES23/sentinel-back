<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\Application;
use App\Models\Branch;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use Database\Seeders\OrganizacionEmpresasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\TestCase;

/**
 * Organización (sucursales, tipos de entidades, entidades y áreas) y rutas de
 * Administración para Canes Agro y Splendid Logistic: el seeder la deja lista
 * en las empresas activas que no la tienen (salvo el corporativo) y las rutas
 * de /api/{empresa}/administration existen para ambas.
 */
class OrganizacionEmpresasNuevasTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;

    private const SUBMODULOS = ['sucursales', 'tipos-entidades', 'entidades', 'areas'];

    private Enterprise $canes;

    private Enterprise $logistic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();

        $this->canes = $this->crearEmpresaActivos('canes-agro', 'Canes Agro', 'CA');
        $this->logistic = $this->crearEmpresaActivos('splendid-logistic', 'Splendid Logistic', 'SL');
    }

    private function sembrar(): void
    {
        $this->artisan('db:seed', ['--class' => OrganizacionEmpresasSeeder::class])->assertSuccessful();
    }

    private function moduloOrganizacion(Enterprise $empresa): ?Module
    {
        $app = Application::where('slug', 'administration')->where('enterprise_id', $empresa->id)->first();

        return $app ? Module::where('application_id', $app->id)->where('slug', 'organizacion')->first() : null;
    }

    /** SF y SBP ya tienen el módulo en producción: se simula como lo dejó MasterStructureSeeder. */
    private function sembrarOrganizacionPrevia(Enterprise $empresa, int $order): Module
    {
        $app = Application::where('slug', 'administration')->where('enterprise_id', $empresa->id)->first();
        $modulo = Module::create([
            'application_id' => $app->id, 'slug' => 'organizacion', 'name' => 'Organización',
            'icon' => 'Building', 'order' => $order, 'is_active' => true,
        ]);
        foreach (self::SUBMODULOS as $i => $slug) {
            Submodule::create(['module_id' => $modulo->id, 'slug' => $slug, 'name' => $slug, 'icon' => 'Building2', 'order' => $i + 1, 'is_active' => true]);
        }

        return $modulo;
    }

    private function conteos(): array
    {
        return [
            'apps' => Application::count(),
            'modulos' => Module::count(),
            'submodulos' => Submodule::count(),
            'tipos_permiso' => SubmodulePermissionType::count(),
            'acceso_app' => UserApplicationAccess::count(),
            'acceso_modulo' => UserModuleAccess::count(),
            'acceso_submodulo' => UserSubmoduleAccess::count(),
            'permisos_usuario' => UserSubmodulePermission::count(),
        ];
    }

    // ---------------------------------------------------------------- Sembrado

    public function test_canes_agro_y_logistic_reciben_el_modulo_con_sus_cuatro_submodulos_y_permisos(): void
    {
        $this->sembrar();

        foreach ([$this->canes, $this->logistic] as $empresa) {
            $modulo = $this->moduloOrganizacion($empresa);
            $this->assertNotNull($modulo, "{$empresa->slug} no recibió Organización");
            $this->assertSame('Organización', $modulo->name);
            $this->assertSame('Building', $modulo->icon);
            $this->assertTrue($modulo->is_active);

            $submodulos = Submodule::where('module_id', $modulo->id)->orderBy('order')->get();
            $this->assertSame(self::SUBMODULOS, $submodulos->pluck('slug')->all(), $empresa->slug);
            $this->assertSame(['Sucursales', 'Tipos de Entidades', 'Entidades', 'Áreas'], $submodulos->pluck('name')->all());
            $this->assertSame(['Building2', 'FileType', 'Landmark', 'LayoutGrid'], $submodulos->pluck('icon')->all());
            $this->assertSame([1, 2, 3, 4], $submodulos->pluck('order')->all());

            foreach ($submodulos as $submodulo) {
                $this->assertTrue($submodulo->is_active);
                $this->assertEqualsCanonicalizing(
                    ['view', 'create', 'edit', 'delete'],
                    SubmodulePermissionType::where('submodule_id', $submodulo->id)->pluck('slug')->all(),
                    "{$empresa->slug}/{$submodulo->slug}",
                );
            }
        }
    }

    public function test_el_modulo_toma_el_siguiente_orden_libre_de_la_app(): void
    {
        // La app de la empresa ya tiene Activos Fijos (fixture); el de Organización va después.
        $app = Application::where('slug', 'administration')->where('enterprise_id', $this->canes->id)->first();
        Module::where('application_id', $app->id)->update(['order' => 5]);

        $this->sembrar();

        $this->assertSame(6, $this->moduloOrganizacion($this->canes)->order);
    }

    public function test_el_corporativo_no_recibe_organizacion(): void
    {
        $this->sembrar();

        $this->assertNull($this->moduloOrganizacion($this->corporativo));
        $this->assertSame(0, Submodule::whereIn('slug', self::SUBMODULOS)
            ->whereHas('module.application', fn ($q) => $q->where('enterprise_id', $this->corporativo->id))->count());
    }

    public function test_si_la_empresa_no_tenia_app_administracion_se_crea(): void
    {
        $nueva = Enterprise::create(['name' => 'Nueva', 'slug' => 'nueva-co', 'description' => 'Nueva', 'is_active' => true]);

        $this->sembrar();

        $app = Application::where('slug', 'administration')->where('enterprise_id', $nueva->id)->first();
        $this->assertNotNull($app);
        $this->assertSame('Administración', $app->name);
        $this->assertSame('Building2', $app->icon);
        $this->assertSame('/nueva-co/administration', $app->path);
        $this->assertNotNull($this->moduloOrganizacion($nueva));
    }

    public function test_una_empresa_inactiva_no_recibe_nada(): void
    {
        $inactiva = Enterprise::create(['name' => 'Inactiva', 'slug' => 'inactiva-co', 'description' => 'x', 'is_active' => false]);

        $this->sembrar();

        $this->assertNull(Application::where('enterprise_id', $inactiva->id)->first());
        $this->assertNull($this->moduloOrganizacion($inactiva));
    }

    public function test_las_empresas_que_ya_la_tienen_no_cambian(): void
    {
        $this->sembrarOrganizacionPrevia($this->enterprise, 7);
        $porvenir = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $this->sembrarOrganizacionPrevia($porvenir, 1);

        $this->sembrar();

        foreach ([$this->enterprise, $porvenir] as $empresa) {
            $app = Application::where('slug', 'administration')->where('enterprise_id', $empresa->id)->first();
            $this->assertSame(1, Module::where('application_id', $app->id)->where('slug', 'organizacion')->count());
        }
        $this->assertSame(7, $this->moduloOrganizacion($this->enterprise)->order);
        $this->assertSame(1, $this->moduloOrganizacion($porvenir)->order);
        // Sus submódulos no ganaron tipos de permiso ni se duplicaron.
        $this->assertSame(0, SubmodulePermissionType::whereIn('submodule_id', Submodule::where('module_id', $this->moduloOrganizacion($this->enterprise)->id)->pluck('id'))->count());
        $this->assertSame(4, Submodule::where('module_id', $this->moduloOrganizacion($this->enterprise)->id)->count());
        $this->assertSame(4, Submodule::where('module_id', $this->moduloOrganizacion($porvenir)->id)->count());
    }

    public function test_correrlo_dos_veces_no_duplica_nada(): void
    {
        User::factory()->create(['email' => 'admin@sentinel.com', 'role' => 'admin']);
        $this->sembrar();
        $primera = $this->conteos();

        $this->sembrar();

        $this->assertSame($primera, $this->conteos());
        $this->assertSame(1, Module::where('slug', 'organizacion')->where('application_id', Application::where('slug', 'administration')->where('enterprise_id', $this->canes->id)->value('id'))->count());
    }

    public function test_da_acceso_sin_conceder_solo_a_admin_y_demo_en_las_empresas_nuevas(): void
    {
        $admin = User::factory()->create(['email' => 'admin@sentinel.com', 'role' => 'admin']);
        $demo = User::factory()->create(['email' => 'demo@sentinel.com', 'role' => 'user']);
        $real = User::factory()->create(['email' => 'real@empresa.com', 'role' => 'user']);
        // SF ya la tiene: no se le agrega acceso a nadie.
        $moduloSf = $this->sembrarOrganizacionPrevia($this->enterprise, 2);

        $this->sembrar();

        foreach ([$this->canes, $this->logistic] as $empresa) {
            $modulo = $this->moduloOrganizacion($empresa);
            $submodulos = Submodule::where('module_id', $modulo->id)->pluck('id');

            foreach ([$admin, $demo] as $usuario) {
                $this->assertTrue(UserApplicationAccess::where('user_id', $usuario->id)->where('application_id', $modulo->application_id)->exists());
                $this->assertTrue(UserModuleAccess::where('user_id', $usuario->id)->where('module_id', $modulo->id)->exists());
                $this->assertSame(4, UserSubmoduleAccess::where('user_id', $usuario->id)->whereIn('submodule_id', $submodulos)->count());
                $permisos = UserSubmodulePermission::where('user_id', $usuario->id)->whereIn('submodule_id', $submodulos)->get();
                $this->assertCount(16, $permisos);
                $this->assertSame(0, $permisos->where('is_granted', true)->count(), 'No se concede nada automáticamente');
            }

            $this->assertFalse(UserModuleAccess::where('user_id', $real->id)->where('module_id', $modulo->id)->exists());
        }

        $this->assertSame(0, UserModuleAccess::where('module_id', $moduloSf->id)->count());
        $this->assertSame(0, UserModuleAccess::where('module_id', $this->moduloOrganizacion($this->corporativo)?->id ?? 0)->count());
    }

    // ------------------------------------------------------------------ Rutas

    private function headersDe(Enterprise $empresa): array
    {
        return ['X-Enterprise-Slug' => $empresa->slug];
    }

    private function usuarioDe(Enterprise $empresa, array $permisosActivos = ['view', 'create']): User
    {
        $usuario = User::factory()->create(['role' => 'user']);
        $this->otorgarActivos($usuario, $empresa, 'activos', $permisosActivos);

        return $usuario;
    }

    public function test_canes_agro_lista_solo_sus_sucursales(): void
    {
        $this->crearUbicacion($this->canes, 'CA');
        $this->crearUbicacion($this->enterprise, 'SFX'); // otra empresa
        Sanctum::actingAs($this->usuarioDe($this->canes));

        $respuesta = $this->getJson('/api/canes-agro/administration/organizacion/sucursales', $this->headersDe($this->canes))
            ->assertOk();

        $this->assertSame(['SUC-CA'], collect($respuesta->json('data'))->pluck('code')->all());
    }

    public function test_splendid_logistic_lista_solo_sus_sucursales(): void
    {
        $this->crearUbicacion($this->logistic, 'SL');
        $this->crearUbicacion($this->canes, 'CA');
        Sanctum::actingAs($this->usuarioDe($this->logistic));

        $respuesta = $this->getJson('/api/splendid-logistic/administration/organizacion/sucursales', $this->headersDe($this->logistic))
            ->assertOk();

        $this->assertSame(['SUC-SL'], collect($respuesta->json('data'))->pluck('code')->all());
    }

    public function test_crear_una_sucursal_queda_en_la_empresa_del_header(): void
    {
        foreach ([[$this->canes, 'canes-agro', 'Rancho Canes'], [$this->logistic, 'splendid-logistic', 'Patio Logistic']] as [$empresa, $slug, $nombre]) {
            Sanctum::actingAs($this->usuarioDe($empresa));

            $id = $this->postJson("/api/{$slug}/administration/organizacion/sucursales", ['name' => $nombre], $this->headersDe($empresa))
                ->assertCreated()->json('data.id');

            $this->assertSame($empresa->id, Branch::find($id)->enterprise_id);
        }
    }

    public function test_tipos_entidades_entidades_y_areas_responden_en_las_empresas_nuevas(): void
    {
        foreach (['canes-agro' => $this->canes, 'splendid-logistic' => $this->logistic] as $slug => $empresa) {
            Sanctum::actingAs($this->usuarioDe($empresa));

            foreach (['tipos-entidades', 'entidades', 'areas'] as $recurso) {
                $this->getJson("/api/{$slug}/administration/organizacion/{$recurso}", $this->headersDe($empresa))
                    ->assertOk();
            }
        }
    }

    public function test_activos_fijos_responde_en_canes_agro_y_logistic(): void
    {
        foreach (['canes-agro' => $this->canes, 'splendid-logistic' => $this->logistic] as $slug => $empresa) {
            Sanctum::actingAs($this->usuarioDe($empresa));

            $this->getJson("/api/{$slug}/administration/activos-fijos/mis-permisos")
                ->assertOk()
                ->assertJsonPath('data.activos.view', true)
                ->assertJsonPath('data.corporativo', false);

            $this->getJson("/api/{$slug}/administration/activos-fijos/activos")->assertOk();
            $this->getJson("/api/{$slug}/administration/activos-fijos/catalogos")->assertOk();
        }
    }

    public function test_sin_acceso_a_la_empresa_activos_fijos_responde_403(): void
    {
        // Tiene acceso a Canes Agro, no a Splendid Logistic.
        Sanctum::actingAs($this->usuarioDe($this->canes));

        $this->getJson('/api/splendid-logistic/administration/activos-fijos/mis-permisos')->assertForbidden();
    }

    public function test_sin_sesion_las_rutas_nuevas_responden_401(): void
    {
        $this->getJson('/api/canes-agro/administration/organizacion/sucursales', $this->headersDe($this->canes))->assertUnauthorized();
        $this->getJson('/api/splendid-logistic/administration/activos-fijos/mis-permisos')->assertUnauthorized();
    }

    public function test_el_corporativo_conserva_activos_fijos_y_sigue_sin_organizacion(): void
    {
        Sanctum::actingAs($this->actingUser);

        $this->getJson('/api/grupoesplendido/administration/activos-fijos/mis-permisos')->assertOk();
        $this->getJson('/api/grupoesplendido/administration/organizacion/sucursales', $this->headersDe($this->corporativo))->assertNotFound();
    }

    public function test_las_rutas_de_organizacion_de_sf_y_sbp_siguen_respondiendo(): void
    {
        $porvenir = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $this->crearUbicacion($porvenir, 'SBP');
        Sanctum::actingAs($this->actingUser);

        $sf = $this->getJson('/api/splendidfarms/administration/organizacion/sucursales', $this->headersDe($this->enterprise))->assertOk();
        $this->assertSame(['SUC-SF'], collect($sf->json('data'))->pluck('code')->all());

        $sbp = $this->getJson('/api/splendidbyporvenir/administration/organizacion/sucursales', $this->headersDe($porvenir))->assertOk();
        $this->assertSame(['SUC-SBP'], collect($sbp->json('data'))->pluck('code')->all());

        foreach (['splendidfarms', 'splendidbyporvenir'] as $slug) {
            $this->getJson("/api/{$slug}/administration/organizacion/areas", $this->headersDe($slug === 'splendidfarms' ? $this->enterprise : $porvenir))->assertOk();
        }
    }
}
