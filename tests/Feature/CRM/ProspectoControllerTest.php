<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmProspecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class ProspectoControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const BASE_URL = '/api/crm/prospectos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        // Este archivo prueba el comportamiento, no la autorización (ver CrmPermisosEnforcementTest).
        $this->otorgarTodosLosPermisosCrm();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Rancho El Fresno',
            'email' => 'contacto@ranchoelfresno.mx',
            'vendedor_id' => $this->vendedor->id,
            'region_id' => $this->region->id,
        ], $overrides);
    }

    public function test_puede_listar_prospectos_de_la_empresa_del_contexto(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        CrmProspecto::create($this->validPayload(['empresa_id' => $this->enterprise->id]));
        CrmProspecto::create($this->validPayload([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => null, 'region_id' => null,
        ]));

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL);

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_puede_crear_un_prospecto_con_estatus_por_defecto(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->validPayload());

        $response->assertCreated()->assertJsonPath('data.estatus', 'nuevo');
        $this->assertDatabaseHas('crm_prospectos', [
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Rancho El Fresno',
        ]);
    }

    public function test_puede_actualizar_un_prospecto(): void
    {
        $prospecto = CrmProspecto::create($this->validPayload(['empresa_id' => $this->enterprise->id]));

        $response = $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL."/{$prospecto->id}", [
            'estatus' => 'contactado',
        ]);

        $response->assertOk()->assertJsonPath('data.estatus', 'contactado');
    }

    public function test_no_puede_actualizar_un_prospecto_de_otra_empresa(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $prospecto = CrmProspecto::create($this->validPayload([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => null, 'region_id' => null,
        ]));

        $response = $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL."/{$prospecto->id}", [
            'estatus' => 'contactado',
        ]);

        $response->assertStatus(404);
    }

    public function test_convertir_a_cliente_crea_el_cliente_y_marca_el_prospecto_como_calificado(): void
    {
        $prospecto = CrmProspecto::create($this->validPayload(['empresa_id' => $this->enterprise->id]));

        $response = $this->withHeaders($this->crmHeaders())
            ->postJson(self::BASE_URL."/{$prospecto->id}/convertir-cliente");

        $response->assertCreated()
            ->assertJsonPath('data.nombre', 'Rancho El Fresno')
            ->assertJsonPath('data.prospecto.id', $prospecto->id);

        $this->assertDatabaseHas('crm_clientes', [
            'prospecto_id' => $prospecto->id,
            'vendedor_id' => $this->vendedor->id,
            'estatus' => 'activo',
        ]);
        $this->assertDatabaseHas('crm_prospectos', ['id' => $prospecto->id, 'estatus' => 'calificado']);
        // Debe quedar registrada la actividad automática de conversión
        $this->assertDatabaseHas('crm_actividades', ['entidad_type' => \App\Models\CRM\CrmCliente::class]);
    }

    public function test_puede_reasignar_vendedor_de_un_prospecto(): void
    {
        $prospecto = CrmProspecto::create($this->validPayload(['empresa_id' => $this->enterprise->id, 'vendedor_id' => null]));

        $response = $this->withHeaders($this->crmHeaders())
            ->patchJson(self::BASE_URL."/{$prospecto->id}/asignar-vendedor", ['vendedor_id' => $this->vendedor->id]);

        $response->assertOk()->assertJsonPath('data.vendedor.id', $this->vendedor->id);
    }

    public function test_puede_eliminar_un_prospecto_con_soft_delete(): void
    {
        $prospecto = CrmProspecto::create($this->validPayload(['empresa_id' => $this->enterprise->id]));

        $response = $this->withHeaders($this->crmHeaders())->deleteJson(self::BASE_URL."/{$prospecto->id}");

        $response->assertOk();
        $this->assertSoftDeleted('crm_prospectos', ['id' => $prospecto->id]);
    }
}
