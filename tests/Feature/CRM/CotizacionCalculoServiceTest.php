<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionImpuesto;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadProducto;
use App\Models\CRM\CrmProducto;
use App\Services\CRM\CotizacionCalculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class CotizacionCalculoServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    private function crearCotizacionConLineas(float $descuentoPct = 0): CrmCotizacion
    {
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);
        $oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Oportunidad',
        ]);
        $producto = CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Producto A', 'precio' => 100,
        ]);
        $cotizacion = CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidad->id,
            'folio' => 'COT-00001', 'estado' => 'borrador', 'fecha_emision' => now(),
            'descuento_global_pct' => $descuentoPct,
        ]);
        CrmOportunidadProducto::create([
            'oportunidad_id' => $oportunidad->id, 'cotizacion_id' => $cotizacion->id,
            'producto_id' => $producto->id, 'descripcion' => 'Producto A',
            'cantidad' => 2, 'precio_unitario' => 100,
        ]);

        return $cotizacion;
    }

    public function test_calcula_el_subtotal_y_total_sin_descuento_ni_impuestos(): void
    {
        $cotizacion = $this->crearCotizacionConLineas();

        $resultado = (new CotizacionCalculoService())->recalcular($cotizacion);

        $this->assertEquals(200.0, (float) $resultado->subtotal);
        $this->assertEquals(200.0, (float) $resultado->total);
        $this->assertCount(0, $resultado->impuestos);
    }

    public function test_aplica_descuento_global_antes_de_impuestos(): void
    {
        $cotizacion = $this->crearCotizacionConLineas(descuentoPct: 10);

        $resultado = (new CotizacionCalculoService())->recalcular($cotizacion);

        // 200 - 10% = 180
        $this->assertEquals(180.0, (float) $resultado->total);
    }

    public function test_combina_varios_impuestos_activos_sobre_la_base_con_descuento(): void
    {
        CrmConfiguracionImpuesto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'IVA', 'tasa' => 16, 'activo' => true, 'orden' => 1]);
        CrmConfiguracionImpuesto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'IEPS', 'tasa' => 8, 'activo' => true, 'orden' => 2]);
        CrmConfiguracionImpuesto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Inactivo', 'tasa' => 50, 'activo' => false, 'orden' => 3]);

        $cotizacion = $this->crearCotizacionConLineas(descuentoPct: 10);

        $resultado = (new CotizacionCalculoService())->recalcular($cotizacion);

        // base gravable = 180; IVA 16% = 28.8; IEPS 8% = 14.4; total = 223.2
        $this->assertCount(2, $resultado->impuestos);
        $this->assertEquals(223.2, round((float) $resultado->total, 2));
    }
}
