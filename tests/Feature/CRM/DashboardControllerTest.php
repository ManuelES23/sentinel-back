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
            ['embudo'],
            ['embudo-conversion'],
            ['tendencia'],
            ['cotizaciones'],
            ['actividad'],
            ['cumplimiento-metas'],
            ['detalle?tipo=ganadas'],
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

        $response->assertOk()->assertJsonPath('data.pipeline.abiertas', 1);
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

        $response->assertOk()->assertJsonPath('data.pipeline.abiertas', 2);
    }

    public function test_ver_sin_vendedor_propio_devuelve_ceros_no_error(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        // El actingUser no tiene NINGÚN CrmVendedor propio.

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/kpis');

        $response->assertOk()
            ->assertJsonPath('data.pipeline.abiertas', 0)
            ->assertJsonPath('data.clientesNuevos.actual', 0);
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

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/embudo');

        $response->assertOk()->assertJsonCount(4, 'data'); // las 4 etapas abiertas, sin importar el periodo
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

        $response->assertOk()->assertJsonPath('data.pipeline.abiertas', 0);
    }

    private function getDashboard(string $ruta)
    {
        return $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/'.$ruta);
    }

    public function test_periodo_personalizado_valido(): void
    {
        $this->otorgarPermisosDashboard(['ver']);

        $this->getDashboard('kpis?periodo=personalizado&desde=2026-09-15&hasta=2026-10-08')
            ->assertOk()
            ->assertJsonPath('data.rango.inicio', '2026-09-15')
            ->assertJsonPath('data.rango.fin', '2026-10-08');
    }

    public function test_periodo_personalizado_invalido_devuelve_422(): void
    {
        $this->otorgarPermisosDashboard(['ver']);

        $this->getDashboard('kpis?periodo=personalizado')->assertStatus(422)->assertJsonValidationErrors(['desde', 'hasta']);
        $this->getDashboard('kpis?periodo=personalizado&desde=2026-10-08&hasta=2026-10-01')->assertStatus(422)->assertJsonValidationErrors(['hasta']);
        $this->getDashboard('kpis?periodo=personalizado&desde=2025-01-01&hasta=2026-10-01')->assertStatus(422)->assertJsonValidationErrors(['hasta']);
    }

    public function test_periodo_personalizado_exige_formato_estricto_y_limite_de_366_dias(): void
    {
        $this->otorgarPermisosDashboard(['ver']);

        // Formato estricto Y-m-d: RangoDashboard parsea de forma laxa, así que el controller lo exige.
        $this->getDashboard('kpis?periodo=personalizado&desde=ayer&hasta=2026-10-01')->assertStatus(422)->assertJsonValidationErrors(['desde']);
        $this->getDashboard('kpis?periodo=personalizado&desde=2026-9-1&hasta=2026-10-01')->assertStatus(422)->assertJsonValidationErrors(['desde']);
        $this->getDashboard('kpis?periodo=personalizado&desde=2026-09-01&hasta=2026-10-01T10:00:00')->assertStatus(422)->assertJsonValidationErrors(['hasta']);

        // Frontera: 366 días exactos (2025-10-01 a 2026-10-01 inclusive) pasa; 367 no.
        $this->getDashboard('kpis?periodo=personalizado&desde=2025-10-01&hasta=2026-10-01')->assertOk();
        $this->getDashboard('kpis?periodo=personalizado&desde=2025-09-30&hasta=2026-10-01')->assertStatus(422)->assertJsonValidationErrors(['hasta']);
    }

    public function test_sin_ejecutivo_el_vendedor_id_se_ignora(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        $propio = $this->crearVendedorPropio();
        \App\Models\CRM\CrmOportunidad::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $propio->id, 'nombre' => 'Mía', 'etapa' => 'propuesta']);
        \App\Models\CRM\CrmOportunidad::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'Ajena', 'etapa' => 'propuesta']);

        $this->getDashboard("kpis?vendedor_id={$this->vendedor->id}")
            ->assertOk()
            ->assertJsonPath('data.pipeline.abiertas', 1);
    }

    public function test_con_ejecutivo_filtra_por_vendedor_de_la_empresa(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        $otro = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro']);
        \App\Models\CRM\CrmOportunidad::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $otro->id, 'nombre' => 'A', 'etapa' => 'propuesta']);
        \App\Models\CRM\CrmOportunidad::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'B', 'etapa' => 'propuesta']);

        $this->getDashboard("kpis?vendedor_id={$otro->id}")->assertOk()->assertJsonPath('data.pipeline.abiertas', 1);
        $this->getDashboard('kpis')->assertOk()->assertJsonPath('data.pipeline.abiertas', 2);
    }

    public function test_con_ejecutivo_vendedor_de_otra_empresa_devuelve_422(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        $otraEmpresa = $this->crearOtraEmpresa();
        $ajeno = CrmVendedor::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Ajeno']);

        $this->getDashboard("kpis?vendedor_id={$ajeno->id}")->assertStatus(422)->assertJsonValidationErrors(['vendedor_id']);
    }

    public function test_comparar_agrega_el_periodo_anterior(): void
    {
        $this->otorgarPermisosDashboard(['ver']);

        $this->getDashboard('actividad?comparar=1')->assertOk()->assertJsonPath('data.porTipo.0.anterior', 0);
        $this->getDashboard('actividad')->assertOk()->assertJsonMissingPath('data.porTipo.0.anterior');
    }

    public function test_detalle_valida_tipo_y_etapa(): void
    {
        $this->otorgarPermisosDashboard(['ver']);

        $this->getDashboard('detalle?tipo=abiertas&etapa=prospecto')->assertOk()->assertJsonPath('data.total', 0);
        $this->getDashboard('detalle?tipo=otra')->assertStatus(422);
        $this->getDashboard('detalle?tipo=abiertas')->assertStatus(422);
        $this->getDashboard('detalle?tipo=abiertas&etapa=sin_registro')->assertStatus(422);
        $this->getDashboard('detalle?tipo=perdidas&etapa=sin_registro')->assertOk();
    }

    public function test_detalle_valida_la_etapa_segun_el_tipo(): void
    {
        $this->otorgarPermisosDashboard(['ver']);

        // abiertas y llegaron: solo las cuatro etapas abiertas.
        foreach (['abiertas', 'llegaron'] as $tipo) {
            foreach (['prospecto', 'calificado', 'propuesta', 'negociacion'] as $etapa) {
                $this->getDashboard("detalle?tipo={$tipo}&etapa={$etapa}")->assertOk();
            }
            $this->getDashboard("detalle?tipo={$tipo}&etapa=cerrado_ganado")->assertStatus(422)->assertJsonValidationErrors(['etapa']);
            $this->getDashboard("detalle?tipo={$tipo}&etapa=sin_registro")->assertStatus(422)->assertJsonValidationErrors(['etapa']);
            $this->getDashboard("detalle?tipo={$tipo}&etapa=inexistente")->assertStatus(422)->assertJsonValidationErrors(['etapa']);
        }

        // perdidas: una etapa abierta o sin_registro; no cerrado_*.
        $this->getDashboard('detalle?tipo=perdidas&etapa=negociacion')->assertOk();
        $this->getDashboard('detalle?tipo=perdidas&etapa=cerrado_perdido')->assertStatus(422)->assertJsonValidationErrors(['etapa']);
        $this->getDashboard('detalle?tipo=perdidas')->assertStatus(422)->assertJsonValidationErrors(['etapa']);

        // ganadas: sin etapa.
        $this->getDashboard('detalle?tipo=ganadas')->assertOk();
        $this->getDashboard('detalle?tipo=ganadas&etapa=prospecto')->assertStatus(422)->assertJsonValidationErrors(['etapa']);
        $this->getDashboard('detalle?tipo=ganadas&etapa=sin_registro')->assertStatus(422)->assertJsonValidationErrors(['etapa']);
    }

    public function test_los_endpoints_eliminados_ya_no_existen(): void
    {
        $this->otorgarPermisosDashboard(['ver']);

        $this->getDashboard('pipeline')->assertNotFound();
        $this->getDashboard('funnel-conversion')->assertNotFound();
    }

    public function test_ranking_sigue_exigiendo_solo_ejecutivo_y_trae_meta(): void
    {
        $this->otorgarPermisosDashboard(['ejecutivo']);

        $this->getDashboard('ranking-vendedores')->assertOk()->assertJsonPath('data.0.meta', 0);
    }
}
