<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use Database\Seeders\FixedAssetsModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SembradoTodasLasEmpresasTest extends TestCase
{
    use RefreshDatabase;

    private const SUBMODULOS = ['activos', 'tipos-activo', 'asignaciones', 'depreciacion', 'salidas'];

    private function crearEmpresa(string $slug, bool $activa = true): Enterprise
    {
        return Enterprise::firstOrCreate(['slug' => $slug], ['name' => $slug, 'description' => $slug, 'is_active' => $activa]);
    }

    /** SF, SBP y GE más Splendid Logistic (con una app CRM previa) y Canes Agro. */
    private function crearEmpresas(): void
    {
        foreach (['splendidfarms', 'splendidbyporvenir', 'grupoesplendido', 'splendid-logistic', 'canes-agro'] as $slug) {
            $this->crearEmpresa($slug);
        }

        Application::create([
            'enterprise_id' => Enterprise::where('slug', 'splendid-logistic')->value('id'),
            'slug' => 'crm', 'name' => 'CRM', 'description' => 'CRM', 'path' => '/splendid-logistic/crm', 'is_active' => true,
        ]);
    }

    private function sembrar(): void
    {
        $this->artisan('db:seed', ['--class' => FixedAssetsModuleSeeder::class])->assertSuccessful();
    }

    private function conteos(): array
    {
        return [
            'apps' => Application::where('slug', 'administration')->count(),
            'modulos' => Module::where('slug', 'activos-fijos')->count(),
            'submodulos' => Submodule::whereIn('slug', self::SUBMODULOS)->count(),
            'permisos' => SubmodulePermissionType::count(),
        ];
    }

    public function test_cada_empresa_activa_recibe_la_app_el_modulo_y_los_cinco_submodulos(): void
    {
        $this->crearEmpresas();
        $this->sembrar();

        foreach (Enterprise::all() as $empresa) {
            $app = Application::where('slug', 'administration')->where('enterprise_id', $empresa->id)->first();
            $this->assertNotNull($app, "{$empresa->slug} no tiene la app administration");

            $modulo = Module::where('application_id', $app->id)->where('slug', 'activos-fijos')->first();
            $this->assertNotNull($modulo, "{$empresa->slug} no tiene el módulo activos-fijos");

            $submodulos = Submodule::where('module_id', $modulo->id)->get()->keyBy('slug');
            $this->assertEqualsCanonicalizing(self::SUBMODULOS, $submodulos->keys()->all(), $empresa->slug);

            foreach ($submodulos as $slug => $submodulo) {
                $this->assertSame($slug === 'tipos-activo' ? $empresa->slug === 'grupoesplendido' : true, (bool) $submodulo->is_active, "{$empresa->slug}/{$slug}");
                $this->assertEqualsCanonicalizing(
                    ['view', 'create', 'edit', 'delete'],
                    SubmodulePermissionType::where('submodule_id', $submodulo->id)->pluck('slug')->all(),
                );
            }
        }

        // La app CRM previa de Splendid Logistic sigue intacta y no se confunde con Administración.
        $logistic = Enterprise::where('slug', 'splendid-logistic')->first();
        $this->assertSame(1, Application::where('enterprise_id', $logistic->id)->where('slug', 'crm')->count());
        $this->assertSame(2, Application::where('enterprise_id', $logistic->id)->count());
    }

    public function test_una_empresa_inactiva_no_recibe_app_ni_modulo(): void
    {
        $this->crearEmpresas();
        $inactiva = $this->crearEmpresa('empresa-inactiva', false);

        $this->sembrar();

        $this->assertSame(0, Application::where('enterprise_id', $inactiva->id)->count());
        $this->assertSame(0, Module::whereIn('application_id', Application::where('enterprise_id', $inactiva->id)->pluck('id'))->count());
        $this->assertSame(5, Application::where('slug', 'administration')->count());
    }

    public function test_sembrar_dos_veces_no_duplica_nada(): void
    {
        $this->crearEmpresas();

        $this->sembrar();
        $antes = $this->conteos();
        $this->sembrar();

        $this->assertSame($antes, $this->conteos());
        $this->assertSame(
            ['apps' => 5, 'modulos' => 5, 'submodulos' => 25],
            array_intersect_key($antes, array_flip(['apps', 'modulos', 'submodulos'])),
        );
    }

    public function test_una_empresa_extra_recibe_su_ruta_de_administracion(): void
    {
        $this->crearEmpresas();
        $this->sembrar();

        $canes = Enterprise::where('slug', 'canes-agro')->first();
        $app = Application::where('slug', 'administration')->where('enterprise_id', $canes->id)->first();

        $this->assertSame('/canes-agro/administration', $app->path);
        $this->assertSame('Administración', $app->name);
        $this->assertTrue((bool) $app->is_active);
    }

    public function test_grupo_esplendido_ve_a_las_empresas_nuevas_en_el_selector_de_alta(): void
    {
        $this->crearEmpresas();
        $this->sembrar();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $slugs = collect($this->getJson('/api/grupoesplendido/administration/activos-fijos/catalogos')
            ->assertOk()
            ->json('data.empresas'))->pluck('slug');

        $this->assertTrue($slugs->contains('splendid-logistic'));
        $this->assertTrue($slugs->contains('canes-agro'));
    }
}
