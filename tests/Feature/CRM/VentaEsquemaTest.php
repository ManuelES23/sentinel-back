<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCategoriaRechazo;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmMotivoRechazo;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadAjuste;
use App\Models\CRM\CrmOportunidadVenta;
use App\Models\CRM\CrmOportunidadVentaRenglon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Esquema de lo vendido por renglón, ajustes y versiones de cotización (spec 2026-11-25 §5). */
class VentaEsquemaTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();
    }

    private function oportunidad(): CrmOportunidad
    {
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente Uno', 'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);

        return CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Oportunidad de prueba',
        ]);
    }

    private function cotizacion(CrmOportunidad $oportunidad, string $folio, string $estado): CrmCotizacion
    {
        return CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidad->id,
            'folio' => $folio, 'estado' => $estado, 'fecha_emision' => now()->toDateString(),
            'subtotal' => 100, 'total' => 100,
        ]);
    }

    public function test_crea_las_cuatro_tablas_con_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasColumns('crm_cotizacion_versiones', [
            'id', 'cotizacion_id', 'numero', 'renglones', 'descuento_global_pct',
            'subtotal', 'total', 'motivo', 'user_id', 'created_at',
        ]));
        $this->assertTrue(Schema::hasColumns('crm_oportunidad_ventas', [
            'id', 'empresa_id', 'oportunidad_id', 'cotizacion_id', 'vigente',
            'descuento_global_pct', 'registrada_por', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('crm_oportunidad_venta_renglones', [
            'id', 'venta_id', 'cotizacion_linea_id', 'producto_id', 'descripcion', 'calibre',
            'presentacion', 'cantidad_cotizada', 'precio_cotizado', 'vendido',
            'cantidad_vendida', 'precio_vendido', 'motivo_rechazo_id', 'nota',
        ]));
        $this->assertTrue(Schema::hasColumns('crm_oportunidad_ajustes', [
            'id', 'empresa_id', 'oportunidad_id', 'venta_renglon_id', 'tipo', 'cajas',
            'precio_nuevo', 'importe', 'motivo_rechazo_id', 'nota', 'fecha', 'user_id',
            'anulado_at', 'anulado_por', 'motivo_anulacion', 'created_at', 'updated_at',
        ]));
    }

    public function test_la_configuracion_anuncia_venta_por_renglon(): void
    {
        $config = CrmConfiguracionComercial::paraEmpresa($this->enterprise->id);

        $config->update(['cotizacion_por_caja' => true]);
        $this->withHeaders($this->crmHeaders())->getJson('/api/crm/configuracion-comercial')
            ->assertOk()->assertJsonPath('data.venta_por_renglon', true);

        $config->update(['cotizacion_por_caja' => false]);
        $this->withHeaders($this->crmHeaders())->getJson('/api/crm/configuracion-comercial')
            ->assertOk()->assertJsonPath('data.venta_por_renglon', false);
    }

    public function test_una_oportunidad_resuelve_su_venta_vigente_y_ajustes(): void
    {
        $oportunidad = $this->oportunidad();
        $cotizacion = $this->cotizacion($oportunidad, 'COT-0001', 'aprobado');
        $categoria = CrmCategoriaRechazo::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Calidad']);
        $motivo = CrmMotivoRechazo::create(['categoria_id' => $categoria->id, 'nombre' => 'Madurez']);

        $anterior = CrmOportunidadVenta::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidad->id,
            'cotizacion_id' => $cotizacion->id, 'vigente' => false, 'descuento_global_pct' => 0,
            'registrada_por' => $this->actingUser->id,
        ]);
        $venta = CrmOportunidadVenta::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidad->id,
            'cotizacion_id' => $cotizacion->id, 'vigente' => true, 'descuento_global_pct' => 5,
            'registrada_por' => $this->actingUser->id,
        ]);
        $renglon = CrmOportunidadVentaRenglon::create([
            'venta_id' => $venta->id, 'descripcion' => 'Kent calibre 9', 'cantidad_cotizada' => 1000,
            'precio_cotizado' => 28.5, 'vendido' => true, 'cantidad_vendida' => 1000, 'precio_vendido' => 28.5,
        ]);
        $vigente = CrmOportunidadAjuste::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidad->id,
            'venta_renglon_id' => $renglon->id, 'tipo' => 'rechazo', 'cajas' => 100, 'importe' => -2850,
            'motivo_rechazo_id' => $motivo->id, 'fecha' => now()->toDateString(), 'user_id' => $this->actingUser->id,
        ]);
        CrmOportunidadAjuste::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidad->id,
            'venta_renglon_id' => $renglon->id, 'tipo' => 'precio', 'precio_nuevo' => 27, 'importe' => -1500,
            'fecha' => now()->toDateString(), 'user_id' => $this->actingUser->id,
            'anulado_at' => now(), 'anulado_por' => $this->actingUser->id, 'motivo_anulacion' => 'Error',
        ]);

        $oportunidad = $oportunidad->fresh();
        $this->assertSame($venta->id, $oportunidad->ventaVigente->id);
        $this->assertSame('5.00', $oportunidad->ventaVigente->descuento_global_pct);
        $this->assertTrue($oportunidad->ventaVigente->vigente);
        $this->assertCount(2, $oportunidad->ajustes);
        $this->assertSame([$vigente->id], CrmOportunidadAjuste::vigentes()->pluck('id')->all());
        $this->assertSame($renglon->id, $vigente->renglon->id);
        $this->assertSame($motivo->id, $vigente->motivoRechazo->id);
        $this->assertCount(1, $venta->renglones);
        $this->assertSame($cotizacion->id, $venta->cotizacion->id);
        $this->assertTrue($renglon->fresh()->vendido);
        $this->assertFalse($anterior->fresh()->vigente);
    }

    public function test_una_cotizacion_lista_sus_versiones_por_numero(): void
    {
        $cotizacion = $this->cotizacion($this->oportunidad(), 'COT-0002', 'enviado');
        foreach ([2, 1] as $numero) {
            $cotizacion->versiones()->create([
                'numero' => $numero, 'renglones' => [['descripcion' => 'Kent', 'cantidad' => 10]],
                'descuento_global_pct' => 0, 'subtotal' => 100, 'total' => 100,
                'motivo' => $numero === 1 ? null : 'Ajuste', 'user_id' => $this->actingUser->id,
            ]);
        }

        $versiones = $cotizacion->fresh()->versiones;
        $this->assertSame([1, 2], $versiones->pluck('numero')->all());
        $this->assertSame('Kent', $versiones->first()->renglones[0]['descripcion']);
    }
}
