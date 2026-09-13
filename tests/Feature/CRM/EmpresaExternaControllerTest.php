<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmEmpresaExterna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class EmpresaExternaControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const BASE_URL = '/api/crm/empresas-externas';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        // Este archivo prueba el comportamiento, no la autorización (ver CrmPermisosEnforcementTest).
        $this->otorgarTodosLosPermisosCrm();
    }

    public function test_puede_crear_una_empresa_externa(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'razon_social' => 'Distribuidora del Pacífico S.A. de C.V.',
            'rfc' => 'DPA010101ABC',
            'industria' => 'Logística',
        ]);

        $response->assertCreated()->assertJsonPath('data.razon_social', 'Distribuidora del Pacífico S.A. de C.V.');
    }

    public function test_el_listado_solo_incluye_empresas_de_la_empresa_del_contexto(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        CrmEmpresaExterna::create(['empresa_id' => $this->enterprise->id, 'razon_social' => 'Propia']);
        CrmEmpresaExterna::create(['empresa_id' => $otraEmpresa->id, 'razon_social' => 'Ajena']);

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL);

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.razon_social', 'Propia');
    }

    public function test_al_eliminar_una_empresa_externa_tambien_se_borran_sus_contactos(): void
    {
        $empresaExterna = CrmEmpresaExterna::create(['empresa_id' => $this->enterprise->id, 'razon_social' => 'Con contactos']);
        $contacto = $empresaExterna->contactos()->create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Contacto asociado',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->deleteJson(self::BASE_URL."/{$empresaExterna->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('crm_empresas_externas', ['id' => $empresaExterna->id]);
        $this->assertDatabaseMissing('crm_contactos', ['id' => $contacto->id]);
    }
}
