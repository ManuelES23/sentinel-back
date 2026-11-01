<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProducto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Calibre y presentación por renglón de la cotización (cotización por caja). */
class CotizacionPorCajaControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected CrmOportunidad $oportunidad;
    protected CrmProducto $producto;
    protected CrmCliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();

        $this->cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente Uno', 'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);
        $this->oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Oportunidad de prueba',
        ]);
        $this->producto = CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Aguacate Hass', 'commodity' => 'Aguacate', 'precio' => 250,
        ]);
    }

    private function enviar(array $lineas, ?int $cotizacionId = null): TestResponse
    {
        $payload = [
            'fecha_emision' => now()->toDateString(),
            'descuento_global_pct' => 0,
            'lineas' => $lineas,
        ];
        $headers = $this->withHeaders($this->crmHeaders());

        return $cotizacionId
            ? $headers->putJson("/api/crm/cotizaciones/{$cotizacionId}", $payload)
            : $headers->postJson("/api/crm/oportunidades/{$this->oportunidad->id}/cotizaciones", $payload);
    }

    private function crear(array $lineas): array
    {
        return $this->enviar($lineas)->json();
    }

    public function test_guarda_calibre_y_presentacion_por_renglon(): void
    {
        $data = $this->crear([['producto_id' => $this->producto->id, 'calibre' => 'Calibre 8', 'presentacion' => 'Caja cartón 8.85 LBS B15', 'cantidad' => 480, 'precio_unitario' => 7.5]]);

        $this->assertDatabaseHas('crm_oportunidad_productos', ['calibre' => 'Calibre 8', 'presentacion' => 'Caja cartón 8.85 LBS B15', 'descripcion' => 'Aguacate Hass']);
        $this->assertEquals(3600.0, (float) $data['data']['total']);
    }

    public function test_una_pestana_vieja_sin_los_campos_sigue_funcionando(): void
    {
        $response = $this->enviar([['producto_id' => $this->producto->id, 'cantidad' => 2, 'precio_unitario' => 250]]);

        $response->assertStatus(201);
        $this->assertEquals(500.0, (float) $response->json('data.total'));
        $this->assertDatabaseHas('crm_oportunidad_productos', ['calibre' => null, 'presentacion' => null]);
    }

    public function test_acepta_un_calibre_que_no_esta_en_el_catalogo(): void
    {
        $this->enviar([['producto_id' => $this->producto->id, 'calibre' => 'Calibre inventado 99', 'presentacion' => 'Bolsa rara', 'cantidad' => 1, 'precio_unitario' => 10]])
            ->assertStatus(201);

        $this->assertDatabaseHas('crm_oportunidad_productos', ['calibre' => 'Calibre inventado 99', 'presentacion' => 'Bolsa rara']);
    }

    public function test_texto_vacio_o_con_espacios_se_guarda_como_null(): void
    {
        $this->enviar([
            ['producto_id' => $this->producto->id, 'calibre' => '   ', 'presentacion' => '', 'cantidad' => 1, 'precio_unitario' => 10],
            ['producto_id' => $this->producto->id, 'calibre' => '  Calibre 10  ', 'presentacion' => null, 'cantidad' => 1, 'precio_unitario' => 10],
        ])->assertStatus(201);

        $this->assertDatabaseHas('crm_oportunidad_productos', ['calibre' => null, 'presentacion' => null]);
        $this->assertDatabaseHas('crm_oportunidad_productos', ['calibre' => 'Calibre 10', 'presentacion' => null]);
        $this->assertDatabaseMissing('crm_oportunidad_productos', ['calibre' => '  Calibre 10  ']);
    }

    public function test_rechaza_textos_demasiado_largos(): void
    {
        $this->enviar([['producto_id' => $this->producto->id, 'calibre' => str_repeat('a', 101), 'cantidad' => 1, 'precio_unitario' => 10]])
            ->assertStatus(422)->assertJsonValidationErrors(['lineas.0.calibre']);
        $this->enviar([['producto_id' => $this->producto->id, 'presentacion' => str_repeat('a', 151), 'cantidad' => 1, 'precio_unitario' => 10]])
            ->assertStatus(422)->assertJsonValidationErrors(['lineas.0.presentacion']);

        $this->enviar([['producto_id' => $this->producto->id, 'calibre' => str_repeat('a', 100), 'presentacion' => str_repeat('b', 150), 'cantidad' => 1, 'precio_unitario' => 10]])
            ->assertStatus(201);
    }

    public function test_un_valor_que_no_es_texto_responde_422_y_no_500(): void
    {
        $this->enviar([['producto_id' => $this->producto->id, 'calibre' => ['x'], 'cantidad' => 1, 'precio_unitario' => 10]])
            ->assertStatus(422)->assertJsonValidationErrors(['lineas.0.calibre']);
        $this->enviar([['producto_id' => $this->producto->id, 'presentacion' => ['x'], 'cantidad' => 1, 'precio_unitario' => 10]])
            ->assertStatus(422)->assertJsonValidationErrors(['lineas.0.presentacion']);
    }

    public function test_editar_un_borrador_conserva_los_campos(): void
    {
        $id = $this->crear([['producto_id' => $this->producto->id, 'calibre' => 'Calibre 8', 'presentacion' => 'Caja A', 'cantidad' => 2, 'precio_unitario' => 10]])['data']['id'];

        $this->enviar([['producto_id' => $this->producto->id, 'calibre' => ' Calibre 12 ', 'presentacion' => 'Caja B', 'cantidad' => 3, 'precio_unitario' => 10]], $id)
            ->assertOk();

        $cotizacion = CrmCotizacion::findOrFail($id);
        $this->assertCount(1, $cotizacion->lineas);
        $this->assertSame('Calibre 12', $cotizacion->lineas->first()->calibre);
        $this->assertSame('Caja B', $cotizacion->lineas->first()->presentacion);
        $this->assertEquals(30.0, (float) $cotizacion->total);

        // Editar solo la cabecera (sin lineas) no toca los renglones.
        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/cotizaciones/{$id}", ['notas' => 'solo notas'])->assertOk();
        $this->assertSame('Calibre 12', $cotizacion->fresh()->lineas()->first()->calibre);
    }

    public function test_el_detalle_trae_commodity_cliente_y_empresa(): void
    {
        $this->enterprise->update(['razon_social' => 'Splendid SA de CV', 'direccion' => 'Calle 1', 'ciudad' => 'Uruapan', 'pais' => 'México', 'telefono' => '555']);
        $id = $this->crear([['producto_id' => $this->producto->id, 'calibre' => 'Calibre 8', 'presentacion' => 'Caja A', 'cantidad' => 2, 'precio_unitario' => 10]])['data']['id'];

        $response = $this->withHeaders($this->crmHeaders())->getJson("/api/crm/cotizaciones/{$id}");

        $response->assertOk()
            ->assertJsonPath('data.lineas.0.producto.commodity', 'Aguacate')
            ->assertJsonPath('data.lineas.0.calibre', 'Calibre 8')
            ->assertJsonPath('data.lineas.0.presentacion', 'Caja A')
            ->assertJsonPath('data.oportunidad.cliente.nombre', 'Cliente Uno')
            ->assertJsonPath('data.oportunidad.vendedor.nombre', 'Juan Pérez')
            ->assertJsonPath('data.empresa.razon_social', 'Splendid SA de CV')
            ->assertJsonPath('data.empresa.ciudad', 'Uruapan')
            ->assertJsonPath('data.empresa.name', 'Splendid Farms');
        $this->assertArrayHasKey('impuestos', $response->json('data'));
    }

    public function test_el_detalle_de_una_oportunidad_borrada_no_falla(): void
    {
        $id = $this->crear([['producto_id' => $this->producto->id, 'cantidad' => 2, 'precio_unitario' => 10]])['data']['id'];
        $this->oportunidad->delete();

        $response = $this->withHeaders($this->crmHeaders())->getJson("/api/crm/cotizaciones/{$id}");

        $response->assertOk()->assertJsonPath('data.id', $id);
        $this->assertNull($response->json('data.oportunidad'));
    }
}
