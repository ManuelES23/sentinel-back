<?php

namespace App\Services\ActivosFijos;

/** Resultado de depreciar un activo en un mes. */
final class ResultadoDepreciacion
{
    public const DEPRECIANDO = 'depreciando';
    public const TOTALMENTE_DEPRECIADO = 'totalmente_depreciado';
    public const POR_INICIAR = 'por_iniciar';

    public function __construct(
        public readonly string $estado,
        public readonly int $mesesTranscurridos,
        public readonly int $vidaMeses,
        public readonly int $cuotaCentavos,
        public readonly int $acumuladoCentavos,
        public readonly int $libroCentavos,
    ) {
    }

    public static function decimal(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }

    public function cuota(): string
    {
        return self::decimal($this->cuotaCentavos);
    }

    public function acumulado(): string
    {
        return self::decimal($this->acumuladoCentavos);
    }

    public function valorEnLibros(): string
    {
        return self::decimal($this->libroCentavos);
    }
}
