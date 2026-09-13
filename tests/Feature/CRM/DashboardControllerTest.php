<?php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\CRM\CrmVendedor;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    /** Crea el árbol Application/Module/Submodule/PermissionType y otorga los permisos dados al actingUser sobre el submódulo dashboard. */
    private function otorgarPermisosDashboard(array $slugs): void
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $this->enterprise->id, 'slug' => 'crm'],
            ['name' => 'CRM Comercial', 'description' => 'CRM Comercial', 'path' => '/'.$this->enterprise->slug.'/crm', 'is_active' => true],
        );
        $modulo = Module::firstOrCreate(
            ['application_id' => $app->id, 'slug' => 'dashboard'],
            ['name' => 'Dashboard', 'order' => 10, 'is_active' => true],
        );
        $submodulo = Submodule::firstOrCreate(
            ['module_id' => $modulo->id, 'slug' => 'dashboard'],
            ['name' => 'Dashboard', 'order' => 1, 'is_active' => true],
        );

        foreach (['ver', 'ejecutivo'] as $slug) {
            $tipo = SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $submodulo->id, 'slug' => $slug],
                ['name' => ucfirst($slug), 'order' => 1, 'is_active' => true],
            );

            if (in_array($slug, $slugs, true)) {
                UserSubmodulePermission::create([
                    'user_id' => $this->actingUser->id,
                    'submodule_id' => $submodulo->id,
                    'permission_type_id' => $tipo->id,
                    'is_granted' => true,
                ]);
            }
        }
    }

    /** Crea un CrmVendedor cuyo user_id es el actingUser. */
    private function crearVendedorPropio(): CrmVendedor
    {
        return CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id, 'nombre' => 'Vendedor propio',
        ]);
    }

    public static function endpointsRegularesProvider(): array
    {
        return [
            ['kpis'],
            ['pipeline'],
            ['cotizaciones'],
            ['funnel-conversion'],
            ['actividad'],
            ['cumplimiento-metas'],
        ];
    }

    #[DataProvider('endpointsRegularesProvider')]
    public function test_rechaza_endpoint_regular_sin_permiso_ver(string $endpoint): void
    {
        $this->otorgarPermisosDashboard([]);

        $response = $this->withHeaders($this->crmHeaders())->getJson("/api/crm/dashboard/{$endpoint}");

        $response->assertStatus(403);
    }

    #[DataProvider('endpointsRegularesProvider')]
    public function test_permite_endpoint_regular_con_permiso_ver(string $endpoint): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        $this->crearVendedorPropio();

        $response = $this->withHeaders($this->crmHeaders())->getJson("/api/crm/dashboard/{$endpoint}");

        $response->assertOk();
    }

    public function test_ranking_vendedores_rechaza_sin_permiso_ejecutivo(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        $this->crearVendedorPropio();

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/ranking-vendedores');

        $response->assertStatus(403);
    }

    public function test_ranking_vendedores_permite_con_permiso_ejecutivo(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/ranking-vendedores');

        $response->assertOk();
    }

    public function test_ver_sin_ejecutivo_solo_ve_sus_propias_metricas(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        $vendedorPropio = $this->crearVendedorPropio();

        \App\Models\CRM\CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $vendedorPropio->id,
            'nombre' => 'Propia', 'monto_esperado' => 1000, 'etapa' => 'propuesta',
        ]);
        \App\Models\CRM\CrmOportunidad::create([
            // De otro vendedor (fixture) -- un 'ver'-only NO debe verla.
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Ajena', 'monto_esperado' => 5000, 'etapa' => 'propuesta',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/kpis');

        $response->assertOk()->assertJsonPath('data.oportunidadesAbiertas', 1);
    }

    public function test_ejecutivo_ve_el_agregado_de_todo_el_equipo(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        $otroVendedor = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro', 'activo' => true]);

        \App\Models\CRM\CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'De A', 'monto_esperado' => 1000, 'etapa' => 'propuesta',
        ]);
        \App\Models\CRM\CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $otroVendedor->id,
            'nombre' => 'De B', 'monto_esperado' => 2000, 'etapa' => 'propuesta',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/kpis');

        $response->assertOk()->assertJsonPath('data.oportunidadesAbiertas', 2);
    }

    public function test_ver_sin_vendedor_propio_devuelve_ceros_no_error(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        // El actingUser no tiene NINGÚN CrmVendedor propio.

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/kpis');

        $response->assertOk()
            ->assertJsonPath('data.oportunidadesAbiertas', 0)
            ->assertJsonPath('data.clientesNuevos', 0);
    }

    public function test_sin_empresa_resuelta_rechaza_con_403(): void
    {
        // $this->actingUser (de CreatesCrmFixtures) siempre tiene un
        // UserEnterpriseAccess activo a $this->enterprise, así que omitir
        // el header no basta para dejar la empresa sin resolver --
        // FiltraPorEmpresa::getEmpresaId() cae a ese acceso ("Opción 3",
        // ver EmpresaAccessControlTest::test_usuario_con_acceso_a_la_empresa...).
        // Para probar de verdad el caso "sin empresa resuelta" se necesita un
        // usuario sin NINGÚN acceso activo, igual que
        // EmpresaAccessControlTest::test_usuario_sin_ningun_acceso_activo_es_rechazado_incluso_sin_header.
        $usuarioSinAcceso = \App\Models\User::factory()->create();
        Sanctum::actingAs($usuarioSinAcceso);

        $response = $this->getJson('/api/crm/dashboard/kpis'); // sin header X-Enterprise-Id ni acceso activo

        $response->assertStatus(403);
    }

    public function test_periodo_invalido_devuelve_422(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        $this->crearVendedorPropio();

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson('/api/crm/dashboard/kpis?periodo=siglo');

        $response->assertStatus(422);
    }

    public function test_periodo_omitido_usa_mes_actual_por_default(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        $this->crearVendedorPropio();

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/pipeline');

        $response->assertOk()->assertJsonCount(6, 'data'); // las 6 etapas, sin importar el periodo default
    }

    public function test_no_ve_datos_de_otra_empresa(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        $otraEmpresa = $this->crearOtraEmpresa();
        $this->otorgarAccesoA($otraEmpresa);
        $vendedorAjeno = CrmVendedor::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Ajeno']);
        \App\Models\CRM\CrmOportunidad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'nombre' => 'Op de otra empresa', 'monto_esperado' => 99999, 'etapa' => 'propuesta',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/kpis');

        $response->assertOk()->assertJsonPath('data.oportunidadesAbiertas', 0);
    }
}
