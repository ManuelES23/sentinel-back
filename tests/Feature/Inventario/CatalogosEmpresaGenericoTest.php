<?php

namespace Tests\Feature\Inventario;

use App\Http\Middleware\EmpresaDeLaRuta;
use App\Models\Brand;
use App\Models\Enterprise;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Inventario → Catálogos (categorías, unidades, artículos, marcas y recetas) de
 * empresas distintas de Splendid Farms / Splendid by Porvenir: viven en un solo
 * grupo genérico {empresa}/inventario (routes/inventario-catalogos-empresa.php,
 * middleware EmpresaDeLaRuta). Sirven a CUALQUIER empresa existente por su slug,
 * así que un cambio de nombre/slug (le pasó a Canes Agro) o una empresa nueva no
 * las rompe. SF y SBP conservan sus rutas propias.
 */
class CatalogosEmpresaGenericoTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'canagro-prueba';

    private Enterprise $sf;

    private Enterprise $canagro;

    private User $usuario;

    private UnitOfMeasure $unidad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sf = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'SF', 'is_active' => true]);
        $this->canagro = Enterprise::create(['name' => 'Canagro Prueba', 'slug' => self::SLUG, 'description' => 'Empresa de prueba', 'is_active' => true]);

        $this->usuario = User::factory()->create(['role' => 'user']);
        UserEnterpriseAccess::create(['user_id' => $this->usuario->id, 'enterprise_id' => $this->canagro->id, 'is_active' => true]);

        $this->unidad = UnitOfMeasure::create(['code' => 'KG', 'name' => 'Kilogramo', 'abbreviation' => 'kg', 'type' => 'weight']);
        $this->unidad->enterprises()->attach([$this->sf->id, $this->canagro->id]);

        // Datos de las dos empresas: la de la URL solo debe ver los suyos.
        $this->categoria($this->canagro, 'CAT-CA', 'Alimento CA');
        $this->categoria($this->sf, 'CAT-SF', 'Cajas SF');
        $this->marca($this->canagro, 'MRC-CA', 'AgroQuim');
        $this->marca($this->sf, 'MRC-SF', 'CajaMax');
        $this->articulo($this->canagro, 'Croqueta CA', 'CAT-CA');
        $this->articulo($this->sf, 'Caja SF', 'CAT-SF');

        Sanctum::actingAs($this->usuario);
    }

    private function categoria(Enterprise $empresa, string $codigo, string $nombre): ProductCategory
    {
        $cat = ProductCategory::create(['code' => $codigo, 'name' => $nombre, 'is_active' => true]);
        $cat->enterprises()->attach($empresa->id);

        return $cat;
    }

    private function marca(Enterprise $empresa, string $codigo, string $nombre): Brand
    {
        $marca = Brand::create(['code' => $codigo, 'name' => $nombre]);
        $marca->enterprises()->attach($empresa->id);

        return $marca;
    }

    private function articulo(Enterprise $empresa, string $nombre, string $codigoCategoria): Product
    {
        $producto = Product::create([
            'code' => 'P-'.strtoupper(substr($nombre, 0, 3)).'-'.$empresa->id,
            'name' => $nombre,
            'category_id' => ProductCategory::where('code', $codigoCategoria)->value('id'),
            'unit_id' => $this->unidad->id,
            'cost_price' => 10,
        ]);
        $producto->enterprises()->attach($empresa->id);

        return $producto;
    }

    private function nombres($respuesta): array
    {
        $datos = $respuesta->json('data');
        $datos = $datos['data'] ?? $datos; // listados paginados

        return collect($datos)->pluck('name')->all();
    }

    private function base(string $slug = self::SLUG): string
    {
        return "/api/{$slug}/inventario/catalogos";
    }

    // --------------------------------------------------- empresa con slug arbitrario

    public function test_categorias_de_una_empresa_con_slug_arbitrario_solo_trae_las_suyas(): void
    {
        $r = $this->getJson($this->base().'/categorias');

        $r->assertOk();
        $this->assertContains('Alimento CA', $this->nombres($r));
        $this->assertNotContains('Cajas SF', $this->nombres($r));
    }

    public function test_marcas_de_una_empresa_con_slug_arbitrario_solo_trae_las_suyas(): void
    {
        $r = $this->getJson($this->base().'/marcas');

        $r->assertOk();
        $this->assertSame(['AgroQuim'], $this->nombres($r));
    }

    public function test_articulos_de_una_empresa_con_slug_arbitrario_solo_trae_los_suyos(): void
    {
        $r = $this->getJson($this->base().'/articulos');

        $r->assertOk();
        $this->assertSame(['Croqueta CA'], $this->nombres($r));
    }

    public function test_unidades_y_recetas_responden_para_una_empresa_con_slug_arbitrario(): void
    {
        $this->getJson($this->base().'/unidades')->assertOk();
        $this->getJson($this->base().'/recetas')->assertOk();
    }

    public function test_el_header_x_enterprise_slug_no_cambia_la_empresa_de_la_url(): void
    {
        // El cliente manda el slug de SF pero la URL es de la empresa nueva: el
        // middleware reescribe el header, así que no puede leer datos de SF.
        $cabecera = ['X-Enterprise-Slug' => 'splendidfarms'];

        $this->assertSame(['AgroQuim'], $this->nombres($this->getJson($this->base().'/marcas', $cabecera)));
        $this->assertSame(['Croqueta CA'], $this->nombres($this->getJson($this->base().'/articulos', $cabecera)));
        $this->assertNotContains('Cajas SF', $this->nombres($this->getJson($this->base().'/categorias', $cabecera)));
    }

    public function test_el_alta_se_vincula_a_la_empresa_de_la_url_aunque_el_header_diga_otra(): void
    {
        $r = $this->postJson($this->base().'/marcas', ['name' => 'Nutrisol'], ['X-Enterprise-Slug' => 'splendidfarms']);

        $r->assertCreated();
        $this->assertDatabaseHas('enterprise_brand', ['enterprise_id' => $this->canagro->id, 'brand_id' => $r->json('data.id')]);
        $this->assertDatabaseMissing('enterprise_brand', ['enterprise_id' => $this->sf->id, 'brand_id' => $r->json('data.id')]);
    }

    public function test_rutas_con_parametro_de_modelo_funcionan_sin_que_el_slug_desplace_los_argumentos(): void
    {
        $marca = Brand::where('code', 'MRC-CA')->first();
        $producto = Product::where('name', 'Croqueta CA')->first();

        $this->getJson($this->base()."/marcas/{$marca->id}")->assertOk()->assertJsonPath('data.name', 'AgroQuim');
        $this->putJson($this->base()."/marcas/{$marca->id}", ['name' => 'AgroQuim Plus'])->assertOk();
        $this->assertSame('AgroQuim Plus', $marca->fresh()->name);

        $this->getJson($this->base()."/articulos/{$producto->id}")->assertOk()->assertJsonPath('data.name', 'Croqueta CA');
    }

    public function test_el_flujo_de_recetas_funciona_con_un_slug_arbitrario(): void
    {
        $ingrediente = Product::where('name', 'Croqueta CA')->first();

        $r = $this->postJson($this->base().'/recetas', [
            'name' => 'Mezcla Canagro',
            'output_quantity' => 100,
            'output_unit_id' => $this->unidad->id,
            'items' => [['product_id' => $ingrediente->id, 'quantity' => 5]],
        ]);

        $r->assertOk();
        $id = $r->json('data.id');
        $this->getJson($this->base()."/recetas/{$id}")->assertOk()->assertJsonPath('data.name', 'Mezcla Canagro');
        $this->assertContains('Mezcla Canagro', $this->nombres($this->getJson($this->base().'/recetas')));
    }

    // ---------------------------------------------------------------- acceso

    public function test_un_usuario_sin_acceso_a_la_empresa_recibe_403(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson($this->base().'/marcas')->assertForbidden();
        $this->getJson($this->base().'/articulos')->assertForbidden();
        $this->postJson($this->base().'/marcas', ['name' => 'Intruso'])->assertForbidden();
        $this->assertDatabaseMissing('brands', ['name' => 'Intruso']);
    }

    public function test_un_acceso_inactivo_recibe_403(): void
    {
        $otro = User::factory()->create(['role' => 'user']);
        UserEnterpriseAccess::create(['user_id' => $otro->id, 'enterprise_id' => $this->canagro->id, 'is_active' => false]);
        Sanctum::actingAs($otro);

        $this->getJson($this->base().'/marcas')->assertForbidden();
    }

    public function test_un_administrador_entra_sin_acceso_explicito(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson($this->base().'/marcas')->assertOk();
    }

    public function test_un_slug_inexistente_recibe_404(): void
    {
        $this->getJson($this->base('no-existe-xyz').'/marcas')->assertNotFound();
        $this->getJson($this->base('no-existe-xyz').'/articulos')->assertNotFound();
    }

    // ------------------------------------------------- empresas históricas intactas

    public function test_el_slug_canes_agro_sigue_funcionando_por_el_grupo_generico(): void
    {
        $canes = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'description' => 'Canes', 'is_active' => true]);
        UserEnterpriseAccess::create(['user_id' => $this->usuario->id, 'enterprise_id' => $canes->id, 'is_active' => true]);
        $this->marca($canes, 'MRC-CN', 'Nutrican');

        $r = $this->getJson('/api/canes-agro/inventario/catalogos/marcas');

        $r->assertOk();
        $this->assertSame(['Nutrican'], $this->nombres($r));
    }

    public function test_splendidfarms_y_splendidbyporvenir_siguen_con_sus_rutas_propias(): void
    {
        foreach (['splendidfarms', 'splendidbyporvenir'] as $slug) {
            foreach (['categorias', 'unidades', 'articulos', 'marcas', 'recetas'] as $recurso) {
                $ruta = Route::getRoutes()->match(Request::create("/api/{$slug}/inventario/catalogos/{$recurso}", 'GET'));

                $this->assertSame("api/{$slug}/inventario/catalogos/{$recurso}", $ruta->uri(), "{$slug}/{$recurso} debe resolverse con su ruta propia");
                $this->assertNotContains(EmpresaDeLaRuta::class, $ruta->gatherMiddleware(), "{$slug}/{$recurso} no debe pasar por el grupo genérico");
            }
        }
    }

    public function test_splendidfarms_sigue_sirviendo_su_inventario_sin_exigir_acceso_de_empresa(): void
    {
        // Comportamiento histórico: SF no pasa por EmpresaDeLaRuta (un usuario sin
        // user_enterprise_access no recibe el 403 del grupo genérico).
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $r = $this->getJson('/api/splendidfarms/inventario/catalogos/marcas', ['X-Enterprise-Slug' => 'splendidfarms']);

        $r->assertOk();
        $this->assertSame(['CajaMax'], $this->nombres($r));
    }

    public function test_el_slug_nuevo_se_resuelve_por_el_grupo_generico(): void
    {
        $ruta = Route::getRoutes()->match(Request::create('/api/'.self::SLUG.'/inventario/catalogos/marcas', 'GET'));

        $this->assertSame('api/{empresa}/inventario/catalogos/marcas', $ruta->uri());
        $this->assertContains(EmpresaDeLaRuta::class, $ruta->gatherMiddleware());
    }
}
