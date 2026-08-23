<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\OportunidadUpdated;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProducto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    // --- Etapas terminales: una oportunidad perdida no se reabre vía cotización ---

    private function marcarOportunidadPerdida(): void
    {
        $this->oportunidad->update([
            'etapa' => 'cerrado_perdido',
            'motivo_perdida' => 'Se fue con la competencia',
            'fecha_cierre_real' => now(),
        ]);
    }

    public function test_rechaza_crear_una_cotizacion_sobre_una_oportunidad_cerrada_como_perdida(): void
    {
        $this->marcarOportunidadPerdida();

        $response = $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$this->oportunidad->id}/cotizaciones", [
                'fecha_emision' => now()->toDateString(),
                'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1, 'precio_unitario' => 250]],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('crm_cotizaciones', 0);
    }

    public function test_rechaza_aprobar_una_cotizacion_de_una_oportunidad_cerrada_como_perdida(): void
    {
        $data = $this->crearCotizacion();
        $cotizacionId = $data['data']['id'];
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$cotizacionId}/enviar");

        // La oportunidad se pierde DESPUÉS de que la cotización ya estaba enviada.
        $this->marcarOportunidadPerdida();

        $response = $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$cotizacionId}/aprobar");

        $response->assertStatus(422);

        // Nada mutó: ni la cotización ni el cierre de la oportunidad.
        $this->assertDatabaseHas('crm_cotizaciones', ['id' => $cotizacionId, 'estado' => 'enviado']);
        $fresca = $this->oportunidad->fresh();
        $this->assertEquals('cerrado_perdido', $fresca->etapa);
        $this->assertEquals('Se fue con la competencia', $fresca->motivo_perdida);
    }

    public function test_aprobar_emite_el_evento_de_oportunidad_actualizada(): void
    {
        Event::fake([OportunidadUpdated::class]);

        $data = $this->crearCotizacion();
        $cotizacionId = $data['data']['id'];
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$cotizacionId}/enviar");
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$cotizacionId}/aprobar")->assertOk();

        Event::assertDispatched(
            OportunidadUpdated::class,
            fn (OportunidadUpdated $e) => $e->action === 'updated'
                && $e->data['id'] === $this->oportunidad->id
                && $e->data['etapa'] === 'cerrado_ganado'
                && (int) $e->data['empresa_id'] === $this->enterprise->id
        );
    }

    // --- Aislamiento multi-tenant en las FK de las líneas ---

    public function test_rechaza_crear_una_cotizacion_con_un_producto_de_otra_empresa(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $productoAjeno = CrmProducto::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Producto secreto ajeno', 'precio' => 999,
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$this->oportunidad->id}/cotizaciones", [
                'fecha_emision' => now()->toDateString(),
                'lineas' => [['producto_id' => $productoAjeno->id, 'cantidad' => 1, 'precio_unitario' => 100]],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('lineas.0.producto_id');
        $this->assertDatabaseCount('crm_cotizaciones', 0);
    }

    public function test_rechaza_actualizar_una_cotizacion_con_un_producto_de_otra_empresa(): void
    {
        $cotizacionId = $this->crearCotizacion()['data']['id'];
        $otraEmpresa = $this->crearOtraEmpresa();
        $productoAjeno = CrmProducto::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Producto secreto ajeno', 'precio' => 999,
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/cotizaciones/{$cotizacionId}", [
                'lineas' => [['producto_id' => $productoAjeno->id, 'cantidad' => 1, 'precio_unitario' => 100]],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('lineas.0.producto_id');
    }

    // --- descuento_global_pct nullable vs. columna NOT NULL ---

    public function test_actualizar_con_descuento_global_pct_nulo_conserva_el_valor_previo(): void
    {
        $cotizacionId = $this->crearCotizacion(['descuento_global_pct' => 10])['data']['id'];

        $response = $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/cotizaciones/{$cotizacionId}", [
                'notas' => 'Solo cambio la nota',
                'descuento_global_pct' => null,
            ]);

        $response->assertOk();
        $this->assertEquals(10.0, (float) $response->json('data.descuento_global_pct'));
        $this->assertDatabaseHas('crm_cotizaciones', ['id' => $cotizacionId, 'notas' => 'Solo cambio la nota']);
    }

    public function test_con_el_descuento_deshabilitado_se_puede_seguir_editando_un_borrador_ya_descontado(): void
    {
        $cotizacionId = $this->crearCotizacion(['descuento_global_pct' => 10])['data']['id'];

        // La empresa deshabilita el descuento DESPUÉS de que el borrador ya lo traía.
        \App\Models\CRM\CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)
            ->update(['descuento_global_habilitado' => false]);

        // Editar otra cosa (sin tocar el descuento) sigue permitido.
        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/cotizaciones/{$cotizacionId}", ['notas' => 'Corrijo un typo'])
            ->assertOk();

        // Bajarlo también.
        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/cotizaciones/{$cotizacionId}", ['descuento_global_pct' => 5])
            ->assertOk();

        // Pero subirlo no.
        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/cotizaciones/{$cotizacionId}", ['descuento_global_pct' => 20])
            ->assertStatus(422);

        $this->assertEquals(5.0, (float) CrmCotizacion::find($cotizacionId)->descuento_global_pct);
    }

    // --- Folio consecutivo por empresa ---

    public function test_el_consecutivo_de_folio_es_independiente_por_empresa(): void
    {
        $this->crearCotizacion();
        $this->crearCotizacion();

        // Segunda empresa, con su propia oportunidad y producto.
        $otraEmpresa = $this->crearOtraEmpresa();
        $otraOportunidad = CrmOportunidad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Oportunidad ajena',
        ]);
        $otroProducto = CrmProducto::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Producto ajeno', 'precio' => 100,
        ]);

        $respuesta = $this->withHeaders($this->crmHeaders($otraEmpresa->id))
            ->postJson("/api/crm/oportunidades/{$otraOportunidad->id}/cotizaciones", [
                'fecha_emision' => now()->toDateString(),
                'lineas' => [['producto_id' => $otroProducto->id, 'cantidad' => 1, 'precio_unitario' => 100]],
            ]);

        // El folio de la otra empresa arranca en 1, no continúa el consecutivo ajeno.
        $respuesta->assertCreated()->assertJsonPath('data.folio', 'COT-00001');
        $this->assertDatabaseHas('crm_cotizaciones', [
            'empresa_id' => $this->enterprise->id, 'folio' => 'COT-00002',
        ]);
    }
}
