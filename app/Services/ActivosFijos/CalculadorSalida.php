<?php

namespace App\Services\ActivosFijos;

use App\Models\FixedAsset;

/**
 * Cifras de una salida: valor en libros a la fecha (con la calculadora de la fase 4, mes completo
 * de la salida) y utilidad o pérdida. Todo en centavos enteros; se devuelve con 2 decimales.
 */
class CalculadorSalida
{
    private const RELACIONES = [
        'category:id,name,depreciation_rate,salvage_percent',
        'subcategory:id,name,depreciation_rate,salvage_percent',
    ];

    public function __construct(
        private ResolutorDepreciacion $resolutor,
        private CalculadoraDepreciacion $calculadora,
    ) {
    }

    /**
     * Sin tasa o sin fecha el libro es el valor de compra (no se puede depreciar); sin valor de
     * compra es 0. Ninguno de los tres casos falla: avisan en `warnings`.
     *
     * @return array{purchase_value: ?string, accumulated_depreciation: string, book_value: string, warnings: list<array{code: string, message: string}>}
     */
    public function valores(FixedAsset $activo, \DateTimeInterface $fecha): array
    {
        $activo->loadMissing(self::RELACIONES);
        ['datos' => $datos, 'motivo' => $motivo] = $this->resolutor->resolver($activo);

        if ($datos) {
            $resultado = $this->calculadora->calcular($datos, $fecha);

            return [
                'purchase_value' => ResultadoDepreciacion::decimal($datos->valorCentavos),
                'accumulated_depreciation' => $resultado->acumulado(),
                'book_value' => $resultado->valorEnLibros(),
                'warnings' => [],
            ];
        }

        $centavos = (int) round(((float) $activo->purchase_value) * 100);
        if ($centavos <= 0) {
            return [
                'purchase_value' => null,
                'accumulated_depreciation' => '0.00',
                'book_value' => '0.00',
                'warnings' => [['code' => ResolutorDepreciacion::SIN_VALOR, 'message' => 'El activo no tiene valor de compra: su valor en libros se toma como 0.']],
            ];
        }

        $mensaje = $motivo === ResolutorDepreciacion::SIN_FECHA
            ? 'El activo no tiene fecha de compra ni de inicio de depreciación: se usa el valor de compra como valor en libros.'
            : 'El activo no tiene tasa de depreciación: se usa el valor de compra como valor en libros.';

        return [
            'purchase_value' => ResultadoDepreciacion::decimal($centavos),
            'accumulated_depreciation' => '0.00',
            'book_value' => ResultadoDepreciacion::decimal($centavos),
            'warnings' => [['code' => $motivo ?? ResolutorDepreciacion::SIN_TASA, 'message' => $mensaje]],
        ];
    }

    /** Venta = precio − libro; desecho, siniestro y donación = −libro; traspaso = 0. */
    public function resultado(string $tipo, string $libro, ?string $precio): string
    {
        $libroCentavos = (int) round(((float) $libro) * 100);

        return ResultadoDepreciacion::decimal(match ($tipo) {
            'venta' => (int) round(((float) $precio) * 100) - $libroCentavos,
            'traspaso' => 0,
            default => -$libroCentavos,
        });
    }

    /**
     * Tasa y residual efectivos del activo, para copiarlos a un activo nuevo (traspaso).
     *
     * @return array{0: ?float, 1: ?float}
     */
    public function tasaYResidual(FixedAsset $activo): array
    {
        $activo->loadMissing(self::RELACIONES);
        ['datos' => $datos] = $this->resolutor->resolver($activo);

        return $datos ? [round($datos->tasaAnual, 2), round($datos->residualPct, 2)] : [null, null];
    }
}
