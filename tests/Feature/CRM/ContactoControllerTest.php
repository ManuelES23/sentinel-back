<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmContacto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class ContactoControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const BASE_URL = '/api/crm/contactos';

    protected CrmCliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        // Este archivo prueba el comportamiento, no la autorización (ver CrmPermisosEnforcementTest).
        $this->otorgarTodosLosPermisosCrm();
        $this->cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente de prueba']);
    }

    public function test_puede_crear_un_contacto_para_un_cliente(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'entidad_tipo' => 'cliente',
            'entidad_id' => $this->cliente->id,
            'nombre' => 'María López',
            'email' => 'maria@example.com',
        ]);

        $response->assertCreated()->assertJsonPath('data.entidad_tipo', 'cliente');
        $this->assertDatabaseHas('crm_contactos', [
            'entidad_type' => CrmCliente::class,
            'entidad_id' => $this->cliente->id,
            'nombre' => 'María López',
        ]);
    }

    public function test_rechaza_una_entidad_que_no_pertenece_a_la_empresa(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $clienteAjeno = CrmCliente::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Ajeno']);

        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'entidad_tipo' => 'cliente',
            'entidad_id' => $clienteAjeno->id,
            'nombre' => 'Intruso',
        ]);

        $response->assertStatus(404);
    }

    public function test_marcar_un_contacto_como_principal_desmarca_a_los_demas_de_la_misma_entidad(): void
    {
        $principalActual = CrmContacto::create([
            'empresa_id' => $this->enterprise->id, 'entidad_type' => CrmCliente::class,
            'entidad_id' => $this->cliente->id, 'nombre' => 'Contacto A', 'es_principal' => true,
        ]);

        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'entidad_tipo' => 'cliente',
            'entidad_id' => $this->cliente->id,
            'nombre' => 'Contacto B',
            'es_principal' => true,
        ]);

        $response->assertCreated()->assertJsonPath('data.es_principal', true);
        $this->assertDatabaseHas('crm_contactos', ['id' => $principalActual->id, 'es_principal' => false]);
    }

    public function test_puede_eliminar_un_contacto(): void
    {
        $contacto = CrmContacto::create([
            'empresa_id' => $this->enterprise->id, 'entidad_type' => CrmCliente::class,
            'entidad_id' => $this->cliente->id, 'nombre' => 'Contacto a borrar',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->deleteJson(self::BASE_URL."/{$contacto->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('crm_contactos', ['id' => $contacto->id]);
    }
}
