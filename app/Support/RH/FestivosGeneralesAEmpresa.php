<?php

namespace App\Support\RH;

use App\Models\Enterprise;
use App\Models\Holiday;
use Illuminate\Support\Facades\DB;

/**
 * Paso de la migración a festivos por empresa: los festivos sin empresa («todas») eran los
 * oficiales de México, así que pasan a Splendid Farms (si ya tenía uno en esa fecha, se queda
 * el suyo) y las demás empresas, menos Splendid by Porvenir (festivos de Estados Unidos), quedan
 * siguiéndola. Sin Splendid Farms no hace nada: los festivos sin empresa siguen valiendo para todas.
 * Idempotente.
 */
final class FestivosGeneralesAEmpresa
{
    public const MEXICO = 'splendidfarms';

    public const ESTADOS_UNIDOS = 'splendidbyporvenir';

    public static function aplicar(): void
    {
        $mexico = Enterprise::where('slug', self::MEXICO)->value('id');
        if (! $mexico) {
            return;
        }

        DB::transaction(function () use ($mexico) {
            $ocupadas = Holiday::where('enterprise_id', $mexico)->pluck('date')->all();
            Holiday::whereNull('enterprise_id')->whereIn('date', $ocupadas)->delete();
            Holiday::whereNull('enterprise_id')->update(['enterprise_id' => $mexico]);

            Enterprise::whereNotIn('slug', [self::MEXICO, self::ESTADOS_UNIDOS])
                ->whereNull('holiday_source_enterprise_id')
                ->update(['holiday_source_enterprise_id' => $mexico]);
        });
    }
}
