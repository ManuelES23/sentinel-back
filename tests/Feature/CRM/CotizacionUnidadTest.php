<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmMarca;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProducto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class CotizacionUnidadTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private CrmOportunidad $oportunidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();

        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);
        $this->oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Oportunidad',
        ]);
    }

    private function producto(array $extra = []): CrmProducto
    {
        return CrmProducto::create($extra + [
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Producto', 'precio' => 10,
            'unidad_medida' => 'litro',
        ]);
    }

    private function cotizar(CrmProducto $producto): int
    {
        return $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$this->oportunidad->id}/cotizaciones", [
                'fecha_emision' => now()->toDateString(),
                'descuento_global_pct' => 0,
                'lineas' => [['producto_id' => $producto->id, 'cantidad' => 2, 'precio_unitario' => 10]],
            ])->assertCreated()->json('data.id');
    }

    public function test_el_renglon_copia_la_unidad_del_producto(): void
    {
        $id = $this->cotizar($this->producto());

        $this->assertDatabaseHas('crm_oportunidad_productos', ['cotizacion_id' => $id, 'unidad' => 'litro']);
    }

    public function test_el_detalle_trae_clave_marca_y_unidad(): void
    {
        $marca = CrmMarca::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Marca X']);
        $id = $this->cotizar($this->producto(['clave' => 'AB-1', 'marca_id' => $marca->id]));

        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/cotizaciones/{$id}")
            ->assertOk()
            ->assertJsonPath('data.lineas.0.unidad', 'litro')
            ->assertJsonPath('data.lineas.0.producto.clave', 'AB-1')
            ->assertJsonPath('data.lineas.0.producto.activo', true)
            ->assertJsonPath('data.lineas.0.producto.marca.id', $marca->id)
            ->assertJsonPath('data.lineas.0.producto.marca.nombre', 'Marca X');
    }

    public function test_producto_desactivado_sigue_en_el_detalle(): void
    {
        $producto = $this->producto(['clave' => 'AB-2']);
        $id = $this->cotizar($producto);
        $producto->update(['activo' => false]);

        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/cotizaciones/{$id}")
            ->assertOk()
            ->assertJsonPath('data.lineas.0.producto.id', $producto->id)
            ->assertJsonPath('data.lineas.0.producto.clave', 'AB-2')
            ->assertJsonPath('data.lineas.0.producto.activo', false);
    }
}
