<?php

namespace Tests\Unit\Planeacion;

use App\Services\Planeacion\AtrasoSemana;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AtrasoSemanaTest extends TestCase
{
    public function test_deuda_con_compensacion(): void
    {
        // Lunes debe 1.5; el martes se programa 4 y se cumple 5: queda 0.5.
        $this->assertSame(0.5, AtrasoSemana::deuda([5, 4, 3, 0, 0, 0, 0], [3.5, 5, 0, 0, 0, 0, 0], 2, 2));
    }

    public function test_sobre_cumplir_no_deja_saldo_a_favor(): void
    {
        // El lunes sobran 2, pero el martes se debe 1 completo (no se compensa hacia adelante).
        $this->assertSame(1, AtrasoSemana::deuda([1, 1, 0, 0, 0, 0, 0], [3, 0, 0, 0, 0, 0, 0], 2));
    }

    public function test_solo_cuentan_los_dias_pasados(): void
    {
        $this->assertSame(0, AtrasoSemana::deuda([5, 5, 5, 5, 5, 5, 5], [0, 0, 0, 0, 0, 0, 0], 0));
        $this->assertSame(10.5, AtrasoSemana::deuda([5.25, 5.25, 5, 5, 5, 5, 5], [0, 0, 0, 0, 0, 0, 0], 2, 2));
    }

    public function test_dias(): void
    {
        $lunes = '2026-11-23';

        $this->assertSame(
            ['hoy' => '2026-11-25', 'indice_hoy' => 2, 'dias_pasados' => 2, 'hoy_en_semana' => true],
            AtrasoSemana::dias($lunes, CarbonImmutable::parse('2026-11-25 10:00:00')),
        );

        $antes = AtrasoSemana::dias($lunes, CarbonImmutable::parse('2026-11-20'));
        $this->assertSame(0, $antes['dias_pasados']);
        $this->assertFalse($antes['hoy_en_semana']);

        $despues = AtrasoSemana::dias($lunes, CarbonImmutable::parse('2026-12-02'));
        $this->assertSame(7, $despues['dias_pasados']);
        $this->assertFalse($despues['hoy_en_semana']);
    }
}
