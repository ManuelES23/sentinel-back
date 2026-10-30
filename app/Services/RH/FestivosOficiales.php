<?php

namespace App\Services\RH;

use App\Models\Holiday;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Festivos oficiales para cargar en una empresa.
 * - México: días de descanso obligatorio del art. 74 de la LFT. No incluye las jornadas
 *   electorales: esas las agrega RH a mano cuando apliquen.
 * - Estados Unidos: los 6 que suele dar la empresa privada (no los 11 federales). Uno de fecha
 *   fija que cae en sábado se recorre al viernes y en domingo al lunes («observado»).
 */
class FestivosOficiales
{
    public const MEXICO = 'mx';

    public const ESTADOS_UNIDOS = 'us';

    public const PAISES = [self::MEXICO, self::ESTADOS_UNIDOS];

    /** @return list<array{date: string, name: string}> */
    public function paraAnio(int $anio, string $pais = self::MEXICO): array
    {
        return match ($pais) {
            self::MEXICO => $this->mexico($anio),
            self::ESTADOS_UNIDOS => $this->estadosUnidos($anio),
            default => throw new InvalidArgumentException("País sin festivos oficiales: {$pais}"),
        };
    }

    /** Crea en la empresa los que falten. Idempotente. Devuelve cuántos creó. */
    public function cargar(int $anio, int $empresaId, string $pais = self::MEXICO): int
    {
        $creados = 0;
        foreach ($this->paraAnio($anio, $pais) as $dia) {
            $festivo = Holiday::firstOrCreate(
                ['date' => $dia['date'], 'enterprise_id' => $empresaId],
                ['name' => $dia['name'], 'is_official' => true],
            );
            $creados += $festivo->wasRecentlyCreated ? 1 : 0;
        }

        return $creados;
    }

    private function mexico(int $anio): array
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

    private function estadosUnidos(int $anio): array
    {
        return [
            $this->observado(Carbon::create($anio, 1, 1), "New Year's Day"),
            ['date' => Carbon::create($anio, 5, 1)->lastOfMonth(Carbon::MONDAY)->format('Y-m-d'), 'name' => 'Memorial Day'],
            $this->observado(Carbon::create($anio, 7, 4), 'Independence Day'),
            ['date' => Carbon::create($anio, 9, 1)->nthOfMonth(1, Carbon::MONDAY)->format('Y-m-d'), 'name' => 'Labor Day'],
            ['date' => Carbon::create($anio, 11, 1)->nthOfMonth(4, Carbon::THURSDAY)->format('Y-m-d'), 'name' => 'Thanksgiving Day'],
            $this->observado(Carbon::create($anio, 12, 25), 'Christmas Day'),
        ];
    }

    /** Sábado → viernes anterior, domingo → lunes siguiente (Año Nuevo en sábado se observa el 31 de diciembre). */
    private function observado(Carbon $fecha, string $nombre): array
    {
        if ($fecha->isSaturday()) {
            return ['date' => $fecha->copy()->subDay()->format('Y-m-d'), 'name' => "{$nombre} (observado)"];
        }
        if ($fecha->isSunday()) {
            return ['date' => $fecha->copy()->addDay()->format('Y-m-d'), 'name' => "{$nombre} (observado)"];
        }

        return ['date' => $fecha->format('Y-m-d'), 'name' => $nombre];
    }
}
