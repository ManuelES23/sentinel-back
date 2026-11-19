<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Recepcion;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Cálculo de una liquidación (spec 6.5, plan 2 ruling 1). Es pura: no consulta
 * ni escribe en la base de datos; los precios salen de la relación `precios`
 * del convenio (cárgala antes para no consultar por recepción).
 *
 * Cada renglón se redondea a 2 decimales (`subtotal`, `descuento`, `a_pagar`) y
 * `bruto`, `descuento` y `monto_calculado` son la suma de esos renglones, así
 * que los totales siempre cuadran con el detalle. Primero se descuenta la rezaga
 * excedente sobre el bruto y después se aplica el % del productor.
 *
 * Convenio por calibre (spec de calibres, C10): un renglón por (recepción, calibre)
 * con `calibre_id` y el precio de ese calibre; la rezaga sigue siendo UNA sola por
 * liquidación (Σ rezaga ÷ Σ kilos de las cabeceras, que son la suma de sus renglones)
 * y el descuento se reparte en proporción al subtotal de cada renglón. Carga antes
 * `renglones.calibre` de las recepciones (quien llama a la calculadora).
 */
class CalculadoraLiquidacion
{
    /**
     * @param  Collection<int, Recepcion>  $recepciones  recepciones del convenio a liquidar
     * @return array{
     *   detalles: array<int, array{recepcion_id: int, calibre_id?: int, cantidad: float, kilos: float, rezaga_kg: float, precio: float, porcentaje_productor: float, subtotal: float, descuento: float, a_pagar: float}>,
     *   cantidad: float, kilos: float, bruto: float, rezaga_real_pct: float, rezaga_aceptada_pct: float,
     *   descuento: float, monto_calculado: float, monto_ajustado: float|null, motivo_ajuste: string|null, monto_final: float
     * }
     *
     * @throws ValidationException `recepcion_ids` si una recepción no tiene precio vigente; `motivo_ajuste` si hay ajuste sin motivo
     */
    public static function calcular(Convenio $convenio, Collection $recepciones, ?float $montoAjustado = null, ?string $motivoAjuste = null): array
    {
        $motivo = $motivoAjuste === null ? null : trim($motivoAjuste);
        if ($montoAjustado !== null && ($motivo === null || $motivo === '')) {
            throw ValidationException::withMessages([
                'motivo_ajuste' => 'Indica el motivo del ajuste manual.',
            ]);
        }

        $precios = $convenio->relationLoaded('precios') ? $convenio->precios : $convenio->precios()->get();
        $porKilo = $convenio->unidad === 'kg';

        // Rezaga real = Σ rezaga ÷ Σ kilos, solo de las recepciones que tienen kilos.
        $kilosConKilos = 0.0;
        $rezagaConKilos = 0.0;
        foreach ($recepciones as $r) {
            $kilos = (float) $r->kilos;
            if ($kilos > 0) {
                $kilosConKilos += $kilos;
                $rezagaConKilos += (float) $r->rezaga_kg;
            }
        }
        $rezagaReal = $kilosConKilos > 0 ? $rezagaConKilos / $kilosConKilos * 100 : 0.0;
        $aceptada = (float) $convenio->porcentaje_rezaga;
        $excedente = max(0.0, $rezagaReal - $aceptada) / 100;

        $porCalibre = (bool) $convenio->usa_calibres;
        $detalles = [];
        $sinPrecio = [];
        $sinRenglones = [];
        foreach ($recepciones as $r) {
            $folio = $r->folio ?? '#'.$r->id;
            $fecha = $r->fecha->format('Y-m-d');

            // Convenio por calibre: un renglón de liquidación por (recepción, calibre), cada uno con su
            // precio (el especial del calibre o, si no hay, el general). La cabecera es la suma de sus renglones.
            $partes = $porCalibre
                ? $r->renglones->map(fn ($c) => [(int) $c->calibre_id, $c->cantidad, $c->kilos, $c->rezaga_kg, $c->calibre?->nombre])->all()
                : [[null, $r->cantidad, $r->kilos, $r->rezaga_kg, null]];
            if ($partes === []) {
                $sinRenglones[] = $folio;

                continue;
            }

            foreach ($partes as [$calibreId, $cantidad, $kilos, $rezaga, $nombreCalibre]) {
                $precio = PrecioVigente::enColeccion($precios, (int) $r->tipo_carga_id, $fecha, $calibreId);
                if ($precio === null) {
                    $sinPrecio[] = $calibreId === null ? $folio : $folio.' ('.($nombreCalibre ?? 'calibre #'.$calibreId).')';

                    continue;
                }

                $pct = $precio->porcentaje_productor !== null ? (float) $precio->porcentaje_productor : 100.0;
                $base = $porKilo ? (float) $kilos : (float) $cantidad;
                $subtotal = round($base * (float) $precio->precio, 2);
                $descuento = round($subtotal * $excedente, 2);

                $detalles[] = [
                    'recepcion_id' => (int) $r->id,
                ] + ($porCalibre ? ['calibre_id' => $calibreId] : []) + [
                    'cantidad' => round((float) $cantidad, 2),
                    'kilos' => round((float) $kilos, 2),
                    'rezaga_kg' => round((float) $rezaga, 2),
                    'precio' => round((float) $precio->precio, 4),
                    'porcentaje_productor' => round($pct, 2),
                    'subtotal' => $subtotal,
                    'descuento' => $descuento,
                    'a_pagar' => round(($subtotal - $descuento) * $pct / 100, 2),
                ];
            }
        }

        if ($sinRenglones !== []) {
            throw ValidationException::withMessages([
                'recepcion_ids' => 'Las recepciones '.implode(', ', $sinRenglones).' no tienen renglones por calibre.',
            ]);
        }
        if ($sinPrecio !== []) {
            throw ValidationException::withMessages([
                'recepcion_ids' => 'Las recepciones '.implode(', ', $sinPrecio).' no tienen un precio vigente a su fecha.',
            ]);
        }

        $suma = fn (string $campo) => round(array_sum(array_column($detalles, $campo)), 2);
        $montoCalculado = $suma('a_pagar');

        return [
            'detalles' => $detalles,
            'cantidad' => $suma('cantidad'),
            'kilos' => $suma('kilos'),
            'bruto' => $suma('subtotal'),
            'rezaga_real_pct' => round($rezagaReal, 4),
            'rezaga_aceptada_pct' => round($aceptada, 4),
            'descuento' => $suma('descuento'),
            'monto_calculado' => $montoCalculado,
            'monto_ajustado' => $montoAjustado === null ? null : round($montoAjustado, 2),
            'motivo_ajuste' => $montoAjustado === null ? null : $motivo,
            'monto_final' => $montoAjustado === null ? $montoCalculado : round($montoAjustado, 2),
        ];
    }
}
