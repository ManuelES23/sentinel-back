<?php

namespace Tests\Feature\CRM;

use App\Services\CRM\DashboardResumenService;
use Carbon\Carbon;
use Tests\TestCase;

class DashboardResumenServiceTest extends TestCase
{
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
}
