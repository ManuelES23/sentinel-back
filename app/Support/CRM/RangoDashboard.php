<?php

namespace App\Support\CRM;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Rango de fechas del Dashboard del CRM y el rango anterior con el que se
 * compara (spec 2026-10-08 §5.1). Los periodos en curso se comparan contra
 * el periodo anterior recortado a los mismos días transcurridos, para no
 * comparar 8 días de octubre contra septiembre completo.
 */
final class RangoDashboard
{
    public const PERIODOS = ['mes_actual', 'mes_anterior', 'trimestre', 'anio', 'personalizado'];

    private const MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    private function __construct(
        public readonly CarbonImmutable $inicio,
        public readonly CarbonImmutable $fin,
        public readonly CarbonImmutable $inicioAnterior,
        public readonly CarbonImmutable $finAnterior,
        public readonly string $etiquetaAnterior,
    ) {}

    public static function desdePeriodo(string $periodo, ?string $desde = null, ?string $hasta = null): self
    {
        $hoy = CarbonImmutable::now();

        return match ($periodo) {
            'mes_actual' => new self(
                $hoy->startOfMonth(),
                $hoy->endOfMonth(),
                $hoy->subMonthNoOverflow()->startOfMonth(),
                $hoy->subMonthNoOverflow()->endOfDay(),
                'vs '.self::mesCorto($hoy->subMonthNoOverflow()).' al día '.$hoy->subMonthNoOverflow()->day,
            ),
            'mes_anterior' => new self(
                $hoy->subMonthNoOverflow()->startOfMonth(),
                $hoy->subMonthNoOverflow()->endOfMonth(),
                $hoy->subMonthsNoOverflow(2)->startOfMonth(),
                $hoy->subMonthsNoOverflow(2)->endOfMonth(),
                'vs '.self::mesCorto($hoy->subMonthsNoOverflow(2)),
            ),
            'trimestre' => new self(
                $hoy->startOfQuarter(),
                $hoy->endOfQuarter(),
                $hoy->subQuarterNoOverflow()->startOfQuarter(),
                $hoy->subQuarterNoOverflow()->endOfDay(),
                'vs trimestre anterior',
            ),
            'anio' => new self(
                $hoy->startOfYear(),
                $hoy->endOfYear(),
                $hoy->subYearNoOverflow()->startOfYear(),
                $hoy->subYearNoOverflow()->endOfDay(),
                'vs '.($hoy->year - 1).' a la misma fecha',
            ),
            'personalizado' => self::personalizado($desde, $hasta),
            default => throw new InvalidArgumentException("Periodo inválido: {$periodo}"),
        };
    }

    private static function personalizado(?string $desde, ?string $hasta): self
    {
        if ($desde === null || $hasta === null) {
            throw new InvalidArgumentException('El periodo personalizado requiere desde y hasta.');
        }

        $inicio = CarbonImmutable::parse($desde)->startOfDay();
        $fin = CarbonImmutable::parse($hasta)->endOfDay();
        $dias = (int) round($inicio->diffInDays($fin->startOfDay(), true)) + 1;
        $inicioAnterior = $inicio->subDays($dias);
        $finAnterior = $inicio->subDay()->endOfDay();

        return new self(
            $inicio,
            $fin,
            $inicioAnterior,
            $finAnterior,
            'vs '.self::fechaCorta($inicioAnterior).' – '.self::fechaCorta($finAnterior),
        );
    }

    public function dias(): int
    {
        return (int) round($this->inicio->diffInDays($this->fin->startOfDay(), true)) + 1;
    }

    public function enCurso(): bool
    {
        return $this->fin->greaterThanOrEqualTo(CarbonImmutable::now());
    }

    /** @return array<int, CarbonImmutable> inicio de cada uno de los últimos $n meses, del más antiguo al más reciente. */
    public function ultimosMeses(int $n): array
    {
        $ultimo = $this->fin->min(CarbonImmutable::now())->startOfMonth();
        $meses = [];
        for ($k = $n - 1; $k >= 0; $k--) {
            $meses[] = $ultimo->subMonthsNoOverflow($k);
        }

        return $meses;
    }

    private static function mesCorto(CarbonImmutable $fecha): string
    {
        return self::MESES_CORTOS[$fecha->month - 1];
    }

    private static function fechaCorta(CarbonImmutable $fecha): string
    {
        return $fecha->day.' '.self::mesCorto($fecha);
    }
}
