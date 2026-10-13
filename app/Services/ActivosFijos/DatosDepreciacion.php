<?php

namespace App\Services\ActivosFijos;

use Carbon\CarbonImmutable;

/** Datos efectivos de un activo para depreciarlo. El dinero va en centavos enteros. */
final class DatosDepreciacion
{
    public function __construct(
        public readonly int $valorCentavos,
        public readonly float $tasaAnual,
        public readonly float $residualPct,
        public readonly CarbonImmutable $inicio,
    ) {
    }

    public static function crear(string|float|int $valorCompra, float $tasaAnual, float $residualPct, \DateTimeInterface $inicio): self
    {
        return new self(
            (int) round(((float) $valorCompra) * 100),
            $tasaAnual,
            $residualPct,
            CarbonImmutable::instance($inicio)->startOfMonth(),
        );
    }

    /** Valor de compra menos el residual: lo que se reparte en la vida útil. */
    public function baseCentavos(): int
    {
        return $this->valorCentavos - (int) round($this->valorCentavos * $this->residualPct / 100);
    }

    /** Vida útil en meses: 100 % anual = 12 meses, 20 % = 60, 10 % = 120. */
    public function vidaMeses(): int
    {
        return max(1, (int) round(1200 / $this->tasaAnual));
    }
}
