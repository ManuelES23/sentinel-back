<?php

namespace App\Services\Cosecha;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lecturas de cosecha y empaque por etapa que comparten el Dashboard de cosecha
 * y el rendimiento real de Planeación: una sola definición de qué cierre es de
 * venta nacional y de a qué etapa pertenece cada caja empacada.
 */
class ConsultasCosecha
{
    /** Una venta cancelada no cuenta como venta nacional. */
    public const VENTA_VIVA = "ventas_cosecha.deleted_at IS NULL AND ventas_cosecha.status <> 'cancelada'";

    /**
     * Hectáreas de los cierres por temporada, etapa y día, separando los que
     * tienen alguna venta no cancelada.
     *
     * La bandera se calcula en una subconsulta (cada cierre una sola vez, aunque tenga
     * varias ventas) y se agrupa por columnas simples: válido con only_full_group_by.
     *
     * @param  int[]  $temporadaIds
     * @return Builder columnas `temporada_id`, `etapa_id`, `fecha_inicio`, `con_venta`, `ha`.
     */
    public function cierresPorDia(array $temporadaIds): Builder
    {
        $cierresConVenta = DB::table('ventas_cosecha')
            ->whereRaw(self::VENTA_VIVA)
            ->select('cierre_cosecha_id')
            ->distinct();
        $cierresMarcados = DB::table('cierres_cosecha')
            ->leftJoinSub($cierresConVenta, 'cv', 'cv.cierre_cosecha_id', '=', 'cierres_cosecha.id')
            ->whereIn('cierres_cosecha.temporada_id', $temporadaIds)
            ->whereNull('cierres_cosecha.deleted_at')
            ->whereNotNull('cierres_cosecha.etapa_id')
            ->selectRaw('cierres_cosecha.temporada_id AS temporada_id, cierres_cosecha.etapa_id AS etapa_id, '
                .'cierres_cosecha.fecha_inicio AS fecha_inicio, cierres_cosecha.superficie_cosechada AS ha, '
                .'CASE WHEN cv.cierre_cosecha_id IS NULL THEN 0 ELSE 1 END AS con_venta');

        return DB::query()
            ->fromSub($cierresMarcados, 'c')
            ->selectRaw('c.temporada_id, c.etapa_id, c.fecha_inicio, c.con_venta, COALESCE(SUM(c.ha), 0) AS ha')
            ->groupBy('c.temporada_id', 'c.etapa_id', 'c.fecha_inicio', 'c.con_venta');
    }

    /**
     * Una fila por pallet sin desglose y una por renglón de desglose de un pallet
     * mixto, con la etapa de su proceso (`null` si no hay proceso o no trae etapa).
     * Los procesos borrados se excluyen.
     *
     * @param  int[]  $temporadaIds
     * @return Builder columnas `temporada_id`, `produccion_id`, `etapa_id`, `cajas`, `kg`, `total_pallet`.
     */
    public function partesDeProduccion(array $temporadaIds): Builder
    {
        $simples = DB::table('produccion_empaque as p')
            ->leftJoin('proceso_empaque as pr', 'pr.id', '=', 'p.proceso_id')
            ->whereIn('p.temporada_id', $temporadaIds)
            ->whereNull('p.deleted_at')
            ->whereNull('pr.deleted_at')
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('produccion_empaque_detalles as dd')
                    ->whereColumn('dd.produccion_id', 'p.id');
            })
            ->select([
                'p.temporada_id', 'p.id as produccion_id', 'pr.etapa_id', 'p.total_cajas as cajas',
                'p.peso_neto_kg as kg', 'p.total_cajas as total_pallet',
            ]);

        $mixtos = DB::table('produccion_empaque as p')
            ->join('produccion_empaque_detalles as d', 'd.produccion_id', '=', 'p.id')
            ->leftJoin('proceso_empaque as pr', 'pr.id', '=', 'd.proceso_id')
            ->whereIn('p.temporada_id', $temporadaIds)
            ->whereNull('p.deleted_at')
            ->whereNull('pr.deleted_at')
            ->select([
                'p.temporada_id', 'p.id as produccion_id', 'pr.etapa_id', 'd.total_cajas as cajas',
                'd.peso_neto_kg as kg', 'p.total_cajas as total_pallet',
            ]);

        return $simples->unionAll($mixtos);
    }
}
