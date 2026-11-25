<?php

namespace App\Services\Planeacion;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Cuentas del atraso de una semana (lunes a domingo) que comparten los avances
 * de Planeación: qué día de la semana es hoy y cuánto se debe de los días ya
 * pasados.
 */
final class AtrasoSemana
{
    /**
     * Dónde cae hoy respecto a la semana que empieza en `$lunes`.
     * `dias_pasados` es 0 en una semana futura y 7 en una pasada; hoy no cuenta
     * como pasado (un día futuro o el de hoy nunca es atrasado).
     *
     * @return array{hoy: string, indice_hoy: int, dias_pasados: int, hoy_en_semana: bool}
     */
    public static function dias(string $lunes, CarbonInterface $hoy): array
    {
        $dias = CalculadoraBalance::DIAS;
        $inicio = CarbonImmutable::parse($lunes)->startOfDay();
        $fechaHoy = CarbonImmutable::parse($hoy->toDateString())->startOfDay();
        $indiceHoy = (int) $inicio->diffInDays($fechaHoy, false);

        return [
            'hoy' => $fechaHoy->toDateString(),
            'indice_hoy' => $indiceHoy,
            'dias_pasados' => max(0, min($dias, $indiceHoy)),
            'hoy_en_semana' => $indiceHoy >= 0 && $indiceHoy < $dias,
        ];
    }

    /**
     * Deuda de los primeros `$diasPasados` días con compensación: día a día
     * `deuda = max(0, deuda + plan[d] − real[d])`, así un sobre-cumplimiento cubre
     * faltantes anteriores pero no deja saldo a favor. Redondea a `$decimales` en
     * cada paso; con 0 decimales devuelve entero.
     *
     * @param  array<int, int|float>  $plan
     * @param  array<int, int|float>  $real
     */
    public static function deuda(array $plan, array $real, int $diasPasados, int $decimales = 0): int|float
    {
        $deuda = 0.0;
        for ($d = 0; $d < $diasPasados; $d++) {
            $deuda = max(0.0, round($deuda + ($plan[$d] ?? 0) - ($real[$d] ?? 0), $decimales));
        }

        return $decimales === 0 ? (int) $deuda : $deuda;
    }
}
