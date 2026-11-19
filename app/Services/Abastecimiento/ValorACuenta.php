<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Recepcion;

/**
 * Valor a cuenta de una recepción: lo que vale al precio vigente de su fecha, ya con el
 * % del productor y SIN descuento por rezaga (el excedente se descuenta hasta la
 * liquidación). Es puro: usa los precios de `$convenio->precios` y los renglones de
 * `$recepcion->renglones` (con `calibre`), que quien llama debe haber cargado.
 *
 * Cada parte (la cabecera en un convenio clásico, cada renglón en uno por calibre) vale
 * `round(round(base × precio, 2) × pct / 100, 2)`; la misma cuenta de una parte en la
 * calculadora de liquidaciones, que recorre las mismas partes.
 */
class ValorACuenta
{
    /**
     * @return list<array{calibre_id: ?int, cantidad: ?float, kilos: ?float, rezaga_kg: ?float, calibre: ?string}>
     */
    public static function partes(Convenio $convenio, Recepcion $recepcion): array
    {
        $numero = fn ($v) => $v === null ? null : (float) $v;

        if (! $convenio->usa_calibres) {
            return [[
                'calibre_id' => null,
                'cantidad' => $numero($recepcion->cantidad),
                'kilos' => $numero($recepcion->kilos),
                'rezaga_kg' => $numero($recepcion->rezaga_kg),
                'calibre' => null,
            ]];
        }

        return $recepcion->renglones->map(fn ($c) => [
            'calibre_id' => (int) $c->calibre_id,
            'cantidad' => $numero($c->cantidad),
            'kilos' => $numero($c->kilos),
            'rezaga_kg' => $numero($c->rezaga_kg),
            'calibre' => $c->calibre?->nombre,
        ])->values()->all();
    }

    /**
     * @return array{valor: float, motivo: ?string, sin_valor: bool}
     */
    public static function de(Convenio $convenio, Recepcion $recepcion): array
    {
        $partes = self::partes($convenio, $recepcion);
        if ($partes === []) {
            return self::sinValor('Sin renglones por calibre');
        }

        $precios = $convenio->relationLoaded('precios') ? $convenio->precios : $convenio->precios()->get();
        $fecha = $recepcion->fecha->format('Y-m-d');
        $porKilo = $convenio->unidad === 'kg';

        $valor = 0.0;
        $sinPrecio = [];
        foreach ($partes as $p) {
            $precio = PrecioVigente::enColeccion($precios, (int) $recepcion->tipo_carga_id, $fecha, $p['calibre_id']);
            if ($precio === null) {
                $sinPrecio[] = $p['calibre'] ?? 'calibre #'.$p['calibre_id'];

                continue;
            }
            $pct = $precio->porcentaje_productor !== null ? (float) $precio->porcentaje_productor : 100.0;
            $base = $porKilo ? (float) $p['kilos'] : (float) $p['cantidad'];
            $valor += round(round($base * (float) $precio->precio, 2) * $pct / 100, 2);
        }

        if ($sinPrecio !== []) {
            return self::sinValor($convenio->usa_calibres
                ? 'Sin precio vigente: '.implode(', ', array_values(array_unique($sinPrecio)))
                : 'Sin precio vigente');
        }
        if ($porKilo && self::alguna($partes, 'kilos')) {
            return self::sinValor('Faltan los kilos');
        }

        $motivo = (float) $convenio->porcentaje_rezaga > 0 && self::alguna($partes, 'rezaga_kg')
            ? 'Falta la rezaga'
            : null;

        return ['valor' => round($valor, 2), 'motivo' => $motivo, 'sin_valor' => false];
    }

    /** @return array{valor: float, motivo: string, sin_valor: bool} */
    private static function sinValor(string $motivo): array
    {
        return ['valor' => 0.0, 'motivo' => $motivo, 'sin_valor' => true];
    }

    /** ¿Alguna parte tiene nulo ese dato? (0 es un dato válido.) */
    private static function alguna(array $partes, string $campo): bool
    {
        foreach ($partes as $p) {
            if ($p[$campo] === null) {
                return true;
            }
        }

        return false;
    }
}
