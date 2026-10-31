<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Support\CRM\AlcanceOportunidades;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Actividades que muestra la ficha de un cliente o de un prospecto: las suyas y
 * las de sus oportunidades (spec 2026-10-30 §5.4). Desde que la actividad nueva
 * se captura en la oportunidad, sin esto el historial de la ficha se vaciaría.
 *
 * De las oportunidades salen solo las que el usuario ve (AlcanceOportunidades):
 * sin oportunidades.equipo, las de otros vendedores y las eliminadas no entran.
 * Es un solo `where (... or ...)` en SQL, así que el conteo, el orden y la
 * paginación salen de la misma consulta.
 */
class ActividadesDeFicha
{
    /**
     * Limita la consulta de crm_actividades a la ficha de $duenoType
     * (CrmCliente|CrmProspecto) con id $duenoId.
     *
     * @param  class-string  $duenoType
     */
    public function limitar(Builder $query, string $duenoType, int $duenoId, AlcanceOportunidades $alcance): Builder
    {
        $columna = $duenoType === CrmProspecto::class ? 'prospecto_id' : 'cliente_id';

        $oportunidades = $alcance->aplicar(CrmOportunidad::query()->select('crm_oportunidades.id'))
            ->where("crm_oportunidades.{$columna}", $duenoId);

        return $query->where(fn ($w) => $w
            ->where(fn ($propias) => $propias
                ->where('crm_actividades.entidad_type', $duenoType)
                ->where('crm_actividades.entidad_id', $duenoId))
            ->orWhere(fn ($deOportunidades) => $deOportunidades
                ->where('crm_actividades.entidad_type', CrmOportunidad::class)
                ->whereIn('crm_actividades.entidad_id', $oportunidades)));
    }

    /**
     * Pone `oportunidad` {id, nombre} (o null) en cada actividad con UNA sola
     * consulta, sea cual sea el número de filas. Incluye oportunidades
     * eliminadas: el nombre de una actividad ya visible no debe perderse.
     *
     * @param  iterable<CrmActividad>  $actividades
     */
    public function adjuntarOportunidad(iterable $actividades): void
    {
        $actividades = Collection::make($actividades);

        $ids = $actividades
            ->filter(fn ($a) => $a->entidad_type === CrmOportunidad::class)
            ->pluck('entidad_id')
            ->unique()
            ->values();

        $nombres = $ids->isEmpty()
            ? collect()
            : CrmOportunidad::withTrashed()->whereIn('id', $ids)->pluck('nombre', 'id');

        foreach ($actividades as $actividad) {
            $esDeOportunidad = $actividad->entidad_type === CrmOportunidad::class && $nombres->has($actividad->entidad_id);

            $actividad->oportunidad = $esDeOportunidad
                ? ['id' => (int) $actividad->entidad_id, 'nombre' => $nombres[$actividad->entidad_id]]
                : null;
        }
    }
}
