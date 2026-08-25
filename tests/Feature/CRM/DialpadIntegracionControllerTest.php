<?php
// tests/Feature/CRM/DialpadIntegracionControllerTest.php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmDialpadSyncEstado;
use App\Models\CRM\CrmVendedor;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class DialpadIntegracionControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function otorgarPermisoDialpad(array $slugs): void
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $this->enterprise->id, 'slug' => 'crm'],
            ['name' => 'CRM Comercial', 'description' => 'CRM Comercial', 'path' => '/'.$this->enterprise->slug.'/crm', 'is_active' => true],
        );
        $modulo = Module::firstOrCreate(
            ['application_id' => $app->id, 'slug' => 'integraciones'],
            ['name' => 'Integraciones', 'order' => 1, 'is_active' => true],
        );
        $submodulo = Submodule::firstOrCreate(
            ['module_id' => $modulo->id, 'slug' => 'dialpad'],
            ['name' => 'Dialpad', 'order' => 1, 'is_active' => true],
        );

        foreach ($slugs as $i => $slug) {
            $tipo = SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $submodulo->id, 'slug' => $slug],
                ['name' => ucfirst($slug), 'order' => $i + 1, 'is_active' => true],
            );

            UserSubmodulePermission::create([
                'user_id' => $this->actingUser->id,
                'submodule_id' => $submodulo->id,
                'permission_type_id' => $tipo->id,
                'is_granted' => true,
            ]);
        }
    }

    private function crearLlamada(array $overrides = []): CrmActividad
    {
        return CrmActividad::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'tipo' => 'llamada',
            'vendedor_id' => $this->vendedor->id,
            'descripcion' => 'Llamada entrante de Dialpad (6621234567) — 4 min',
            'fecha_actividad' => now(),
            'duracion_minutos' => 4,
            'fuente' => 'dialpad',
            'dialpad_call_id' => 'call-'.uniqid(),
        ], $overrides));
    }

    public function test_listar_sin_permiso_responde_403(): void
    {
        $response = $this->getJson('/api/crm/integraciones/dialpad/llamadas', $this->crmHeaders());
        $response->assertStatus(403);
    }

    public function test_ver_only_sin_vendedor_id_queda_forzado_a_su_propio_vendedor(): void
    {
        $this->otorgarPermisoDialpad(['ver']);

        // Vincula el vendedor de fixtures al usuario autenticado para que
        // "su propio vendedor" sea $this->vendedor.
        $this->vendedor->update(['user_id' => $this->actingUser->id]);

        $propia = $this->crearLlamada(['vendedor_id' => $this->vendedor->id]);
        $otroVendedor = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Otro vendedor',
            'email' => 'otro@example.com',
        ]);
        $this->crearLlamada(['vendedor_id' => $otroVendedor->id]);

        $response = $this->getJson('/api/crm/integraciones/dialpad/llamadas', $this->crmHeaders());

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $propia->id);
    }

    public function test_ver_only_pidiendo_el_vendedor_de_otro_responde_403(): void
    {
        $this->otorgarPermisoDialpad(['ver']);
        $this->vendedor->update(['user_id' => $this->actingUser->id]);

        $otroVendedor = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Otro vendedor',
            'email' => 'otro@example.com',
        ]);

        $response = $this->getJson(
            '/api/crm/integraciones/dialpad/llamadas?vendedor_id='.$otroVendedor->id,
            $this->crmHeaders(),
        );

        $response->assertStatus(403);
    }

    public function test_gerencia_ve_las_llamadas_de_todos_los_vendedores(): void
    {
        $this->otorgarPermisoDialpad(['sync', 'ver', 'editar']);

        $otroVendedor = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Otro vendedor',
            'email' => 'otro@example.com',
        ]);
        $this->crearLlamada(['vendedor_id' => $this->vendedor->id]);
        $this->crearLlamada(['vendedor_id' => $otroVendedor->id]);

        $response = $this->getJson('/api/crm/integraciones/dialpad/llamadas', $this->crmHeaders());

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_clasificar_actualiza_entidad_y_resultado(): void
    {
        $this->otorgarPermisoDialpad(['editar']);
        $llamada = $this->crearLlamada();
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Cliente Fake',
            'telefono' => '6621234567',
            'estatus' => 'activo',
        ]);

        $response = $this->patchJson(
            "/api/crm/integraciones/dialpad/llamadas/{$llamada->id}/clasificar",
            ['entidad_tipo' => 'cliente', 'entidad_id' => $cliente->id, 'resultado' => 'Interesado'],
            $this->crmHeaders(),
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('crm_actividades', [
            'id' => $llamada->id,
            'entidad_type' => CrmCliente::class,
            'entidad_id' => $cliente->id,
            'resultado' => 'Interesado',
        ]);
    }

    public function test_clasificar_sin_permiso_editar_responde_403(): void
    {
        $llamada = $this->crearLlamada();

        $response = $this->patchJson(
            "/api/crm/integraciones/dialpad/llamadas/{$llamada->id}/clasificar",
            ['resultado' => 'Interesado'],
            $this->crmHeaders(),
        );

        $response->assertStatus(403);
    }

    public function test_sincronizar_sin_permiso_sync_responde_403(): void
    {
        $response = $this->postJson('/api/crm/integraciones/dialpad/sincronizar', [], $this->crmHeaders());
        $response->assertStatus(403);
    }

    public function test_llamadas_de_otra_empresa_no_se_filtran_ni_se_pueden_clasificar(): void
    {
        $this->otorgarPermisoDialpad(['sync', 'ver', 'editar']);

        $propia = $this->crearLlamada(['vendedor_id' => $this->vendedor->id]);

        $otraEmpresa = $this->crearOtraEmpresa();
        $otroVendedor = CrmVendedor::create([
            'empresa_id' => $otraEmpresa->id,
            'nombre' => 'Vendedor de otra empresa',
            'email' => 'vendedor.otra@example.com',
        ]);
        $llamadaAjena = CrmActividad::create([
            'empresa_id' => $otraEmpresa->id,
            'tipo' => 'llamada',
            'vendedor_id' => $otroVendedor->id,
            'descripcion' => 'Llamada entrante de Dialpad (5551234567) — 3 min',
            'fecha_actividad' => now(),
            'duracion_minutos' => 3,
            'fuente' => 'dialpad',
            'dialpad_call_id' => 'call-ajena-'.uniqid(),
        ]);

        $response = $this->getJson('/api/crm/integraciones/dialpad/llamadas', $this->crmHeaders());
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $propia->id);

        $patchResponse = $this->patchJson(
            "/api/crm/integraciones/dialpad/llamadas/{$llamadaAjena->id}/clasificar",
            ['resultado' => 'Interesado'],
            $this->crmHeaders(),
        );
        $patchResponse->assertStatus(404);
    }

    public function test_estado_devuelve_la_forma_correcta(): void
    {
        $this->otorgarPermisoDialpad(['ver']);
        CrmDialpadSyncEstado::obtenerSingleton()->update([
            'ultimo_sync_at' => now(),
            'ultimo_error' => null,
        ]);

        $response = $this->getJson('/api/crm/integraciones/dialpad/estado', $this->crmHeaders());

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['ultimoSync', 'ultimoError']]);
    }
}
