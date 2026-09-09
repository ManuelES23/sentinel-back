<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmOportunidad;
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
        $otraEmpresa = Enterprise::create([
            'name' => 'Otra Empresa', 'slug' => 'otra-empresa-dashboard-'.uniqid(),
            'description' => 'Aislamiento', 'is_active' => true,
        ]);
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
}
