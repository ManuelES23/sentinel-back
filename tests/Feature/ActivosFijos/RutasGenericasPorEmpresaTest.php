<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\Branch;
use App\Models\Enterprise;
use App\Models\FixedAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\TestCase;

/**
 * Las rutas de Administración → Activos Fijos y Organización viven en un solo
 * grupo genérico {empresa}/administration (middleware EmpresaDeLaRuta): sirven
 * a CUALQUIER empresa existente por su slug, sin lista de slugs fijos, así que
 * un cambio de nombre/slug o una empresa nueva no las rompe.
 */
class RutasGenericasPorEmpresaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;

    private const SLUG = 'canagro-prueba';

    private Enterprise $canagro;

    private Branch $sucursalCanagro;

    private FixedAsset $activoCanagro;

    private User $usuarioCanagro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();

        $this->canagro = $this->crearEmpresaActivos(self::SLUG, 'Canagro Prueba', 'CP');
        [$this->sucursalCanagro, $entidad] = $this->crearUbicacion($this->canagro, 'CP');

        $this->activoCanagro = FixedAsset::create($this->validFixedAssetPayload([
            'enterprise_id' => $this->canagro->id, 'code' => 'CP-AF-000001', 'name' => 'Tractor Canagro',
            'branch_id' => $this->sucursalCanagro->id, 'entity_id' => $entidad->id, 'brand_id' => null,
        ]));
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'SF-AF-000001', 'name' => 'Laptop SF']));

        $this->usuarioCanagro = User::factory()->create(['role' => 'user']);
        $this->otorgarActivos($this->usuarioCanagro, $this->canagro, 'activos', self::PERMISOS_CRUD);
    }

    private function base(string $slug = self::SLUG): string
    {
        return "/api/{$slug}/administration";
    }

    // ------------------------------------------------------------ Activos Fijos

    public function test_una_empresa_con_slug_arbitrario_sirve_activos_fijos(): void
    {
        Sanctum::actingAs($this->usuarioCanagro);

        $this->getJson($this->base().'/activos-fijos/mis-permisos')
            ->assertOk()
            ->assertJsonPath('data.activos.view', true)
            ->assertJsonPath('data.corporativo', false);

        $this->getJson($this->base().'/activos-fijos/activos')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.code', 'CP-AF-000001');
    }

    public function test_rutas_con_modelo_e_id_reciben_sus_argumentos_sin_el_slug(): void
    {
        Sanctum::actingAs($this->usuarioCanagro);
        $url = $this->base()."/activos-fijos/activos/{$this->activoCanagro->id}";

        $this->getJson($url)->assertOk()->assertJsonPath('data.code', 'CP-AF-000001');
        $this->putJson($url, ['name' => 'Tractor renombrado'])->assertOk()->assertJsonPath('data.name', 'Tractor renombrado');
        $this->deleteJson($url)->assertOk();
        $this->assertSoftDeleted('fixed_assets', ['id' => $this->activoCanagro->id]);
    }

    // ------------------------------------------------------------- Organización

    public function test_organizacion_lista_solo_la_empresa_de_la_url_aunque_el_header_diga_otra(): void
    {
        $this->crearUbicacion($this->enterprise, 'SFX'); // otra sucursal de SF
        Sanctum::actingAs($this->usuarioCanagro);

        $respuesta = $this->getJson($this->base().'/organizacion/sucursales', ['X-Enterprise-Slug' => 'splendidfarms'])
            ->assertOk();

        $this->assertSame(['SUC-CP'], collect($respuesta->json('data'))->pluck('code')->all());
    }

    public function test_organizacion_sin_header_tambien_queda_en_la_empresa_de_la_url(): void
    {
        Sanctum::actingAs($this->usuarioCanagro);

        $respuesta = $this->getJson($this->base().'/organizacion/sucursales')->assertOk();

        $this->assertSame(['SUC-CP'], collect($respuesta->json('data'))->pluck('code')->all());
    }

    public function test_crear_sucursal_queda_en_la_empresa_de_la_url(): void
    {
        Sanctum::actingAs($this->usuarioCanagro);

        $id = $this->postJson(
            $this->base().'/organizacion/sucursales',
            ['name' => 'Rancho Norte', 'enterprise_id' => $this->enterprise->id],
            ['X-Enterprise-Slug' => 'splendidfarms'],
        )->assertCreated()->json('data.id');

        $this->assertSame($this->canagro->id, Branch::find($id)->enterprise_id);
    }

    public function test_ver_editar_y_borrar_una_sucursal_por_id(): void
    {
        Sanctum::actingAs($this->usuarioCanagro);
        $sucursal = Branch::create([
            'enterprise_id' => $this->canagro->id, 'code' => 'SUC-CP2', 'name' => 'Bodega CP',
            'slug' => 'bodega-cp', 'is_active' => true, 'is_main' => false,
        ]);
        $url = $this->base()."/organizacion/sucursales/{$sucursal->id}";

        $this->getJson($url)->assertOk()->assertJsonPath('data.code', 'SUC-CP2');
        $this->putJson($url, ['name' => 'Bodega renombrada'])->assertOk()->assertJsonPath('data.name', 'Bodega renombrada');
        $this->deleteJson($url)->assertOk();
        $this->assertSoftDeleted('branches', ['id' => $sucursal->id]);
    }

    public function test_tipos_entidades_entidades_y_areas_responden(): void
    {
        Sanctum::actingAs($this->usuarioCanagro);

        foreach (['tipos-entidades', 'entidades', 'areas'] as $recurso) {
            $this->getJson($this->base()."/organizacion/{$recurso}")->assertOk();
        }
    }

    // ---------------------------------------------------------- Acceso y slugs

    public function test_un_usuario_sin_acceso_a_la_empresa_recibe_403(): void
    {
        Sanctum::actingAs($this->actingUser); // acceso a SF y GE, no a canagro-prueba

        $this->getJson($this->base().'/activos-fijos/mis-permisos')
            ->assertForbidden()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'No tienes acceso a esta empresa.');
        $this->getJson($this->base().'/organizacion/sucursales')->assertForbidden();
        $this->postJson($this->base().'/organizacion/sucursales', ['name' => 'Intrusa'])->assertForbidden();
        $this->assertSame(0, Branch::where('name', 'Intrusa')->count());
    }

    public function test_un_admin_entra_a_cualquier_empresa_existente(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson($this->base().'/activos-fijos/mis-permisos')->assertOk();
        $this->getJson($this->base().'/organizacion/sucursales')->assertOk();
    }

    public function test_un_slug_inexistente_responde_404(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson($this->base('no-existe').'/activos-fijos/mis-permisos')
            ->assertNotFound()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Empresa no encontrada');
        $this->getJson($this->base('no-existe').'/organizacion/sucursales')->assertNotFound();
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson($this->base().'/activos-fijos/mis-permisos')->assertUnauthorized();
        $this->getJson($this->base().'/organizacion/sucursales')->assertUnauthorized();
    }

    public function test_un_slug_con_caracteres_fuera_del_patron_no_resuelve(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/canagro.prueba/administration/activos-fijos/mis-permisos')->assertNotFound();
    }

    public function test_renombrar_el_slug_sigue_funcionando_con_el_nuevo_y_no_con_el_viejo(): void
    {
        Sanctum::actingAs($this->usuarioCanagro);
        $this->canagro->update(['slug' => 'canagro-renombrada']);

        $this->getJson($this->base('canagro-renombrada').'/activos-fijos/activos')
            ->assertOk()
            ->assertJsonPath('data.data.0.code', 'CP-AF-000001');
        $sucursales = $this->getJson($this->base('canagro-renombrada').'/organizacion/sucursales')->assertOk();
        $this->assertSame(['SUC-CP'], collect($sucursales->json('data'))->pluck('code')->all());

        $this->getJson($this->base().'/activos-fijos/activos')->assertNotFound();
        $this->getJson($this->base().'/organizacion/sucursales')->assertNotFound();
    }

    // ------------------------------------------------------ Empresas históricas

    public function test_las_empresas_historicas_siguen_sirviendo_activos_y_organizacion(): void
    {
        $porvenir = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $this->crearUbicacion($porvenir, 'SBP');
        $this->otorgarActivos($this->actingUser, $porvenir, 'activos', self::PERMISOS_CRUD);
        Sanctum::actingAs($this->actingUser);

        $this->getJson($this->base('splendidfarms').'/activos-fijos/activos')
            ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.code', 'SF-AF-000001');
        $this->getJson($this->base('splendidbyporvenir').'/activos-fijos/activos')
            ->assertOk()->assertJsonCount(0, 'data.data');
        // El corporativo consolida todas las empresas con el módulo.
        $this->getJson($this->base('grupoesplendido').'/activos-fijos/activos')
            ->assertOk()->assertJsonPath('data.total', 2);

        foreach (['splendidfarms' => 'SUC-SF', 'splendidbyporvenir' => 'SUC-SBP'] as $slug => $codigo) {
            $respuesta = $this->getJson($this->base($slug).'/organizacion/sucursales', ['X-Enterprise-Slug' => $slug])->assertOk();
            $this->assertSame([$codigo], collect($respuesta->json('data'))->pluck('code')->all(), $slug);
        }
    }

    public function test_otras_rutas_de_administration_no_las_toma_el_grupo_generico(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $ruta = app('router')->getRoutes()->match(
            \Illuminate\Http\Request::create('/api/splendidfarms/administration/catalogos/proveedores/list', 'GET')
        );

        $this->assertSame('api/splendidfarms/administration/catalogos/proveedores/list', $ruta->uri());
        $this->assertStringNotContainsString('EmpresaDeLaRuta', implode(',', $ruta->gatherMiddleware()));
    }
}
