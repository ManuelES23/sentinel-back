<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\Enterprise;
use App\Services\CRM\DashboardResumenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class DashboardResumenServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    public function test_resuelve_rango_de_mes_actual(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 10, 0, 0));
        $service = new DashboardResumenService();

        $rango = $service->resolverRangoFechas('mes_actual');

        $this->assertSame('2026-09-01 00:00:00', $rango['inicio']->toDateTimeString());
        $this->assertSame('2026-09-30 23:59:59', $rango['fin']->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_resuelve_rango_de_mes_anterior(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 10, 0, 0));
        $service = new DashboardResumenService();

        $rango = $service->resolverRangoFechas('mes_anterior');

        $this->assertSame('2026-08-01 00:00:00', $rango['inicio']->toDateTimeString());
        $this->assertSame('2026-08-31 23:59:59', $rango['fin']->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_resuelve_rango_de_mes_anterior_cruzando_de_anio(): void
    {
        // Caso límite: el mes anterior a enero es diciembre del año pasado.
        Carbon::setTestNow(Carbon::create(2026, 1, 15, 10, 0, 0));
        $service = new DashboardResumenService();

        $rango = $service->resolverRangoFechas('mes_anterior');

        $this->assertSame('2025-12-01 00:00:00', $rango['inicio']->toDateTimeString());
        $this->assertSame('2025-12-31 23:59:59', $rango['fin']->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_resuelve_rango_de_trimestre(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 10, 0, 0));
        $service = new DashboardResumenService();

        $rango = $service->resolverRangoFechas('trimestre');

        $this->assertSame('2026-07-01 00:00:00', $rango['inicio']->toDateTimeString());
        $this->assertSame('2026-09-30 23:59:59', $rango['fin']->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_resuelve_rango_de_anio(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 10, 0, 0));
        $service = new DashboardResumenService();

        $rango = $service->resolverRangoFechas('anio');

        $this->assertSame('2026-01-01 00:00:00', $rango['inicio']->toDateTimeString());
        $this->assertSame('2026-12-31 23:59:59', $rango['fin']->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_periodo_invalido_lanza_excepcion(): void
    {
        $service = new DashboardResumenService();

        $this->expectException(\InvalidArgumentException::class);

        $service->resolverRangoFechas('siglo');
    }

    public function test_pipeline_agrupa_por_etapa_dentro_del_periodo_y_filtra_fuera(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'A', 'monto_esperado' => 1000, 'etapa' => 'calificado',
            'fecha_cierre_esperada' => '2026-09-20',
        ]);
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'B', 'monto_esperado' => 500, 'etapa' => 'calificado',
            'fecha_cierre_esperada' => '2026-09-25',
        ]);
        CrmOportunidad::create([
            // Fuera del periodo (octubre) -- no debe contar en mes_actual.
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'C', 'monto_esperado' => 9999, 'etapa' => 'calificado',
            'fecha_cierre_esperada' => '2026-10-05',
        ]);

        $service = new DashboardResumenService();
        $porEtapa = collect($service->pipeline($this->enterprise->id, $this->vendedor->id, 'mes_actual'))
            ->keyBy('etapa');

        $this->assertCount(6, $porEtapa);
        $this->assertSame(2, $porEtapa['calificado']['total']);
        $this->assertSame(1500.0, $porEtapa['calificado']['monto']);
        $this->assertSame(0, $porEtapa['prospecto']['total']);
        $this->assertSame(0.0, $porEtapa['prospecto']['monto']);

        Carbon::setTestNow();
    }

    public function test_pipeline_ignora_datos_de_otra_empresa(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $otraEmpresa = $this->crearOtraEmpresa();
        $vendedorAjeno = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Vendedor ajeno',
        ]);
        CrmOportunidad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'nombre' => 'Ajena', 'monto_esperado' => 5000, 'etapa' => 'calificado',
            'fecha_cierre_esperada' => '2026-09-20',
        ]);

        $service = new DashboardResumenService();
        $porEtapa = collect($service->pipeline($this->enterprise->id, null, 'mes_actual'))->keyBy('etapa');

        $this->assertSame(0, $porEtapa['calificado']['total']);

        Carbon::setTestNow();
    }

    public function test_pipeline_filtra_por_vendedor_dentro_de_empresa(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        // Crear segundo vendedor en la misma empresa
        $segundoVendedor = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Segundo Vendedor',
        ]);

        // Crear oportunidad del PRIMER vendedor en etapa "prospecto" con monto 1000
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Oportunidad Vendedor 1',
            'monto_esperado' => 1000,
            'etapa' => 'prospecto',
            'fecha_cierre_esperada' => '2026-09-20',
        ]);

        // Crear oportunidad del SEGUNDO vendedor en etapa "prospecto" con monto 5000
        // Esta data NO debe contar cuando filtramos por $this->vendedor->id
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $segundoVendedor->id,
            'nombre' => 'Oportunidad Vendedor 2',
            'monto_esperado' => 5000,
            'etapa' => 'prospecto',
            'fecha_cierre_esperada' => '2026-09-20',
        ]);

        $service = new DashboardResumenService();
        $porEtapa = collect($service->pipeline($this->enterprise->id, $this->vendedor->id, 'mes_actual'))
            ->keyBy('etapa');

        // Debe contar SOLO la oportunidad del primer vendedor (1000), NOT del segundo (5000)
        $this->assertSame(1, $porEtapa['prospecto']['total']);
        $this->assertSame(1000.0, $porEtapa['prospecto']['monto']);
        // Las demás etapas deben estar en cero
        $this->assertSame(0, $porEtapa['calificado']['total']);
        $this->assertSame(0.0, $porEtapa['calificado']['monto']);

        Carbon::setTestNow();
    }

    public function test_cotizaciones_agrupa_por_estado_y_filtra_por_vendedor_via_oportunidad(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        $oportunidadPropia = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Op propia', 'monto_esperado' => 2000, 'etapa' => 'propuesta',
        ]);
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidadPropia->id,
            'folio' => 'COT-1', 'estado' => 'aprobado', 'fecha_emision' => '2026-09-10', 'total' => 1200,
        ]);
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidadPropia->id,
            'folio' => 'COT-2', 'estado' => 'aprobado', 'fecha_emision' => '2026-09-12', 'total' => 800,
        ]);

        $otroVendedor = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Otro vendedor',
        ]);
        $oportunidadAjena = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $otroVendedor->id,
            'nombre' => 'Op ajena', 'monto_esperado' => 5000, 'etapa' => 'propuesta',
        ]);
        CrmCotizacion::create([
            // De otro vendedor -- no debe contar cuando se filtra por $this->vendedor.
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidadAjena->id,
            'folio' => 'COT-3', 'estado' => 'aprobado', 'fecha_emision' => '2026-09-14', 'total' => 9999,
        ]);

        $service = new DashboardResumenService();
        $porEstado = collect($service->cotizaciones($this->enterprise->id, $this->vendedor->id, 'mes_actual'))
            ->keyBy('estado');

        $this->assertCount(5, $porEstado);
        $this->assertSame(2, $porEstado['aprobado']['total']);
        $this->assertSame(2000.0, $porEstado['aprobado']['monto']);
        $this->assertSame(0, $porEstado['rechazado']['total']);

        Carbon::setTestNow();
    }

    public function test_cotizaciones_sin_vendedor_agrega_todo_el_equipo(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        $otroVendedor = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Otro vendedor',
        ]);
        $oportunidadAjena = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $otroVendedor->id,
            'nombre' => 'Op ajena', 'monto_esperado' => 5000, 'etapa' => 'propuesta',
        ]);
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidadAjena->id,
            'folio' => 'COT-4', 'estado' => 'enviado', 'fecha_emision' => '2026-09-14', 'total' => 300,
        ]);

        $service = new DashboardResumenService();
        $porEstado = collect($service->cotizaciones($this->enterprise->id, null, 'mes_actual'))->keyBy('estado');

        $this->assertSame(1, $porEstado['enviado']['total']);

        Carbon::setTestNow();
    }

    public function test_cotizaciones_ignora_datos_de_otra_empresa(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $otraEmpresa = $this->crearOtraEmpresa();
        $vendedorAjeno = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Vendedor ajeno',
        ]);
        $oportunidadAjena = CrmOportunidad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'nombre' => 'Ajena', 'monto_esperado' => 5000, 'etapa' => 'propuesta',
        ]);
        CrmCotizacion::create([
            'empresa_id' => $otraEmpresa->id, 'oportunidad_id' => $oportunidadAjena->id,
            'folio' => 'COT-AJENA', 'estado' => 'aprobado', 'fecha_emision' => '2026-09-20', 'total' => 9999,
        ]);

        $service = new DashboardResumenService();
        $porEstado = collect($service->cotizaciones($this->enterprise->id, null, 'mes_actual'))->keyBy('estado');

        $this->assertSame(0, $porEstado['aprobado']['total']);
        $this->assertSame(0.0, $porEstado['aprobado']['monto']);

        Carbon::setTestNow();
    }

    public function test_funnel_conversion_calcula_prospectos_clientes_y_tasa(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'P1']);
        CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'P2']);
        CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'P3']);
        CrmCliente::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'C1', 'prospecto_id' => null]);
        // Solo este cuenta como "convertido" -- tiene prospecto_id.
        $prospectoConvertido = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'P4']);
        CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'C2', 'prospecto_id' => $prospectoConvertido->id,
        ]);

        $service = new DashboardResumenService();
        $resultado = $service->funnelConversion($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame(4, $resultado['prospectosCreados']);
        $this->assertSame(1, $resultado['clientesConvertidos']);
        $this->assertSame(25.0, $resultado['tasaConversion']);

        Carbon::setTestNow();
    }

    public function test_funnel_conversion_sin_prospectos_devuelve_tasa_cero(): void
    {
        $service = new DashboardResumenService();
        $resultado = $service->funnelConversion($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame(0, $resultado['prospectosCreados']);
        $this->assertSame(0, $resultado['clientesConvertidos']);
        $this->assertSame(0.0, $resultado['tasaConversion']);
    }

    public function test_funnel_conversion_ignora_datos_de_otra_empresa(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $otraEmpresa = $this->crearOtraEmpresa();
        $vendedorAjeno = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Vendedor ajeno',
        ]);

        // Crear prospectos y clientes en la otra empresa que estarían dentro del rango
        CrmProspecto::create(['empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id, 'nombre' => 'P-ajena-1']);
        CrmProspecto::create(['empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id, 'nombre' => 'P-ajena-2']);
        $prospectoAjeno = CrmProspecto::create(['empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id, 'nombre' => 'P-ajena-3']);
        CrmCliente::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'nombre' => 'C-ajena', 'prospecto_id' => $prospectoAjeno->id,
        ]);

        $service = new DashboardResumenService();
        $resultado = $service->funnelConversion($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        // Debe retornar 0, 0, 0.0 porque no hay data en nuestra empresa
        $this->assertSame(0, $resultado['prospectosCreados']);
        $this->assertSame(0, $resultado['clientesConvertidos']);
        $this->assertSame(0.0, $resultado['tasaConversion']);

        Carbon::setTestNow();
    }
}
