<?php

namespace App\Services\RH;

use App\Models\Holiday;
use Carbon\Carbon;

/**
 * Días de descanso obligatorio del art. 74 de la LFT. No incluye las jornadas
 * electorales: esas las agrega RH a mano cuando apliquen.
 */
class FestivosOficiales
{
    /** @return list<array{date: string, name: string}> */
    public function paraAnio(int $anio): array
    {
        $lunes = fn (int $mes, int $n) => Carbon::create($anio, $mes, 1)->nthOfMonth($n, Carbon::MONDAY)->format('Y-m-d');

        $dias = [
            ['date' => sprintf('%d-01-01', $anio), 'name' => 'Año Nuevo'],
            ['date' => $lunes(2, 1), 'name' => 'Día de la Constitución'],
            ['date' => $lunes(3, 3), 'name' => 'Natalicio de Benito Juárez'],
            ['date' => sprintf('%d-05-01', $anio), 'name' => 'Día del Trabajo'],
            ['date' => sprintf('%d-09-16', $anio), 'name' => 'Día de la Independencia'],
        ];

        if ($anio >= 2024 && ($anio - 2024) % 6 === 0) {
            $dias[] = ['date' => sprintf('%d-10-01', $anio), 'name' => 'Transmisión del Poder Ejecutivo Federal'];
        }

        $dias[] = ['date' => $lunes(11, 3), 'name' => 'Día de la Revolución'];
        $dias[] = ['date' => sprintf('%d-12-25', $anio), 'name' => 'Navidad'];

        return $dias;
    }

    /** Crea los que falten para todas las empresas. Idempotente. Devuelve cuántos creó. */
    public function cargar(int $anio): int
    {
        $creados = 0;
        foreach ($this->paraAnio($anio) as $dia) {
            $festivo = Holiday::firstOrCreate(
                ['date' => $dia['date'], 'enterprise_id' => null],
                ['name' => $dia['name'], 'is_official' => true],
            );
            $creados += $festivo->wasRecentlyCreated ? 1 : 0;
        }

        return $creados;
    }
}
