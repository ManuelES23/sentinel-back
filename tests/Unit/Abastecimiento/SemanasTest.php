<?php

namespace Tests\Unit\Abastecimiento;

use App\Services\Abastecimiento\Semanas;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SemanasTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_lunes_de_un_domingo_es_el_lunes_anterior(): void
    {
        $this->assertSame('2026-07-13', Semanas::lunesDe('2026-07-19'));
        $this->assertSame('2026-07-13', Semanas::lunesDe('2026-07-13'));
        $this->assertSame('2026-07-13', Semanas::lunesDe(CarbonImmutable::parse('2026-07-15 18:30')));
    }

    public function test_entre_cruza_el_anio(): void
    {
        $this->assertSame(['2026-12-21', '2026-12-28', '2027-01-04'], Semanas::entre('2026-12-24', '2027-01-06'));
        $this->assertSame(['2026-12-28'], Semanas::entre('2026-12-28', '2026-12-28'));
        $this->assertSame([], Semanas::entre('2026-12-30', '2026-12-24'));
        $this->assertSame(53, Semanas::numero('2026-12-28'));
        $this->assertSame(1, Semanas::numero('2027-01-04'));
    }

    public function test_cerrada(): void
    {
        $hoy = CarbonImmutable::parse('2026-07-14', 'America/Mazatlan');

        $this->assertTrue(Semanas::cerrada('2026-07-06', $hoy));
        $this->assertFalse(Semanas::cerrada('2026-07-13', $hoy));
        // El domingo mismo la semana sigue abierta; se cierra al día siguiente.
        $this->assertFalse(Semanas::cerrada('2026-07-13', CarbonImmutable::parse('2026-07-19', 'America/Mazatlan')));
        $this->assertTrue(Semanas::cerrada('2026-07-13', CarbonImmutable::parse('2026-07-20', 'America/Mazatlan')));
    }

    public function test_hoy_respeta_set_test_now_y_la_zona_de_mazatlan(): void
    {
        // 03:00 UTC del 15 es todavía el 14 por la noche en Mazatlán (UTC-7).
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-15 03:00:00', 'UTC'));

        $this->assertSame('America/Mazatlan', Semanas::hoy()->timezoneName);
        $this->assertSame('2026-07-14', Semanas::hoy()->toDateString());
        $this->assertTrue(Semanas::cerrada('2026-07-06'));
        $this->assertFalse(Semanas::cerrada('2026-07-13'));
    }

    public function test_rango(): void
    {
        $this->assertSame(['lunes' => '2026-07-13', 'domingo' => '2026-07-19'], Semanas::rango('2026-07-13'));
    }
}
