<?php
// sentinel-back/tests/Feature/CRM/ConfiguracionComercialControllerTest.php

namespace Tests\Feature\CRM;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class ConfiguracionComercialControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const BASE_URL = '/api/crm/configuracion-comercial';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_ver_la_configuracion_la_crea_con_default_true_si_no_existe(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL);

        $response->assertOk()
            ->assertJsonPath('data.descuento_global_habilitado', true)
            ->assertJsonPath('data.impuestos', []);

        $this->assertDatabaseHas('crm_configuraciones_comerciales', [
            'empresa_id' => $this->enterprise->id,
            'descuento_global_habilitado' => true,
        ]);
    }

    public function test_puede_desactivar_el_descuento_global(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL, [
            'descuento_global_habilitado' => false,
        ]);

        $response->assertOk()->assertJsonPath('data.descuento_global_habilitado', false);
    }

    public function test_puede_crear_y_listar_un_impuesto(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL.'/impuestos', [
            'nombre' => 'IVA',
            'tasa' => 16,
        ]);
        $response->assertCreated()->assertJsonPath('data.nombre', 'IVA');

        $listado = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL);
        $listado->assertOk()->assertJsonCount(1, 'data.impuestos');
    }

    public function test_la_configuracion_es_independiente_por_empresa(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();

        $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL, ['descuento_global_habilitado' => false]);
        $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL.'/impuestos', ['nombre' => 'IVA', 'tasa' => 16]);

        $respuestaOtraEmpresa = $this->withHeaders($this->crmHeaders($otraEmpresa->id))->getJson(self::BASE_URL);

        $respuestaOtraEmpresa->assertOk()
            ->assertJsonPath('data.descuento_global_habilitado', true)
            ->assertJsonPath('data.impuestos', []);
    }
}
