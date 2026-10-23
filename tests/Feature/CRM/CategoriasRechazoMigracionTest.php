<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCategoriaRechazo;
use App\Models\CRM\CrmMotivoRechazo;
use App\Models\Enterprise;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use App\Support\CRM\CategoriasRechazoPredeterminadas;
use App\Support\CRM\EmpresasCrm;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Migración que agrega catalogos.categorias-rechazo a los CRM ya sembrados,
 * se lo concede a quien edita la configuración comercial y siembra el catálogo
 * de Splendid by Porvenir (spec 2026-10-23 §4.5).
 */
class CategoriasRechazoMigracionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MIGRACION = 'migrations/2026_10_23_100300_add_crm_categorias_rechazo_submodule.php';

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

    private function crearSbp(): Enterprise
    {
        return Enterprise::create([
            'name' => 'Splendid by Porvenir',
            'slug' => EmpresasCrm::SLUG_SBP,
            'description' => 'SBP de prueba',
            'is_active' => true,
        ]);
    }

    private function submoduloEn(Enterprise $empresa): ?Submodule
    {
        return Submodule::where('slug', 'categorias-rechazo')
            ->whereHas('module', fn ($m) => $m->where('slug', 'catalogos')
                ->whereHas('application', fn ($a) => $a->where('slug', 'crm')->where('enterprise_id', $empresa->id)))
            ->first();
    }

    /** CRM sembrado y sin el submódulo nuevo, como antes del despliegue. */
    private function sinSubmodulo(Enterprise ...$empresas): void
    {
        (new CrmPermisosSeeder)->run();
        foreach ($empresas as $empresa) {
            $sub = $this->submoduloEn($empresa);
            SubmodulePermissionType::where('submodule_id', $sub->id)->delete();
            $sub->delete();
        }
    }

    public function test_siembra_6_categorias_y_14_motivos_en_sbp(): void
    {
        $sbp = $this->crearSbp();

        $this->subir();

        $categorias = CrmCategoriaRechazo::where('empresa_id', $sbp->id)->orderBy('orden')->with('motivos')->get();
        $this->assertSame(
            ['Precio', 'Cliente', 'Producto', 'Disponibilidad', 'Crédito', 'Logística'],
            $categorias->pluck('nombre')->all(),
        );
        $this->assertSame([1, 2, 3, 4, 5, 6], $categorias->pluck('orden')->map(fn ($o) => (int) $o)->all());
        $this->assertTrue($categorias->every->activo);
        $this->assertSame(14, CrmMotivoRechazo::whereIn('categoria_id', $categorias->pluck('id'))->count());

        $producto = $categorias->firstWhere('nombre', 'Producto');
        $this->assertSame([
            'Etiqueta no aceptada',
            'Problemas de calidad',
            'Problemas con presentación de producto',
            'Diferencia en label',
            'Diferencia en variedad',
            'Diferencia en cantidades',
        ], $producto->motivos->pluck('nombre')->all());
        $this->assertTrue($categorias->firstWhere('nombre', 'Crédito')->motivos->pluck('nombre')->contains('Sin Bluebook'));
        $this->assertTrue(CrmCategoriaRechazo::empresaTieneCatalogo($sbp->id));
    }

    public function test_lista_predeterminada_tiene_el_tamano_del_spec(): void
    {
        $this->assertCount(6, CategoriasRechazoPredeterminadas::LISTA);
        $this->assertSame(14, array_sum(array_map(fn ($c) => count($c['motivos']), CategoriasRechazoPredeterminadas::LISTA)));
    }

    public function test_idempotente_y_no_siembra_otras_empresas(): void
    {
        $sbp = $this->crearSbp();

        $this->subir();
        $this->subir();

        $this->assertSame(6, CrmCategoriaRechazo::where('empresa_id', $sbp->id)->count());
        $this->assertSame(14, CrmMotivoRechazo::count());
        $this->assertSame(0, CrmCategoriaRechazo::where('empresa_id', $this->enterprise->id)->count());
        $this->assertSame(0, CategoriasRechazoPredeterminadas::sembrar($sbp->id));

        // Un catálogo editado por la empresa no se rehace ni se completa.
        CrmCategoriaRechazo::where('empresa_id', $sbp->id)->where('nombre', 'Precio')->first()->update(['nombre' => 'Costo']);
        $this->subir();
        $this->assertSame(6, CrmCategoriaRechazo::where('empresa_id', $sbp->id)->count());
    }

    public function test_sin_sbp_no_siembra_nada(): void
    {
        $this->subir();

        $this->assertSame(0, CrmCategoriaRechazo::count());
    }

    public function test_crea_el_submodulo_con_sus_permisos_en_cada_empresa(): void
    {
        $otra = $this->crearOtraEmpresa();
        (new CrmPermisosSeeder)->run();
        $this->sinSubmodulo($this->enterprise, $otra);
        $this->assertNull($this->submoduloEn($this->enterprise));

        $this->subir();
        $this->subir();

        foreach ([$this->enterprise, $otra] as $empresa) {
            $sub = $this->submoduloEn($empresa);
            $this->assertNotNull($sub);
            $this->assertSame('Categorías de rechazo', $sub->name);
            $this->assertSame('ThumbsDown', $sub->icon);
            $this->assertSame(8, (int) $sub->order);
            $this->assertSame(
                ['crear', 'editar', 'eliminar', 'ver'],
                SubmodulePermissionType::where('submodule_id', $sub->id)->pluck('slug')->sort()->values()->all(),
            );
        }
        $this->assertSame(2, Submodule::where('slug', 'categorias-rechazo')->count());

        $this->bajar();
        $this->assertSame(0, Submodule::where('slug', 'categorias-rechazo')->count());
    }

    public function test_concede_a_editores_de_configuracion_comercial(): void
    {
        $this->sinSubmodulo($this->enterprise);
        $editor = User::factory()->create();
        $soloVer = User::factory()->create();

        $config = Submodule::where('slug', 'configuracion-comercial')
            ->whereHas('module.application', fn ($a) => $a->where('enterprise_id', $this->enterprise->id))->first();
        foreach ([[$editor, 'editar'], [$soloVer, 'ver']] as [$usuario, $slug]) {
            $tipo = SubmodulePermissionType::where('submodule_id', $config->id)->where('slug', $slug)->first();
            UserSubmodulePermission::create([
                'user_id' => $usuario->id,
                'submodule_id' => $config->id,
                'permission_type_id' => $tipo->id,
                'is_granted' => true,
            ]);
        }

        $this->subir();

        $sub = $this->submoduloEn($this->enterprise);
        $concedidos = fn (User $u) => UserSubmodulePermission::where('user_id', $u->id)
            ->where('submodule_id', $sub->id)->where('is_granted', true)
            ->with('permissionType:id,slug')->get()->pluck('permissionType.slug')->sort()->values()->all();

        $this->assertSame(['crear', 'editar', 'eliminar', 'ver'], $concedidos($editor));
        $this->assertTrue(UserSubmoduleAccess::where('user_id', $editor->id)->where('submodule_id', $sub->id)->where('is_active', true)->exists());
        $this->assertSame([], $concedidos($soloVer));
        $this->assertFalse(UserSubmoduleAccess::where('user_id', $soloVer->id)->where('submodule_id', $sub->id)->exists());
    }

    public function test_el_seeder_de_permisos_registra_el_submodulo_para_empresas_nuevas(): void
    {
        (new CrmPermisosSeeder)->run();

        $sub = $this->submoduloEn($this->enterprise);
        $this->assertNotNull($sub);
        $this->assertSame('Categorías de rechazo', $sub->name);
        $this->assertSame(8, (int) $sub->order);
        $this->assertSame(
            ['crear', 'editar', 'eliminar', 'ver'],
            SubmodulePermissionType::where('submodule_id', $sub->id)->pluck('slug')->sort()->values()->all(),
        );
    }
}
