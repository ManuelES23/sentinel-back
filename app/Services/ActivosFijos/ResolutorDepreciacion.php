<?php

namespace App\Services\ActivosFijos;

use App\Models\FixedAsset;

/**
 * Valores efectivos de depreciación de un activo y su resumen de hoy.
 *
 * Tasa: la del activo, si no la del subtipo, si no la del tipo, si no
 * 100 ÷ vida útil en años. Residual: el del activo, el del subtipo, el del
 * tipo o 0. Inicio: `depreciation_start` o el mes de la fecha de compra.
 *
 * Nunca entrega a la calculadora una tasa <= 0 ni un residual fuera de [0, 100):
 * esos valores se saltan y se pasa al siguiente nivel.
 */
class ResolutorDepreciacion
{
    public const SIN_FECHA = 'sin_fecha';
    public const SIN_VALOR = 'sin_valor';
    public const SIN_TASA = 'sin_tasa';

    public function __construct(private CalculadoraDepreciacion $calculadora)
    {
    }

    /**
     * Requiere `category` y `subcategory` cargadas para no consultar de más.
     *
     * @return array{datos: ?DatosDepreciacion, motivo: ?string}
     */
    public function resolver(FixedAsset $activo): array
    {
        $inicio = $activo->depreciation_start ?? $activo->purchase_date;
        if (! $inicio) {
            return ['datos' => null, 'motivo' => self::SIN_FECHA];
        }

        $valor = (float) $activo->purchase_value;
        if ($valor <= 0) {
            return ['datos' => null, 'motivo' => self::SIN_VALOR];
        }

        $tasa = $this->tasaEfectiva($activo);
        if ($tasa === null) {
            return ['datos' => null, 'motivo' => self::SIN_TASA];
        }

        return [
            'datos' => DatosDepreciacion::crear($valor, $tasa, $this->residualEfectivo($activo), $inicio),
            'motivo' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function resumen(FixedAsset $activo, ?\DateTimeInterface $hoy = null): array
    {
        $vacio = [
            'estado' => 'baja', 'cuota_mensual' => null, 'acumulado' => null, 'valor_en_libros' => null,
            'tasa' => null, 'residual_pct' => null, 'inicio' => null, 'motivo' => null,
        ];

        if ($activo->status === 'baja') {
            return $vacio;
        }

        ['datos' => $datos, 'motivo' => $motivo] = $this->resolver($activo);
        if (! $datos) {
            return array_merge($vacio, ['estado' => 'sin_depreciar', 'motivo' => $motivo]);
        }

        $resultado = $this->calculadora->calcular($datos, $hoy ?? now());

        return [
            'estado' => $resultado->estado,
            'cuota_mensual' => $resultado->cuota(),
            'acumulado' => $resultado->acumulado(),
            'valor_en_libros' => $resultado->valorEnLibros(),
            'tasa' => round($datos->tasaAnual, 2),
            'residual_pct' => round($datos->residualPct, 2),
            'inicio' => $datos->inicio->toDateString(),
            'motivo' => null,
        ];
    }

    private function tasaEfectiva(FixedAsset $activo): ?float
    {
        foreach ([$activo->depreciation_rate, $activo->subcategory?->depreciation_rate, $activo->category?->depreciation_rate] as $tasa) {
            if ($tasa !== null && (float) $tasa > 0) {
                return (float) $tasa;
            }
        }

        return $activo->useful_life_years > 0 ? 100 / $activo->useful_life_years : null;
    }

    private function residualEfectivo(FixedAsset $activo): float
    {
        foreach ([$activo->salvage_percent, $activo->subcategory?->salvage_percent, $activo->category?->salvage_percent] as $residual) {
            if ($residual !== null && (float) $residual >= 0 && (float) $residual < 100) {
                return (float) $residual;
            }
        }

        return 0.0;
    }
}
