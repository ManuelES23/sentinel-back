<?php

namespace Tests\Feature\CRM;

use App\Support\CRM\RangoDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RangoDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');
        CarbonImmutable::setTestNow('2026-10-08 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function assertRango(RangoDashboard $r, string $ini, string $fin, string $iniAnt, string $finAnt, string $etiqueta): void
    {
        $this->assertSame($ini, $r->inicio->toDateTimeString());
        $this->assertSame($fin, $r->fin->toDateTimeString());
        $this->assertSame($iniAnt, $r->inicioAnterior->toDateTimeString());
        $this->assertSame($finAnt, $r->finAnterior->toDateTimeString());
        $this->assertSame($etiqueta, $r->etiquetaAnterior);
    }

    public function test_mes_actual_se_compara_con_el_mes_anterior_al_mismo_dia(): void
    {
        $this->assertRango(
            RangoDashboard::desdePeriodo('mes_actual'),
            '2026-10-01 00:00:00', '2026-10-31 23:59:59',
            '2026-09-01 00:00:00', '2026-09-08 23:59:59',
            'vs sep al día 8',
        );
    }

    public function test_mes_actual_en_un_dia_que_no_existe_en_el_mes_anterior(): void
    {
        CarbonImmutable::setTestNow('2026-03-31 10:00:00');
        $this->assertRango(
            RangoDashboard::desdePeriodo('mes_actual'),
            '2026-03-01 00:00:00', '2026-03-31 23:59:59',
            '2026-02-01 00:00:00', '2026-02-28 23:59:59',
            'vs feb al día 28',
        );
    }

    public function test_mes_anterior_se_compara_completo(): void
    {
        $this->assertRango(
            RangoDashboard::desdePeriodo('mes_anterior'),
            '2026-09-01 00:00:00', '2026-09-30 23:59:59',
            '2026-08-01 00:00:00', '2026-08-31 23:59:59',
            'vs ago',
        );
    }

    public function test_trimestre_se_recorta_a_los_dias_transcurridos(): void
    {
        $this->assertRango(
            RangoDashboard::desdePeriodo('trimestre'),
            '2026-10-01 00:00:00', '2026-12-31 23:59:59',
            '2026-07-01 00:00:00', '2026-07-08 23:59:59',
            'vs trimestre anterior',
        );
    }

    public function test_anio_se_compara_con_el_anio_anterior_a_la_misma_fecha(): void
    {
        $this->assertRango(
            RangoDashboard::desdePeriodo('anio'),
            '2026-01-01 00:00:00', '2026-12-31 23:59:59',
            '2025-01-01 00:00:00', '2025-10-08 23:59:59',
            'vs 2025 a la misma fecha',
        );
    }

    public function test_personalizado_se_compara_con_los_mismos_dias_previos(): void
    {
        $r = RangoDashboard::desdePeriodo('personalizado', '2026-09-15', '2026-10-08');

        $this->assertRango($r,
            '2026-09-15 00:00:00', '2026-10-08 23:59:59',
            '2026-08-22 00:00:00', '2026-09-14 23:59:59',
            'vs 22 ago – 14 sep',
        );
        $this->assertSame(24, $r->dias());
    }

    public function test_personalizado_sin_fechas_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RangoDashboard::desdePeriodo('personalizado');
    }

    public function test_periodo_desconocido_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RangoDashboard::desdePeriodo('siglo');
    }

    public function test_en_curso(): void
    {
        $this->assertTrue(RangoDashboard::desdePeriodo('mes_actual')->enCurso());
        $this->assertFalse(RangoDashboard::desdePeriodo('mes_anterior')->enCurso());
    }

    public function test_ultimos_meses_no_pasan_del_mes_en_curso(): void
    {
        $meses = fn (RangoDashboard $r, int $n) => array_map(fn ($m) => $m->format('Y-m-d'), $r->ultimosMeses($n));

        $this->assertSame(['2026-08-01', '2026-09-01', '2026-10-01'], $meses(RangoDashboard::desdePeriodo('mes_actual'), 3));
        $this->assertSame(['2026-08-01', '2026-09-01', '2026-10-01'], $meses(RangoDashboard::desdePeriodo('anio'), 3));
        $this->assertSame(['2026-07-01', '2026-08-01', '2026-09-01'], $meses(RangoDashboard::desdePeriodo('mes_anterior'), 3));
    }
}
