<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProducto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class CotizacionControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected CrmOportunidad $oportunidad;
    protected CrmProducto $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);

        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);
        $this->oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Oportunidad de prueba',
        ]);
        $this->producto = CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Producto', 'precio' => 250,
        ]);
    }

    private function crearCotizacion(array $overrides = []): array
    {
        $payload = array_merge([
            'fecha_emision' => now()->toDateString(),
            'descuento_global_pct' => 0,
            'lineas' => [
                ['producto_id' => $this->producto->id, 'cantidad' => 2, 'precio_unitario' => 250],
            ],
        ], $overrides);

        return $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$this->oportunidad->id}/cotizaciones", $payload)
            ->json();
    }

    public function test_puede_crear_una_cotizacion_con_lineas_y_el_total_se_calcula_en_el_servidor(): void
    {
        $data = $this->crearCotizacion();

        $this->assertEquals(500.0, (float) $data['data']['subtotal']);
        $this->assertEquals(500.0, (float) $data['data']['total']);
        $this->assertStringStartsWith('COT-', $data['data']['folio']);
    }

    public function test_ignora_un_total_mandado_por_el_cliente_y_usa_el_calculado(): void
    {
        $data = $this->crearCotizacion(['total' => 999999]);

        $this->assertEquals(500.0, (float) $data['data']['total']);
    }

    public function test_aprobar_una_cotizacion_cierra_la_oportunidad_como_cerrado_ganado(): void
    {
        $data = $this->crearCotizacion();
        $cotizacionId = $data['data']['id'];

        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$cotizacionId}/enviar");
        $response = $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$cotizacionId}/aprobar");

        $response->assertOk()->assertJsonPath('data.estado', 'aprobado');
        $this->assertDatabaseHas('crm_oportunidades', [
            'id' => $this->oportunidad->id, 'etapa' => 'cerrado_ganado',
        ]);
        $this->assertNotNull($this->oportunidad->fresh()->fecha_cierre_real);
    }

    public function test_al_aprobar_una_cotizacion_nueva_la_anterior_aprobada_pasa_a_superado(): void
    {
        $primera = $this->crearCotizacion();
        $primeraId = $primera['data']['id'];
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$primeraId}/enviar");
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$primeraId}/aprobar");

        $segunda = $this->crearCotizacion();
        $segundaId = $segunda['data']['id'];
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$segundaId}/enviar");
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$segundaId}/aprobar");

        $this->assertDatabaseHas('crm_cotizaciones', ['id' => $primeraId, 'estado' => 'superado']);
        $this->assertDatabaseHas('crm_cotizaciones', ['id' => $segundaId, 'estado' => 'aprobado']);
    }

    public function test_rechaza_un_descuento_global_distinto_de_cero_si_la_empresa_lo_tiene_deshabilitado(): void
    {
        \App\Models\CRM\CrmConfiguracionComercial::create([
            'empresa_id' => $this->enterprise->id, 'descuento_global_habilitado' => false,
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$this->oportunidad->id}/cotizaciones", [
                'fecha_emision' => now()->toDateString(),
                'descuento_global_pct' => 10,
                'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1, 'precio_unitario' => 250]],
            ]);

        $response->assertStatus(422);
    }
}
