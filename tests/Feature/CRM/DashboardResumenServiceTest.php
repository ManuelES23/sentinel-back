<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmPresupuesto;
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

    public function test_actividad_agrupa_por_tipo_y_por_dia(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'descripcion' => 'Llamada 1', 'fecha_actividad' => '2026-09-10 09:00:00',
        ]);
        CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'descripcion' => 'Llamada 2', 'fecha_actividad' => '2026-09-10 15:00:00',
        ]);
        CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'correo', 'descripcion' => 'Correo 1', 'fecha_actividad' => '2026-09-12 09:00:00',
        ]);

        $service = new DashboardResumenService();
        $resultado = $service->actividad($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $porTipo = collect($resultado['porTipo'])->keyBy('tipo');
        $this->assertSame(2, $porTipo['llamada']['total']);
        $this->assertSame(1, $porTipo['correo']['total']);

        $porDia = collect($resultado['porDia']);
        $this->assertCount(2, $porDia);
        $this->assertSame('2026-09-10', $porDia->first()['fecha']);
        $this->assertSame(2, $porDia->first()['total']);
        $this->assertSame('2026-09-12', $porDia->last()['fecha']);

        Carbon::setTestNow();
    }

    public function test_actividad_sin_datos_devuelve_arrays_vacios(): void
    {
        $service = new DashboardResumenService();
        $resultado = $service->actividad($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame([], $resultado['porTipo']);
        $this->assertSame([], $resultado['porDia']);
    }

    public function test_actividad_ignora_datos_de_otra_empresa(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $otraEmpresa = $this->crearOtraEmpresa();
        $vendedorAjeno = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Vendedor ajeno',
        ]);

        // Crear actividades en la otra empresa que estarían dentro del rango
        CrmActividad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'tipo' => 'llamada', 'descripcion' => 'Llamada ajena 1', 'fecha_actividad' => '2026-09-10 09:00:00',
        ]);
        CrmActividad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'tipo' => 'llamada', 'descripcion' => 'Llamada ajena 2', 'fecha_actividad' => '2026-09-10 15:00:00',
        ]);
        CrmActividad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'tipo' => 'correo', 'descripcion' => 'Correo ajeno', 'fecha_actividad' => '2026-09-12 09:00:00',
        ]);

        $service = new DashboardResumenService();
        $resultado = $service->actividad($this->enterprise->id, null, 'mes_actual');

        // No debe incluir data de la otra empresa
        $this->assertSame([], $resultado['porTipo']);
        $this->assertSame([], $resultado['porDia']);

        Carbon::setTestNow();
    }

    public function test_cumplimiento_metas_de_un_solo_mes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 9, 'anio' => 2026, 'meta_monto' => 10000, 'meta_clientes' => 5, 'meta_actividades' => 20,
        ]);
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Ganada', 'monto_esperado' => 4000, 'etapa' => 'cerrado_ganado',
            'fecha_cierre_real' => '2026-09-05',
        ]);
        CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'Cliente nuevo',
        ]);
        CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'descripcion' => 'x', 'fecha_actividad' => '2026-09-06 10:00:00',
        ]);

        $service = new DashboardResumenService();
        $resultado = $service->cumplimientoMetas($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame(10000.0, $resultado['metaMonto']);
        $this->assertSame(5, $resultado['metaClientes']);
        $this->assertSame(20, $resultado['metaActividades']);
        $this->assertSame(4000.0, $resultado['montoReal']);
        $this->assertSame(1, $resultado['clientesReales']);
        $this->assertSame(1, $resultado['actividadesReales']);

        Carbon::setTestNow();
    }

    public function test_cumplimiento_metas_de_trimestre_suma_los_3_meses(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        foreach ([7, 8, 9] as $mes) {
            CrmPresupuesto::create([
                'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
                'mes' => $mes, 'anio' => 2026, 'meta_monto' => 1000, 'meta_clientes' => 1, 'meta_actividades' => 2,
            ]);
        }
        // Meta de un mes fuera del trimestre (junio) -- no debe sumar.
        CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 6, 'anio' => 2026, 'meta_monto' => 99999, 'meta_clientes' => 99, 'meta_actividades' => 99,
        ]);

        $service = new DashboardResumenService();
        $resultado = $service->cumplimientoMetas($this->enterprise->id, $this->vendedor->id, 'trimestre');

        $this->assertSame(3000.0, $resultado['metaMonto']);
        $this->assertSame(3, $resultado['metaClientes']);
        $this->assertSame(6, $resultado['metaActividades']);

        Carbon::setTestNow();
    }

    public function test_cumplimiento_metas_sin_presupuesto_definido_devuelve_metas_en_cero(): void
    {
        $service = new DashboardResumenService();
        $resultado = $service->cumplimientoMetas($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame(0.0, $resultado['metaMonto']);
        $this->assertSame(0, $resultado['metaClientes']);
        $this->assertSame(0.0, $resultado['montoReal']);
    }

    public function test_cumplimiento_metas_ignora_datos_de_otra_empresa(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $otraEmpresa = $this->crearOtraEmpresa();
        $vendedorAjeno = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Vendedor ajeno',
        ]);

        // Crear presupuesto en la otra empresa que estaría dentro del rango
        CrmPresupuesto::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'mes' => 9, 'anio' => 2026, 'meta_monto' => 99999, 'meta_clientes' => 99, 'meta_actividades' => 99,
        ]);

        // Crear oportunidad cerrada ganada en la otra empresa
        CrmOportunidad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'nombre' => 'Ganada ajena', 'monto_esperado' => 88888, 'etapa' => 'cerrado_ganado',
            'fecha_cierre_real' => '2026-09-05',
        ]);

        // Crear cliente en la otra empresa
        CrmCliente::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id, 'nombre' => 'Cliente ajeno',
        ]);

        // Crear actividad en la otra empresa
        CrmActividad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'tipo' => 'llamada', 'descripcion' => 'Llamada ajena', 'fecha_actividad' => '2026-09-06 10:00:00',
        ]);

        $service = new DashboardResumenService();
        // Call with vendedorId = null (team aggregate) so empresa_id is the ONLY filter that excludes the other empresa's data
        $resultado = $service->cumplimientoMetas($this->enterprise->id, null, 'mes_actual');

        // Debe retornar 0 para metas y reales porque no hay data en nuestra empresa
        $this->assertSame(0.0, $resultado['metaMonto']);
        $this->assertSame(0, $resultado['metaClientes']);
        $this->assertSame(0, $resultado['metaActividades']);
        $this->assertSame(0.0, $resultado['montoReal']);
        $this->assertSame(0, $resultado['clientesReales']);
        $this->assertSame(0, $resultado['actividadesReales']);

        Carbon::setTestNow();
    }

    public function test_kpis_combina_snapshot_actual_y_metricas_del_periodo(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        // Oportunidades abiertas (snapshot -- no filtra por fecha).
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Abierta', 'monto_esperado' => 3000, 'etapa' => 'propuesta',
        ]);
        CrmOportunidad::create([
            // Cerrada -- no cuenta como "abierta".
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Cerrada', 'monto_esperado' => 999, 'etapa' => 'cerrado_ganado',
            'fecha_cierre_real' => '2026-08-01',
        ]);

        // Cotizaciones pendientes (snapshot).
        $op = CrmOportunidad::first();
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $op->id,
            'folio' => 'C1', 'estado' => 'enviado', 'fecha_emision' => '2026-01-01', 'total' => 700,
        ]);
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $op->id,
            'folio' => 'C2', 'estado' => 'aprobado', 'fecha_emision' => '2026-01-01', 'total' => 1500,
        ]);

        // Clientes nuevos (respeta el periodo).
        CrmCliente::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'Nuevo']);

        // Meta + real para el % de cumplimiento.
        CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 9, 'anio' => 2026, 'meta_monto' => 2000, 'meta_clientes' => 1, 'meta_actividades' => 1,
        ]);
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Ganada del mes', 'monto_esperado' => 1000, 'etapa' => 'cerrado_ganado',
            'fecha_cierre_real' => '2026-09-10',
        ]);

        $service = new DashboardResumenService();
        $resultado = $service->kpis($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame(1, $resultado['oportunidadesAbiertas']);
        $this->assertSame(3000.0, $resultado['montoOportunidadesAbiertas']);
        $this->assertSame(1, $resultado['cotizacionesPendientes']); // solo 'enviado' es pendiente
        $this->assertSame(700.0, $resultado['montoCotizacionesPendientes']);
        $this->assertSame(1, $resultado['clientesNuevos']);
        $this->assertSame(50.0, $resultado['porcentajeCumplimientoMeta']); // 1000/2000

        Carbon::setTestNow();
    }

    public function test_kpis_sin_meta_definida_no_divide_entre_cero(): void
    {
        $service = new DashboardResumenService();
        $resultado = $service->kpis($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame(0.0, $resultado['porcentajeCumplimientoMeta']);
    }

    public function test_kpis_ignora_datos_de_otra_empresa(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $otraEmpresa = $this->crearOtraEmpresa();
        $vendedorAjeno = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Vendedor ajeno',
        ]);

        // Crear una oportunidad abierta en la otra empresa que alimentaría oportunidadesAbiertas
        CrmOportunidad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'nombre' => 'Ajena abierta', 'monto_esperado' => 77777, 'etapa' => 'propuesta',
        ]);

        // Crear un cliente en la otra empresa que alimentaría clientesNuevos
        CrmCliente::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id, 'nombre' => 'Cliente ajeno',
        ]);

        $service = new DashboardResumenService();
        // Call with vendedorId = null (team aggregate) so empresa_id is the ONLY filter excluding the other empresa's data
        $resultado = $service->kpis($this->enterprise->id, null, 'mes_actual');

        // Debe retornar 0 para todos los campos porque no hay data en nuestra empresa
        $this->assertSame(0, $resultado['oportunidadesAbiertas']);
        $this->assertSame(0.0, $resultado['montoOportunidadesAbiertas']);
        $this->assertSame(0, $resultado['cotizacionesPendientes']);
        $this->assertSame(0.0, $resultado['montoCotizacionesPendientes']);
        $this->assertSame(0, $resultado['clientesNuevos']);
        $this->assertSame(0.0, $resultado['porcentajeCumplimientoMeta']);

        Carbon::setTestNow();
    }
}
