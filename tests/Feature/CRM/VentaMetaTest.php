<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCategoriaRechazo;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmMotivoRechazo;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadAjuste;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmVendedor;
use App\Services\CRM\VentaMetaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Cascada vendido → rechazado → ajustes de precio → en firme en la meta, solo SBP (spec 2026-11-25 §8). */
class VentaMetaTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private CrmVendedor $propio;

    private CrmCliente $cliente;

    private CrmProducto $producto;

    private CrmMotivoRechazo $motivoMadurez;

    private CrmMotivoRechazo $motivoPrecio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();
        $this->fijarHoy('2026-10-10 10:00:00');

        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor propio', 'activo' => true,
        ]);
        $this->cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente Uno', 'estatus' => 'activo',
            'vendedor_id' => $this->propio->id,
        ]);
        $this->producto = CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Mango Ataulfo', 'commodity' => 'Mango', 'precio' => 30,
        ]);
        $precio = CrmCategoriaRechazo::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Precio']);
        $calidad = CrmCategoriaRechazo::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Calidad']);
        $this->motivoPrecio = CrmMotivoRechazo::create(['categoria_id' => $precio->id, 'nombre' => 'Muy caro']);
        $this->motivoMadurez = CrmMotivoRechazo::create(['categoria_id' => $calidad->id, 'nombre' => 'Madurez']);

        $this->bandera(true);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fijarHoy(string $momento): void
    {
        CarbonImmutable::setTestNow($momento);
        Carbon::setTestNow($momento);
    }

    private function bandera(bool $activa): void
    {
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['cotizacion_por_caja' => $activa]);
    }

    private function oportunidad(string $nombre = 'Oportunidad'): CrmOportunidad
    {
        return CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => $nombre, 'etapa' => 'negociacion',
        ]);
    }

    /** Cotiza dos renglones y la deja enviada: calibre 8 (2,000 × 31.00) y calibre 9 (4,000 × 28.50). */
    private function cotizar(CrmOportunidad $op, float $descuento = 0): CrmCotizacion
    {
        $res = $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$op->id}/cotizaciones", [
                'fecha_emision' => now()->toDateString(), 'descuento_global_pct' => $descuento,
                'lineas' => [
                    ['producto_id' => $this->producto->id, 'calibre' => 'Calibre 8', 'presentacion' => 'Caja 4 kg', 'cantidad' => 2000, 'precio_unitario' => 31.00],
                    ['producto_id' => $this->producto->id, 'calibre' => 'Calibre 9', 'presentacion' => 'Caja 4 kg', 'cantidad' => 4000, 'precio_unitario' => 28.50],
                ],
            ])->assertCreated();
        $cotizacion = CrmCotizacion::findOrFail($res->json('data.id'));
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$cotizacion->id}/enviar")->assertOk();

        return $cotizacion;
    }

    /** Vende el calibre 9 (5,000 × 28.50 = 142,500) y rechaza el 8; devuelve el id del renglón vendido. */
    private function vender(CrmOportunidad $op): int
    {
        $cotizacion = $this->cotizar($op);
        [$id8, $id9] = $cotizacion->lineas()->orderBy('id')->pluck('id')->all();
        $this->withHeaders($this->crmHeaders())->postJson("/api/crm/cotizaciones/{$cotizacion->id}/venta", ['renglones' => [
            ['linea_id' => $id8, 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            ['linea_id' => $id9, 'vendido' => true, 'cantidad' => 5000, 'precio' => 28.50],
        ]])->assertOk();

        return (int) $op->fresh()->ventaVigente->renglones()->where('vendido', true)->value('id');
    }

    private function rechazar(CrmOportunidad $op, int $renglon, int $cajas)
    {
        return $this->withHeaders($this->crmHeaders())->postJson("/api/crm/oportunidades/{$op->id}/ajustes", [
            'venta_renglon_id' => $renglon, 'tipo' => 'rechazo', 'cajas' => $cajas,
            'motivo_rechazo_id' => $this->motivoMadurez->id,
        ])->assertCreated();
    }

    private function ajustarPrecio(CrmOportunidad $op, int $renglon, float $nuevo)
    {
        return $this->withHeaders($this->crmHeaders())->postJson("/api/crm/oportunidades/{$op->id}/ajustes", [
            'venta_renglon_id' => $renglon, 'tipo' => 'precio', 'precio_nuevo' => $nuevo,
            'motivo_rechazo_id' => $this->motivoPrecio->id,
        ])->assertCreated();
    }

    private function cumplimiento(string $desde, string $hasta, array $extra = [])
    {
        return $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/cumplimiento-metas?'.http_build_query(
            ['periodo' => 'personalizado', 'desde' => $desde, 'hasta' => $hasta] + $extra
        ));
    }

    public function test_cascada_con_venta_y_rechazo(): void
    {
        $op = $this->oportunidad();
        $renglon = $this->vender($op);
        $this->rechazar($op, $renglon, 1000);

        $res = $this->cumplimiento('2026-10-01', '2026-10-31')->assertOk();

        $venta = $res->json('data.venta');
        $this->assertEquals(142500.00, $venta['vendido']);
        $this->assertEquals(28500.00, $venta['rechazado']);
        $this->assertEquals(0, $venta['ajustesPrecio']);
        $this->assertEquals(114000.00, $venta['firme']);
        $this->assertSame(5000, $venta['cajasVendidas']);
        $this->assertSame(1000, $venta['cajasRechazadas']);
        $this->assertSame(4000, $venta['cajasFirmes']);
        $this->assertEquals($res->json('data.montoReal'), $venta['firme'], 'la cascada cierra en lo mismo que el monto real');
    }

    public function test_cascada_con_ajuste_de_precio_que_baja_y_que_sube(): void
    {
        $op = $this->oportunidad();
        $renglon = $this->vender($op);
        $this->rechazar($op, $renglon, 1000);
        $this->ajustarPrecio($op, $renglon, 27.00);

        $res = $this->cumplimiento('2026-10-01', '2026-10-31')->assertOk();
        $venta = $res->json('data.venta');
        $this->assertEquals(142500.00, $venta['vendido']);
        $this->assertEquals(28500.00, $venta['rechazado']);
        $this->assertEquals(6000.00, $venta['ajustesPrecio']);
        $this->assertEquals(108000.00, $venta['firme']);
        $this->assertEquals($res->json('data.montoReal'), $venta['firme']);

        // Subir el precio da un ajuste negativo, igual que en el resumen de la oportunidad.
        $this->ajustarPrecio($op, $renglon, 29.00);
        $res = $this->cumplimiento('2026-10-01', '2026-10-31');
        $this->assertEquals(-2000.00, $res->json('data.venta.ajustesPrecio'));
        $this->assertEquals(116000.00, $res->json('data.venta.firme'));
        $this->assertEquals($res->json('data.montoReal'), $res->json('data.venta.firme'));
    }

    public function test_el_rechazo_pega_al_mes_de_la_venta(): void
    {
        $op = $this->oportunidad();
        $renglon = $this->vender($op); // octubre
        $this->fijarHoy('2026-11-05 10:00:00');
        $this->rechazar($op, $renglon, 1000); // se captura en noviembre

        $octubre = $this->cumplimiento('2026-10-01', '2026-10-31')->assertOk();
        $this->assertEquals(28500.00, $octubre->json('data.venta.rechazado'));
        $this->assertEquals(114000.00, $octubre->json('data.venta.firme'));
        $this->assertEquals(114000.00, $octubre->json('data.montoReal'));

        $noviembre = $this->cumplimiento('2026-11-01', '2026-11-30')->assertOk();
        $this->assertEquals(0, $noviembre->json('data.venta.vendido'));
        $this->assertEquals(0, $noviembre->json('data.venta.rechazado'));
        $this->assertEquals(0, $noviembre->json('data.venta.firme'));
        $this->assertSame(0, $noviembre->json('data.venta.cajasRechazadas'));
    }

    public function test_ganada_sin_venta_cuenta_su_monto_y_las_cajas_de_su_cotizacion_aprobada(): void
    {
        // Ganada con venta registrada, para mezclar los dos caminos.
        $conVenta = $this->oportunidad('Con venta');
        $this->rechazar($conVenta, $this->vender($conVenta), 1000);

        // Ganada a mano: sin venta, con una cotización aprobada de 2,000 + 4,000 cajas.
        $sinVenta = $this->oportunidad('Sin venta');
        $cotizacion = $this->cotizar($sinVenta);
        CrmCotizacion::whereKey($cotizacion->id)->update(['estado' => 'aprobado']);
        $sinVenta->update(['etapa' => 'cerrado_ganado', 'fecha_cierre_real' => now(), 'monto_esperado' => 50000]);

        // Ganada a mano sin cotización: aporta monto y cero cajas.
        $sinCotizacion = $this->oportunidad('Sin cotización');
        $sinCotizacion->update(['etapa' => 'cerrado_ganado', 'fecha_cierre_real' => now(), 'monto_esperado' => 1000]);

        $res = $this->cumplimiento('2026-10-01', '2026-10-31')->assertOk();
        $venta = $res->json('data.venta');
        $this->assertEquals(142500.00 + 50000 + 1000, $venta['vendido']);
        $this->assertEquals(28500.00, $venta['rechazado']);
        $this->assertEquals(0, $venta['ajustesPrecio']);
        $this->assertEquals(114000.00 + 50000 + 1000, $venta['firme']);
        $this->assertSame(5000 + 6000, $venta['cajasVendidas']);
        $this->assertSame(1000, $venta['cajasRechazadas']);
        $this->assertSame(4000 + 6000, $venta['cajasFirmes']);
        $this->assertEquals($res->json('data.montoReal'), $venta['firme']);
    }

    public function test_una_perdida_con_venta_en_cero_no_cuenta(): void
    {
        $op = $this->oportunidad();
        $cotizacion = $this->cotizar($op);
        [$id8, $id9] = $cotizacion->lineas()->orderBy('id')->pluck('id')->all();
        $this->withHeaders($this->crmHeaders())->postJson("/api/crm/cotizaciones/{$cotizacion->id}/venta", ['renglones' => [
            ['linea_id' => $id8, 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            ['linea_id' => $id9, 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
        ]])->assertOk();
        $this->assertSame('cerrado_perdido', $op->fresh()->etapa);

        $res = $this->cumplimiento('2026-10-01', '2026-10-31')->assertOk();
        $this->assertEquals(0, $res->json('data.venta.vendido'));
        $this->assertSame(0, $res->json('data.venta.cajasVendidas'));
        $this->assertEquals(0, $res->json('data.montoReal'));
    }

    public function test_un_ajuste_anulado_no_cuenta(): void
    {
        $op = $this->oportunidad();
        $renglon = $this->vender($op);
        $this->rechazar($op, $renglon, 1000);
        $ajuste = CrmOportunidadAjuste::firstOrFail();
        $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$op->id}/ajustes/{$ajuste->id}/anular", ['motivo' => 'Captura equivocada'])
            ->assertOk();

        $res = $this->cumplimiento('2026-10-01', '2026-10-31')->assertOk();
        $venta = $res->json('data.venta');
        $this->assertEquals(142500.00, $venta['vendido']);
        $this->assertEquals(0, $venta['rechazado']);
        $this->assertEquals(142500.00, $venta['firme']);
        $this->assertSame(0, $venta['cajasRechazadas']);
        $this->assertSame(5000, $venta['cajasFirmes']);
        $this->assertEquals(142500.00, $res->json('data.montoReal'));
    }

    public function test_el_descuento_de_la_venta_se_aplica_al_vendido(): void
    {
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['descuento_global_habilitado' => true]);
        $op = $this->oportunidad();
        $cotizacion = $this->cotizar($op, 10);
        [$id8, $id9] = $cotizacion->lineas()->orderBy('id')->pluck('id')->all();
        $this->withHeaders($this->crmHeaders())->postJson("/api/crm/cotizaciones/{$cotizacion->id}/venta", ['renglones' => [
            ['linea_id' => $id8, 'vendido' => true, 'cantidad' => 333, 'precio' => 31.07],
            ['linea_id' => $id9, 'vendido' => true, 'cantidad' => 3333, 'precio' => 28.57],
        ]])->assertOk();

        $res = $this->cumplimiento('2026-10-01', '2026-10-31')->assertOk();
        $this->assertEquals((float) $op->fresh()->monto_esperado, $res->json('data.venta.vendido'));
        $this->assertEquals($res->json('data.montoReal'), $res->json('data.venta.firme'));
    }

    public function test_el_filtro_de_vendedor_es_el_mismo_que_el_del_monto_real(): void
    {
        $propia = $this->oportunidad('Propia');
        $this->vender($propia);
        $ajena = $this->oportunidad('Ajena');
        $this->vender($ajena);
        $ajena->update(['vendedor_id' => $this->vendedor->id]);

        $res = $this->cumplimiento('2026-10-01', '2026-10-31', ['vendedor_id' => $this->propio->id])->assertOk();
        $this->assertEquals(142500.00, $res->json('data.venta.vendido'));
        $this->assertEquals($res->json('data.montoReal'), $res->json('data.venta.firme'));

        $todas = $this->cumplimiento('2026-10-01', '2026-10-31')->assertOk();
        $this->assertEquals(285000.00, $todas->json('data.venta.vendido'));
        $this->assertEquals($todas->json('data.montoReal'), $todas->json('data.venta.firme'));
    }

    public function test_mi_mes_trae_la_venta(): void
    {
        $op = $this->oportunidad();
        $this->rechazar($op, $this->vender($op), 1000);

        $res = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/mi-dia')->assertOk();

        $venta = $res->json('data.mi_mes.venta');
        $this->assertEquals(142500.00, $venta['vendido']);
        $this->assertEquals(28500.00, $venta['rechazado']);
        $this->assertEquals(114000.00, $venta['firme']);
        $this->assertSame(5000, $venta['cajasVendidas']);
        $this->assertSame(4000, $venta['cajasFirmes']);
        $this->assertEquals($res->json('data.mi_mes.ganado'), $venta['firme']);
    }

    public function test_sin_bandera_no_hay_clave_venta(): void
    {
        $op = $this->oportunidad();
        $this->vender($op);
        $this->bandera(false);

        $metas = $this->cumplimiento('2026-10-01', '2026-10-31')->assertOk();
        $this->assertArrayNotHasKey('venta', $metas->json('data'));

        $miDia = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/mi-dia')->assertOk();
        $this->assertArrayNotHasKey('venta', $miDia->json('data.mi_mes'));
        $this->assertNotNull($miDia->json('data.mi_mes.ganado'));

        $this->assertNull(app(VentaMetaService::class)->para(
            $this->enterprise->id, null, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31 23:59:59')
        ));
    }

    public function test_hace_un_numero_constante_de_consultas(): void
    {
        $uno = $this->oportunidad('Uno');
        $this->rechazar($uno, $this->vender($uno), 1000);
        $inicio = CarbonImmutable::parse('2026-10-01');
        $fin = CarbonImmutable::parse('2026-10-31 23:59:59');

        DB::enableQueryLog();
        app(VentaMetaService::class)->para($this->enterprise->id, null, $inicio, $fin);
        $con1 = count(DB::getQueryLog());

        for ($i = 0; $i < 3; $i++) {
            $op = $this->oportunidad("Extra {$i}");
            $this->rechazar($op, $this->vender($op), 500);
        }
        DB::flushQueryLog();
        app(VentaMetaService::class)->para($this->enterprise->id, null, $inicio, $fin);
        $con4 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($con1, $con4);
        $this->assertLessThanOrEqual(6, $con4);
    }
}
