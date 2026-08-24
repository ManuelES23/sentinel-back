<?php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\CRM\CrmPresupuesto;
use App\Models\CRM\CrmVendedor;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class PresupuestoControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    /** Crea el árbol Application/Module/Submodule/PermissionType y otorga los permisos dados al actingUser. */
    private function otorgarPermisosPresupuestos(array $slugs): void
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $this->enterprise->id, 'slug' => 'crm'],
            [
                'name' => 'CRM Comercial',
                'description' => 'CRM Comercial',
                'path' => '/'.$this->enterprise->slug.'/crm',
                'is_active' => true,
            ],
        );
        $modulo = Module::firstOrCreate(
            ['application_id' => $app->id, 'slug' => 'presupuestos'],
            ['name' => 'Presupuestos', 'order' => 1, 'is_active' => true],
        );
        $submodulo = Submodule::firstOrCreate(
            ['module_id' => $modulo->id, 'slug' => 'presupuestos'],
            ['name' => 'Presupuestos', 'order' => 1, 'is_active' => true],
        );

        foreach (['ver', 'crear', 'editar'] as $slug) {
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

    /** Crea un CrmVendedor cuyo user_id es el actingUser -- para probar el auto-scoping de un usuario 'ver'-only sobre su propio vendedor. */
    private function crearVendedorPropio(): CrmVendedor
    {
        return CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor propio',
        ]);
    }

    public function test_crea_un_presupuesto_con_permiso_crear(): void
    {
        $this->otorgarPermisosPresupuestos(['ver', 'crear']);

        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/presupuestos', [
            'vendedor_id' => $this->vendedor->id,
            'mes' => 8,
            'anio' => 2026,
            'meta_monto' => 10000,
            'meta_clientes' => 5,
            'meta_actividades' => 20,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('crm_presupuestos', [
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 8, 'anio' => 2026, 'meta_monto' => 10000,
        ]);
    }

    public function test_rechaza_crear_sin_permiso_crear(): void
    {
        $this->otorgarPermisosPresupuestos(['ver']);

        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/presupuestos', [
            'vendedor_id' => $this->vendedor->id, 'mes' => 8, 'anio' => 2026, 'meta_monto' => 10000,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('crm_presupuestos', ['vendedor_id' => $this->vendedor->id]);
    }

    public function test_rechaza_un_duplicado_de_vendedor_mes_anio(): void
    {
        $this->otorgarPermisosPresupuestos(['ver', 'crear']);
        CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 8, 'anio' => 2026, 'meta_monto' => 5000,
        ]);

        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/presupuestos', [
            'vendedor_id' => $this->vendedor->id, 'mes' => 8, 'anio' => 2026, 'meta_monto' => 8000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Ya existe un presupuesto para este vendedor en este mes.');
    }

    public function test_edita_metas_con_permiso_editar(): void
    {
        $this->otorgarPermisosPresupuestos(['ver', 'editar']);
        $presupuesto = CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 8, 'anio' => 2026, 'meta_monto' => 5000,
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/presupuestos/{$presupuesto->id}", ['meta_monto' => 7000]);

        $response->assertOk()->assertJsonPath('data.meta_monto', 7000);
    }

    public function test_rechaza_editar_sin_permiso_editar(): void
    {
        $this->otorgarPermisosPresupuestos(['ver']);
        $presupuesto = CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 8, 'anio' => 2026, 'meta_monto' => 5000,
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/presupuestos/{$presupuesto->id}", ['meta_monto' => 7000]);

        $response->assertStatus(403);
    }

    public function test_rechaza_index_sin_permiso_ver(): void
    {
        $this->otorgarPermisosPresupuestos([]);

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/presupuestos?vendedor_id={$this->vendedor->id}&mes=8&anio=2026");

        $response->assertStatus(403);
    }

    public function test_rechaza_resumen_sin_permiso_ver(): void
    {
        $this->otorgarPermisosPresupuestos([]);

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/presupuestos/resumen?vendedor_id={$this->vendedor->id}&mes=8&anio=2026");

        $response->assertStatus(403);
    }

    public function test_rechaza_comparativo_anual_sin_permiso_ver(): void
    {
        $this->otorgarPermisosPresupuestos([]);

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/presupuestos/comparativo-anual?vendedor_id={$this->vendedor->id}&anio=2026");

        $response->assertStatus(403);
    }

    public function test_get_devuelve_null_si_no_existe_presupuesto_ese_mes(): void
    {
        $this->otorgarPermisosPresupuestos(['ver']);
        $vendedorPropio = $this->crearVendedorPropio();

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/presupuestos?vendedor_id={$vendedorPropio->id}&mes=8&anio=2026");

        $response->assertOk()->assertJsonPath('data', null);
    }

    public function test_resumen_devuelve_metas_y_valores_reales_combinados(): void
    {
        $this->otorgarPermisosPresupuestos(['ver']);
        $vendedorPropio = $this->crearVendedorPropio();
        CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $vendedorPropio->id,
            'mes' => 8, 'anio' => 2026, 'meta_monto' => 10000, 'meta_clientes' => 5, 'meta_actividades' => 20,
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/presupuestos/resumen?vendedor_id={$vendedorPropio->id}&mes=8&anio=2026");

        $response->assertOk()
            ->assertJsonPath('data.metaMonto', 10000)
            ->assertJsonPath('data.montoEsperado', 0)
            ->assertJsonPath('data.montoCotizado', 0)
            ->assertJsonPath('data.clientesReales', 0);
    }

    public function test_resumen_devuelve_metas_null_si_no_existe_presupuesto_ese_mes(): void
    {
        $this->otorgarPermisosPresupuestos(['ver']);
        $vendedorPropio = $this->crearVendedorPropio();

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/presupuestos/resumen?vendedor_id={$vendedorPropio->id}&mes=8&anio=2026");

        $response->assertOk()
            ->assertJsonPath('data.presupuestoId', null)
            ->assertJsonPath('data.metaMonto', null)
            ->assertJsonPath('data.metaClientes', null)
            ->assertJsonPath('data.metaActividades', null)
            ->assertJsonPath('data.montoEsperado', 0);
    }

    public function test_comparativo_anual_devuelve_12_meses(): void
    {
        $this->otorgarPermisosPresupuestos(['ver']);
        $vendedorPropio = $this->crearVendedorPropio();

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/presupuestos/comparativo-anual?vendedor_id={$vendedorPropio->id}&anio=2026");

        $response->assertOk()->assertJsonCount(12, 'data');
    }

    public function test_ver_propio_permite_consultar_su_propio_vendedor_id(): void
    {
        $this->otorgarPermisosPresupuestos(['ver']);
        $vendedorPropio = $this->crearVendedorPropio();
        CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $vendedorPropio->id,
            'mes' => 8, 'anio' => 2026, 'meta_monto' => 3000,
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/presupuestos?vendedor_id={$vendedorPropio->id}&mes=8&anio=2026");

        $response->assertOk()->assertJsonPath('data.meta_monto', 3000);
    }

    public function test_ver_propio_rechaza_consultar_vendedor_id_ajeno(): void
    {
        $this->otorgarPermisosPresupuestos(['ver']);
        $this->crearVendedorPropio();
        // $this->vendedor (de la fixture) pertenece a OTRO usuario.

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/presupuestos?vendedor_id={$this->vendedor->id}&mes=8&anio=2026");

        $response->assertStatus(403);
    }

    public function test_ver_sin_ningun_vendedor_propio_rechaza_cualquier_consulta(): void
    {
        $this->otorgarPermisosPresupuestos(['ver']);
        // El actingUser no tiene NINGÚN CrmVendedor propio.

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/presupuestos?vendedor_id={$this->vendedor->id}&mes=8&anio=2026");

        $response->assertStatus(403);
    }

    public function test_con_permiso_crear_puede_consultar_cualquier_vendedor_id(): void
    {
        $this->otorgarPermisosPresupuestos(['ver', 'crear']);
        // El actingUser no tiene un CrmVendedor propio -- igual debe poder
        // ver, porque 'crear' lo marca como gerencia.

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/presupuestos?vendedor_id={$this->vendedor->id}&mes=8&anio=2026");

        $response->assertOk();
    }

    public function test_con_permiso_editar_puede_consultar_cualquier_vendedor_id(): void
    {
        $this->otorgarPermisosPresupuestos(['ver', 'editar']);

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/presupuestos/resumen?vendedor_id={$this->vendedor->id}&mes=8&anio=2026");

        $response->assertOk();
    }

    public function test_crear_con_meta_monto_explicitamente_null_no_causa_error_500(): void
    {
        $this->otorgarPermisosPresupuestos(['ver', 'crear']);

        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/presupuestos', [
            'vendedor_id' => $this->vendedor->id, 'mes' => 9, 'anio' => 2026,
            'meta_monto' => null, 'meta_clientes' => null, 'meta_actividades' => null,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('crm_presupuestos', [
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 9, 'anio' => 2026, 'meta_monto' => 0, 'meta_clientes' => 0, 'meta_actividades' => 0,
        ]);
    }

    public function test_editar_con_meta_monto_explicitamente_null_no_causa_error_500(): void
    {
        $this->otorgarPermisosPresupuestos(['ver', 'editar']);
        $presupuesto = CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 8, 'anio' => 2026, 'meta_monto' => 5000,
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/presupuestos/{$presupuesto->id}", ['meta_monto' => null]);

        $response->assertOk()->assertJsonPath('data.meta_monto', 0);
    }

    public function test_el_constraint_unico_de_bd_rechaza_un_duplicado_a_nivel_modelo(): void
    {
        // Sanity check de que el UNIQUE(empresa_id, vendedor_id, mes, anio)
        // de la migración realmente existe -- es la red de seguridad que el
        // catch(QueryException) de store() depende de que dispare en la
        // ventana de carrera entre el exists() y el create().
        CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 8, 'anio' => 2026, 'meta_monto' => 1000,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 8, 'anio' => 2026, 'meta_monto' => 2000,
        ]);
    }

    public function test_no_ve_presupuestos_de_otra_empresa(): void
    {
        $this->otorgarPermisosPresupuestos(['ver']);
        $otraEmpresa = $this->crearOtraEmpresa();
        $this->otorgarAccesoA($otraEmpresa);
        $vendedorAjeno = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Vendedor ajeno',
        ]);
        $presupuestoAjeno = CrmPresupuesto::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'mes' => 8, 'anio' => 2026, 'meta_monto' => 99999,
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/presupuestos/{$presupuestoAjeno->id}", ['meta_monto' => 1]);

        $response->assertStatus(404);
    }
}
