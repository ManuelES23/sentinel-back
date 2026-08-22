<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class OportunidadControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const BASE_URL = '/api/crm/oportunidades';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function crearCliente(): CrmCliente
    {
        return CrmCliente::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Cliente de prueba',
            'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);
    }

    private function crearProspecto(): CrmProspecto
    {
        return CrmProspecto::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Prospecto de prueba',
            'estatus' => 'nuevo',
            'vendedor_id' => $this->vendedor->id,
        ]);
    }

    public function test_puede_crear_una_oportunidad_sobre_un_cliente(): void
    {
        $cliente = $this->crearCliente();

        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Reorden temporada otoño',
            'monto_esperado' => 50000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.nombre', 'Reorden temporada otoño')
            ->assertJsonPath('data.etapa', 'prospecto');
    }

    public function test_puede_crear_una_oportunidad_sobre_un_prospecto(): void
    {
        $prospecto = $this->crearProspecto();

        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'prospecto_id' => $prospecto->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Primera compra',
        ]);

        $response->assertCreated()->assertJsonPath('data.prospecto_id', $prospecto->id);
    }

    public function test_rechaza_crear_una_oportunidad_sin_prospecto_ni_cliente(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Oportunidad huérfana',
        ]);

        $response->assertStatus(422);
    }

    public function test_rechaza_crear_una_oportunidad_con_prospecto_y_cliente_a_la_vez(): void
    {
        $cliente = $this->crearCliente();
        $prospecto = $this->crearProspecto();

        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'cliente_id' => $cliente->id,
            'prospecto_id' => $prospecto->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Oportunidad ambigua',
        ]);

        $response->assertStatus(422);
    }

    public function test_el_listado_solo_incluye_oportunidades_de_la_empresa_del_contexto(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $cliente = $this->crearCliente();

        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Propia',
        ]);
        CrmOportunidad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'Ajena',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL);

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Propia');
    }
}
