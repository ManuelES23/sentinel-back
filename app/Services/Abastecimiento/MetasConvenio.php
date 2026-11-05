<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;

/**
 * Metas semanales de un convenio y su volumen pactado. Es de solo lectura y
 * sin estado: en modo semanal lee las filas guardadas y en modo total reparte
 * el volumen entre las semanas del convenio. Los métodos son estáticos para
 * llamarlos por convenio dentro de un listado sin construir nada.
 */
class MetasConvenio
{
    /**
     * Lunes de la semana => cantidad, en orden cronológico. Usa la relación
     * `metas` si ya está cargada; si no, consulta una sola vez.
     *
     * @return array<string, float>
     */
    public static function metas(Convenio $convenio): array
    {
        return $convenio->modo_volumen === 'total'
            ? self::repartirTotal($convenio)
            : self::guardadas($convenio);
    }

    /** Suma de las metas (cantidad en la unidad del convenio). */
    public static function pactado(Convenio $convenio): float
    {
        return round(array_sum(self::metas($convenio)), 2);
    }

    /** @return array<string, float> */
    private static function guardadas(Convenio $convenio): array
    {
        $filas = $convenio->relationLoaded('metas') ? $convenio->metas : $convenio->metas()->get(['semana', 'cantidad']);

        $metas = [];
        foreach ($filas as $fila) {
            $metas[$fila->semana->format('Y-m-d')] = (float) $fila->cantidad;
        }
        ksort($metas);

        return $metas;
    }

    /**
     * Reparte `volumen_total` parejo entre las semanas del convenio y deja el
     * resto en la última. En cajas el reparto es en piezas enteras; en kilos,
     * a dos decimales. Se calcula en centavos enteros para no arrastrar error de coma flotante.
     *
     * @return array<string, float>
     */
    private static function repartirTotal(Convenio $convenio): array
    {
        $semanas = Semanas::entre($convenio->fecha_inicio->format('Y-m-d'), $convenio->fecha_fin->format('Y-m-d'));
        $n = count($semanas);
        $centavos = (int) round(((float) $convenio->volumen_total) * 100);

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
