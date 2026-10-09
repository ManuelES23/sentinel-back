<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmPresupuesto;
use App\Models\CRM\CrmVendedor;
use App\Services\CRM\DashboardResumenService;
use App\Support\CRM\RangoDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class DashboardResumenServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const HOY = '2026-10-08 10:00:00';

    private DashboardResumenService $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        $this->ahora(self::HOY);
        $this->servicio = new DashboardResumenService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function ahora(string $fecha): void
    {
        Carbon::setTestNow($fecha);
        CarbonImmutable::setTestNow($fecha);
    }

    private function op(array $datos, string $creada = self::HOY): CrmOportunidad
    {
        $this->ahora($creada);
        $op = CrmOportunidad::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Op',
            'monto_esperado' => 1000,
            'probabilidad' => 50,
        ], $datos));
        $this->ahora(self::HOY);

        return $op;
    }

    private function ganada(string $cierre, float $monto, string $creada = self::HOY, ?int $vendedorId = null): CrmOportunidad
    {
        return $this->op([
            'etapa' => 'cerrado_ganado',
            'fecha_cierre_real' => $cierre,
            'monto_esperado' => $monto,
            'vendedor_id' => $vendedorId ?? $this->vendedor->id,
        ], $creada);
    }

    private function mes(): RangoDashboard
    {
        return RangoDashboard::desdePeriodo('mes_actual');
    }

    public function test_kpis_comparan_con_el_rango_anterior_y_arman_la_serie(): void
    {
        $this->ganada('2026-10-03 10:00:00', 4000, '2026-09-25 10:00:00');
        $this->ganada('2026-09-05 10:00:00', 1000, '2026-08-31 10:00:00');
        $this->ganada('2026-09-20 10:00:00', 7000, '2026-09-01 10:00:00');
        $this->op(['etapa' => 'cerrado_perdido', 'fecha_cierre_real' => '2026-10-04 10:00:00']);
        $abierta = $this->op(['etapa' => 'propuesta', 'monto_esperado' => 3000, 'probabilidad' => 50]);
        CrmCotizacion::create(['empresa_id' => $this->enterprise->id, 'oportunidad_id' => $abierta->id, 'folio' => 'C1', 'estado' => 'enviado', 'fecha_emision' => '2026-10-01', 'total' => 700]);
        CrmCotizacion::create(['empresa_id' => $this->enterprise->id, 'oportunidad_id' => $abierta->id, 'folio' => 'C2', 'estado' => 'aprobado', 'fecha_emision' => '2026-10-01', 'total' => 1500]);
        CrmCliente::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'Nuevo']);

        $k = $this->servicio->kpis($this->enterprise->id, null, $this->mes());

        $this->assertSame(4000.0, $k['ventas']['actual']);
        $this->assertSame(1000.0, $k['ventas']['anterior']);
        $this->assertCount(8, $k['ventas']['serie']);
        $this->assertSame(2026, $k['ventas']['serie'][0]['anio']);
        $this->assertSame(3, $k['ventas']['serie'][0]['mes']);
        $this->assertSame(8000.0, collect($k['ventas']['serie'])->firstWhere('mes', 9)['valor']);
        $this->assertSame(50.0, $k['tasaCierre']['actual']);
        $this->assertSame(100.0, $k['tasaCierre']['anterior']);
        $this->assertSame(8, $k['cicloVenta']['actual']);
        $this->assertSame(1, $k['clientesNuevos']['actual']);
        $this->assertSame(0, $k['clientesNuevos']['anterior']);
        $this->assertSame(['abiertas' => 1, 'monto' => 3000.0, 'ponderado' => 1500.0], $k['pipeline']);
        $this->assertSame(['cantidad' => 1, 'monto' => 700.0], $k['cotizacionesPendientes']);
        $this->assertSame(['inicio' => '2026-10-01', 'fin' => '2026-10-31', 'etiquetaAnterior' => 'vs sep al día 8'], $k['rango']);
    }

    public function test_kpis_sin_cierres_devuelven_null_en_tasa_y_ciclo(): void
    {
        $k = $this->servicio->kpis($this->enterprise->id, null, $this->mes());

        $this->assertSame(0.0, $k['ventas']['actual']);
        $this->assertNull($k['tasaCierre']['actual']);
        $this->assertNull($k['cicloVenta']['actual']);
    }

    public function test_kpis_filtran_por_vendedor_e_ignoran_otra_empresa(): void
    {
        $otro = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro']);
        $this->ganada('2026-10-03 10:00:00', 4000);
        $this->ganada('2026-10-03 10:00:00', 6000, self::HOY, $otro->id);
        $otraEmpresa = $this->crearOtraEmpresa();
        $ajeno = CrmVendedor::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Ajeno']);
        $this->op(['empresa_id' => $otraEmpresa->id, 'vendedor_id' => $ajeno->id, 'etapa' => 'cerrado_ganado', 'fecha_cierre_real' => '2026-10-03', 'monto_esperado' => 99999]);

        $this->assertSame(10000.0, $this->servicio->kpis($this->enterprise->id, null, $this->mes())['ventas']['actual']);
        $this->assertSame(4000.0, $this->servicio->kpis($this->enterprise->id, $this->vendedor->id, $this->mes())['ventas']['actual']);
    }

    public function test_tendencia_de_seis_meses_con_meta_y_anio_anterior(): void
    {
        CrmPresupuesto::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'mes' => 10, 'anio' => 2026, 'meta_monto' => 5000, 'meta_clientes' => 1, 'meta_actividades' => 1]);
        $this->ganada('2026-10-03 10:00:00', 4000);
        $this->ganada('2025-10-10 10:00:00', 2500);

        $con = $this->servicio->tendencia($this->enterprise->id, null, $this->mes(), true);
        $sin = $this->servicio->tendencia($this->enterprise->id, null, $this->mes(), false);

        $this->assertCount(6, $con);
        $this->assertSame(5, $con[0]['mes']);
        $this->assertSame(['anio' => 2026, 'mes' => 10, 'ganado' => 4000.0, 'meta' => 5000.0, 'ganadoAnioAnterior' => 2500.0], $con[5]);
        $this->assertArrayNotHasKey('ganadoAnioAnterior', $sin[5]);
    }

    public function test_cumplimiento_con_pronostico_del_periodo_en_curso(): void
    {
        CrmPresupuesto::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'mes' => 10, 'anio' => 2026, 'meta_monto' => 10000, 'meta_clientes' => 2, 'meta_actividades' => 10]);
        $this->ganada('2026-10-03 10:00:00', 4000);
        $this->op(['etapa' => 'negociacion', 'monto_esperado' => 2000, 'probabilidad' => 50, 'fecha_cierre_esperada' => '2026-10-20']);
        $this->op(['etapa' => 'negociacion', 'monto_esperado' => 8000, 'probabilidad' => 90, 'fecha_cierre_esperada' => '2026-11-05']);
        $this->op(['etapa' => 'propuesta', 'monto_esperado' => 8000, 'probabilidad' => 90, 'fecha_cierre_esperada' => '2026-10-02']);

        $m = $this->servicio->cumplimientoMetas($this->enterprise->id, null, $this->mes());

        $this->assertSame(10000.0, $m['metaMonto']);
        $this->assertSame(4000.0, $m['montoReal']);
        $this->assertSame(5000.0, $m['pronostico']);
        $this->assertFalse($m['periodoTerminado']);
    }

    public function test_cumplimiento_de_periodo_terminado_no_suma_pronostico(): void
    {
        $this->ganada('2026-09-10 10:00:00', 3000);
        $this->op(['etapa' => 'negociacion', 'fecha_cierre_esperada' => '2026-09-25']);

        $m = $this->servicio->cumplimientoMetas($this->enterprise->id, null, RangoDashboard::desdePeriodo('mes_anterior'));

        $this->assertTrue($m['periodoTerminado']);
        $this->assertSame(3000.0, $m['pronostico']);
    }

    public function test_cumplimiento_de_trimestre_suma_las_metas_de_sus_meses(): void
    {
        foreach ([10, 11, 12] as $mes) {
            CrmPresupuesto::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'mes' => $mes, 'anio' => 2026, 'meta_monto' => 1000, 'meta_clientes' => 1, 'meta_actividades' => 1]);
        }

        $m = $this->servicio->cumplimientoMetas($this->enterprise->id, null, RangoDashboard::desdePeriodo('trimestre'));

        $this->assertSame(3000.0, $m['metaMonto']);
        $this->assertSame(3, $m['metaClientes']);
    }

    public function test_cotizaciones_por_estado_con_periodo_anterior_y_filtro_de_vendedor(): void
    {
        $propia = $this->op(['etapa' => 'propuesta']);
        $otro = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro']);
        $ajena = $this->op(['etapa' => 'propuesta', 'vendedor_id' => $otro->id]);
        CrmCotizacion::create(['empresa_id' => $this->enterprise->id, 'oportunidad_id' => $propia->id, 'folio' => 'A', 'estado' => 'aprobado', 'fecha_emision' => '2026-10-05', 'total' => 1200]);
        CrmCotizacion::create(['empresa_id' => $this->enterprise->id, 'oportunidad_id' => $propia->id, 'folio' => 'B', 'estado' => 'aprobado', 'fecha_emision' => '2026-09-03', 'total' => 800]);
        CrmCotizacion::create(['empresa_id' => $this->enterprise->id, 'oportunidad_id' => $ajena->id, 'folio' => 'C', 'estado' => 'aprobado', 'fecha_emision' => '2026-10-05', 'total' => 9999]);

        $con = collect($this->servicio->cotizaciones($this->enterprise->id, $this->vendedor->id, $this->mes(), true))->keyBy('estado');
        $sin = collect($this->servicio->cotizaciones($this->enterprise->id, $this->vendedor->id, $this->mes(), false))->keyBy('estado');

        $this->assertSame(['borrador', 'enviado', 'aprobado', 'rechazado', 'superado'], $con->keys()->all());
        $this->assertSame(1, $con['aprobado']['total']);
        $this->assertSame(1200.0, $con['aprobado']['monto']);
        $this->assertSame(['total' => 1, 'monto' => 800.0], $con['aprobado']['anterior']);
        $this->assertArrayNotHasKey('anterior', $sin['aprobado']);
    }

    public function test_actividad_por_tipo_fijo_con_periodo_anterior(): void
    {
        foreach (['2026-10-02 09:00:00', '2026-10-03 09:00:00'] as $fecha) {
            CrmActividad::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'tipo' => 'llamada', 'descripcion' => 'x', 'fecha_actividad' => $fecha]);
        }
        CrmActividad::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'tipo' => 'llamada', 'descripcion' => 'x', 'fecha_actividad' => '2026-09-02 09:00:00']);

        $a = collect($this->servicio->actividad($this->enterprise->id, null, $this->mes(), true)['porTipo'])->keyBy('tipo');

        $this->assertSame(['llamada', 'whatsapp', 'correo', 'visita', 'reunion', 'nota'], $a->keys()->all());
        $this->assertSame(2, $a['llamada']['total']);
        $this->assertSame(1, $a['llamada']['anterior']);
        $this->assertSame(0, $a['whatsapp']['total']);
    }

    public function test_ranking_ordena_por_monto_y_trae_la_meta(): void
    {
        $otro = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro', 'activo' => true]);
        $this->ganada('2026-10-03 10:00:00', 4000);
        $this->ganada('2026-10-04 10:00:00', 6000, self::HOY, $otro->id);
        CrmPresupuesto::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'mes' => 10, 'anio' => 2026, 'meta_monto' => 5000, 'meta_clientes' => 1, 'meta_actividades' => 1]);

        $r = $this->servicio->rankingVendedores($this->enterprise->id, $this->mes());

        $this->assertSame(['Otro', 'Juan Pérez'], array_column($r, 'nombre'));
        $this->assertSame(6000.0, $r[0]['montoCerrado']);
        $this->assertSame(0.0, $r[0]['meta']);
        $this->assertSame(5000.0, $r[1]['meta']);
    }

    public function test_cierres_y_tasa_publicos(): void
    {
        $this->ganada('2026-10-03 10:00:00', 4000);
        $this->op(['etapa' => 'cerrado_perdido', 'fecha_cierre_real' => '2026-10-04 10:00:00']);
        $mes = $this->mes();

        $this->assertSame(['total' => 2, 'ganadas' => 1], $this->servicio->cierres($this->enterprise->id, null, $mes->inicio, $mes->fin));
        $this->assertSame(50.0, $this->servicio->tasaCierre($this->enterprise->id, null, $mes->inicio, $mes->fin));
    }
}
