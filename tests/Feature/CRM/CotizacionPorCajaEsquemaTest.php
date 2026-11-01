<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmProducto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class CotizacionPorCajaEsquemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();
    }

    public function test_la_configuracion_expone_cotizacion_por_caja_apagada_por_defecto(): void
    {
        $res = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/configuracion-comercial');
        $res->assertOk()->assertJsonPath('data.cotizacion_por_caja', false);
    }

    public function test_put_de_configuracion_no_permite_encender_el_interruptor(): void
    {
        $this->withHeaders($this->crmHeaders())
            ->putJson('/api/crm/configuracion-comercial', ['moneda' => 'USD', 'cotizacion_por_caja' => true])
            ->assertOk();
        $this->assertFalse(CrmConfiguracionComercial::cotizacionPorCaja($this->enterprise->id));
    }

    public function test_un_producto_borrado_se_lleva_sus_calibres_propios(): void
    {
        $producto = CrmProducto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Kent', 'precio' => 0]);
        $producto->calibresPropios()->create(['nombre' => 'Calibre 8']);
        $producto->delete();
        $this->assertDatabaseCount('crm_producto_calibres', 0);
    }
}
