<?php

namespace App\Services\Abastecimiento;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Semanas de Abastecimiento: lunes a domingo (ISO), identificadas por la fecha
 * de su lunes (`Y-m-d`). «Hoy» se toma en la zona America/Mazatlan.
 */
class Semanas
{
    public const ZONA = 'America/Mazatlan';

    public static function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONA);
    }

    /** Lunes (Y-m-d) de la semana que contiene la fecha. */
    public static function lunesDe(string|CarbonInterface $fecha): string
    {
        return self::fecha($fecha)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
    }

    /**
     * Lunes de cada semana que toca el rango, en orden. Un rango invertido da [].
     *
     * @return array<int, string>
     */
    public static function entre(string $desde, string $hasta): array
    {
        $lunes = self::fecha($desde)->startOfWeek(CarbonInterface::MONDAY);
        $fin = self::fecha($hasta)->startOfDay();
        $semanas = [];

        while ($lunes->lessThanOrEqualTo($fin)) {
            $semanas[] = $lunes->toDateString();
            $lunes = $lunes->addWeek();
        }

        return $semanas;
    }

    /** Número de semana ISO del lunes dado. */
    public static function numero(string $lunes): int
    {
        return self::fecha($lunes)->isoWeek();
    }

    /** La semana está cerrada cuando su domingo es anterior a hoy. */
    public static function cerrada(string $lunes, ?CarbonImmutable $hoy = null): bool
    {
        $hoy ??= self::hoy();

        return self::fecha($lunes)->addDays(6)->lessThan($hoy->setTimezone(self::ZONA)->startOfDay());
    }

    /** @return array{lunes: string, domingo: string} */
    public static function rango(string $lunes): array
    {
        $inicio = self::fecha($lunes)->startOfWeek(CarbonInterface::MONDAY);

        return ['lunes' => $inicio->toDateString(), 'domingo' => $inicio->addDays(6)->toDateString()];
    }

    private static function fecha(string|CarbonInterface $fecha): CarbonImmutable
    {
        $texto = $fecha instanceof CarbonInterface ? $fecha->format('Y-m-d') : $fecha;

        return CarbonImmutable::parse($texto, self::ZONA)->startOfDay();
    }
}
