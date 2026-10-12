<?php

namespace App\Support\Inventario;

final class ResultadoMovimiento
{
    public function __construct(public readonly float $cantidad, public readonly float $importe)
    {
    }

    public static function vacio(): self
    {
        return new self(0.0, 0.0);
    }

    public function costoUnitario(): float
    {
        return $this->cantidad > 0.0001 ? $this->importe / $this->cantidad : 0.0;
    }

    public function mas(self $otro): self
    {
        return new self(round($this->cantidad + $otro->cantidad, 4), $this->importe + $otro->importe);
    }
}
