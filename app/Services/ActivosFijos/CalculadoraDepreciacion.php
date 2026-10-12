<?php

namespace App\Services\ActivosFijos;

use Carbon\CarbonImmutable;

/**
 * Depreciación en línea recta, en meses completos y centavos enteros.
 *
 * El acumulado al mes k es round(base × k ÷ vida útil); la cuota del mes es la
 * diferencia de acumulados. Así la suma de las cuotas es exactamente la base,
 * sin centavos perdidos ni ajuste especial en el último mes.
 */
final class CalculadoraDepreciacion
{
    public function calcular(DatosDepreciacion $datos, \DateTimeInterface $mes): ResultadoDepreciacion
    {
        $mes = CarbonImmutable::instance($mes)->startOfMonth();
        $vida = $datos->vidaMeses();
        $base = $datos->baseCentavos();

        $k = (($mes->year - $datos->inicio->year) * 12) + ($mes->month - $datos->inicio->month) + 1;

        if ($k <= 0) {
            return new ResultadoDepreciacion(ResultadoDepreciacion::POR_INICIAR, 0, $vida, 0, 0, $datos->valorCentavos);
        }

        $transcurridos = min($k, $vida);
        $acumulado = $this->acumulado($base, $transcurridos, $vida);
        $anterior = $this->acumulado($base, min($k - 1, $vida), $vida);

        return new ResultadoDepreciacion(
            $k >= $vida ? ResultadoDepreciacion::TOTALMENTE_DEPRECIADO : ResultadoDepreciacion::DEPRECIANDO,
            $transcurridos,
            $vida,
            $acumulado - $anterior,
            $acumulado,
            $datos->valorCentavos - $acumulado,
        );
    }

    /** round(base × k ÷ vida) con enteros: sin errores de coma flotante. */
    private function acumulado(int $base, int $meses, int $vida): int
    {
        if ($meses <= 0) {
            return 0;
        }

        return intdiv(2 * $base * $meses + $vida, 2 * $vida);
    }
}
