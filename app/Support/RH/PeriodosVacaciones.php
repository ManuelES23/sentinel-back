<?php

namespace App\Support\RH;

use App\Services\VacationCalculatorService;
use Carbon\Carbon;

/**
 * Tabla visual de periodos de vacaciones: cada aniversario cumplido asigna los días que marca la LFT y lo que
 * se toma se descuenta del periodo más antiguo que aún tenga días (los días son acumulables). No cambia el
 * saldo: solo lo reparte por periodo para ver cuántos días quedan y de cuál año.
 *
 * Se consume en este orden: lo ya tomado (aprobado + historial de RH), los ajustes negativos de RH, lo que otras
 * solicitudes tienen en revisión y, al final, la solicitud que se está revisando. Lo que rebasa todo lo asignado
 * es adelanto. Un ajuste positivo de RH es un renglón aparte que va primero (como días asignados).
 */
final class PeriodosVacaciones
{
    /**
     * @return array{
     *     filas: list<array{tipo: string, numero: ?int, inicio: ?string, fin: ?string, asignados: int, tomados: int, ajuste: int, en_revision: int, esta: int, quedan: int}>,
     *     adelanto: int,
     *     total: array{asignados: int, tomados: int, ajuste: int, en_revision: int, esta: int, quedan: int}
     * }
     */
    public static function tabla(?Carbon $ingreso, Carbon $hoy, int $ajustes, int $usados, int $otrasEnRevision, int $estaSolicitud): array
    {
        $vacia = ['asignados' => 0, 'tomados' => 0, 'ajuste' => 0, 'en_revision' => 0, 'esta' => 0, 'quedan' => 0];
        if ($ingreso === null) {
            return ['filas' => [], 'adelanto' => 0, 'total' => $vacia];
        }

        $ingreso = $ingreso->copy()->startOfDay();
        $filas = [];

        // Un ajuste positivo de RH es una fila aparte y va primero (lo más antiguo); uno negativo se descuenta
        // de los periodos como lo tomado, en su propia columna.
        if ($ajustes > 0) {
            $filas[] = self::fila('ajuste', null, null, null, $ajustes);
        }

        $cumplidos = max(0, (int) $ingreso->diffInYears($hoy));
        for ($anio = 1; $anio <= $cumplidos; $anio++) {
            $filas[] = self::fila(
                'periodo',
                $anio,
                $ingreso->copy()->addYearsNoOverflow($anio)->format('Y-m-d'),
                $ingreso->copy()->addYearsNoOverflow($anio + 1)->subDay()->format('Y-m-d'),
                VacationCalculatorService::calculateEntitledDays($anio),
            );
        }

        $adelanto = 0;
        foreach ([['tomados', $usados], ['ajuste', max(0, -$ajustes)], ['en_revision', $otrasEnRevision], ['esta', $estaSolicitud]] as [$columna, $dias]) {
            $dias = max(0, $dias);
            foreach ($filas as &$fila) {
                if ($dias === 0) {
                    break;
                }
                $toma = min($dias, max(0, $fila['quedan']));
                $fila[$columna] += $toma;
                $fila['quedan'] -= $toma;
                $dias -= $toma;
            }
            unset($fila);
            $adelanto += $dias;
        }

        $total = $vacia;
        foreach ($filas as $fila) {
            foreach (array_keys($total) as $clave) {
                $total[$clave] += $fila[$clave];
            }
        }

        return ['filas' => $filas, 'adelanto' => $adelanto, 'total' => $total];
    }

    private static function fila(string $tipo, ?int $numero, ?string $inicio, ?string $fin, int $asignados): array
    {
        return [
            'tipo' => $tipo,
            'numero' => $numero,
            'inicio' => $inicio,
            'fin' => $fin,
            'asignados' => $asignados,
            'tomados' => 0,
            'ajuste' => 0,
            'en_revision' => 0,
            'esta' => 0,
            'quedan' => $asignados,
        ];
    }
}
