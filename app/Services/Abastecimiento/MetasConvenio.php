<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use Illuminate\Support\Collection;

/**
 * Metas semanales de un convenio y su volumen pactado. Es de solo lectura y
 * sin estado: en modo semanal lee las filas guardadas y en modo total reparte
 * el volumen entre las semanas del convenio. Los métodos son estáticos para
 * llamarlos por convenio dentro de un listado sin construir nada.
 *
 * Si el convenio usa calibres, cada calibre tiene sus propias metas y el total
 * por semana es la suma de los calibres.
 */
class MetasConvenio
{
    /**
     * Lunes de la semana => cantidad, en orden cronológico. Usa la relación
     * `metas` si ya está cargada; si no, consulta una sola vez. Con calibres,
     * es el total por semana (suma de los calibres).
     *
     * @return array<string, float>
     */
    public static function metas(Convenio $convenio): array
    {
        if ($convenio->usa_calibres) {
            $total = [];
            foreach (self::metasPorCalibre($convenio) as $semanas) {
                foreach ($semanas as $lunes => $cantidad) {
                    $total[$lunes] = round(($total[$lunes] ?? 0) + $cantidad, 2);
                }
            }
            ksort($total);

            return $total;
        }

        return $convenio->modo_volumen === 'total'
            ? self::repartir($convenio, (float) $convenio->volumen_total)
            : self::guardadas($convenio);
    }

    /**
     * calibre_id => (lunes => cantidad). Vacío si el convenio no usa calibres.
     * En modo total cada calibre reparte su propio volumen; en semanal se
     * agrupan las metas guardadas por calibre.
     *
     * @return array<int, array<string, float>>
     */
    public static function metasPorCalibre(Convenio $convenio): array
    {
        if (! $convenio->usa_calibres) {
            return [];
        }

        $porCalibre = [];
        if ($convenio->modo_volumen === 'total') {
            foreach (self::calibres($convenio) as $fila) {
                $porCalibre[(int) $fila->calibre_id] = self::repartir($convenio, (float) $fila->volumen_total);
            }

            return $porCalibre;
        }

        foreach (self::filasMetas($convenio) as $fila) {
            if ($fila->calibre_id === null) {
                continue;
            }
            $porCalibre[(int) $fila->calibre_id][$fila->semana->format('Y-m-d')] = (float) $fila->cantidad;
        }
        foreach ($porCalibre as &$semanas) {
            ksort($semanas);
        }
        unset($semanas);

        return $porCalibre;
    }

    /** Suma de las metas (cantidad en la unidad del convenio). */
    public static function pactado(Convenio $convenio): float
    {
        return round(array_sum(self::metas($convenio)), 2);
    }

    /** @return array<int, float> calibre_id => cantidad pactada */
    public static function pactadoPorCalibre(Convenio $convenio): array
    {
        return array_map(fn (array $semanas) => round(array_sum($semanas), 2), self::metasPorCalibre($convenio));
    }

    /** @return array<string, float> */
    private static function guardadas(Convenio $convenio): array
    {
        $metas = [];
        foreach (self::filasMetas($convenio) as $fila) {
            $metas[$fila->semana->format('Y-m-d')] = (float) $fila->cantidad;
        }
        ksort($metas);

        return $metas;
    }

    /** Filas de metas: la relación si ya está cargada; si no, una sola consulta. */
    private static function filasMetas(Convenio $convenio): Collection
    {
        return $convenio->relationLoaded('metas')
            ? $convenio->metas
            : $convenio->metas()->get(['semana', 'cantidad', 'calibre_id']);
    }

    /** Calibres pactados: la relación si ya está cargada; si no, una sola consulta. */
    private static function calibres(Convenio $convenio): Collection
    {
        return $convenio->relationLoaded('calibres')
            ? $convenio->calibres
            : $convenio->calibres()->get(['calibre_id', 'volumen_total']);
    }

    /**
     * Reparte un volumen parejo entre las semanas del convenio y deja el
     * resto en la última. En cajas el reparto es en piezas enteras; en kilos,
     * a dos decimales. Se calcula en centavos enteros para no arrastrar error de coma flotante.
     *
     * @return array<string, float>
     */
    private static function repartir(Convenio $convenio, float $volumen): array
    {
        $semanas = Semanas::entre($convenio->fecha_inicio->format('Y-m-d'), $convenio->fecha_fin->format('Y-m-d'));
        $n = count($semanas);
        $centavos = (int) round($volumen * 100);

        if ($n === 0 || $centavos <= 0) {
            return [];
        }

        $base = $convenio->unidad === 'caja'
            ? intdiv(intdiv($centavos, 100), $n) * 100
            : intdiv($centavos, $n);
        $ultima = $centavos - $base * ($n - 1);

        $metas = [];
        foreach ($semanas as $i => $lunes) {
            $metas[$lunes] = (float) (($i === $n - 1 ? $ultima : $base) / 100);
        }

        return $metas;
    }
}
