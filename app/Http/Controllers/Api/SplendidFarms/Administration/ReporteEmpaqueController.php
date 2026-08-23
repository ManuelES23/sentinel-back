<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\RecepcionEmpaque;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReporteEmpaqueController extends Controller
{
    /**
     * Reporte de Recepción (pestaña 1/4 de Reportes > Empaque): 1 fila por
     * RecepcionEmpaque de la temporada filtrada, con las cajas y kg
     * realmente producidos a partir de cada folio (sigue la cadena
     * recepción → proceso → producción, con el mismo criterio de
     * atribución ya corregido en ReporteProductoresController).
     */
    public function recepcion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|integer|exists:temporadas,id',
            'entity_id' => 'nullable|integer|exists:entities,id',
            'productor_id' => 'nullable|integer|exists:productores,id',
            'variedad_id' => 'nullable|integer|exists:variedades,id',
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date|after_or_equal:fecha_desde',
            'search' => 'nullable|string|max:100',
        ]);

        $query = RecepcionEmpaque::query()
            ->with([
                'productor:id,nombre,apellido',
                'variedad:id,nombre',
                'lote:id,nombre,numero_lote',
                'entity:id,name,code',
            ])
            ->select('recepciones_empaque.*')
            ->selectSub(
                fn (QueryBuilder $q) => $q->fromSub($this->produccionPorRecepcion(), 'produccion_cajas')
                    ->selectRaw('COALESCE(SUM(produccion_cajas.cajas), 0)')
                    ->whereColumn('produccion_cajas.recepcion_id', 'recepciones_empaque.id'),
                'cajas_producidas',
            )
            ->selectSub(
                fn (QueryBuilder $q) => $q->fromSub($this->produccionPorRecepcion(), 'produccion_kg')
                    ->selectRaw('COALESCE(SUM(produccion_kg.kg), 0)')
                    ->whereColumn('produccion_kg.recepcion_id', 'recepciones_empaque.id'),
                'kg_producidos',
            )
            ->where('temporada_id', $validated['temporada_id']);

        if (!empty($validated['entity_id'])) {
            $query->where('entity_id', $validated['entity_id']);
        }

        if (!empty($validated['productor_id'])) {
            $query->where('productor_id', $validated['productor_id']);
        }

        if (!empty($validated['variedad_id'])) {
            $query->where('variedad_id', $validated['variedad_id']);
        }

        if (!empty($validated['fecha_desde'])) {
            $query->whereDate('fecha_recepcion', '>=', $validated['fecha_desde']);
        }

        if (!empty($validated['fecha_hasta'])) {
            $query->whereDate('fecha_recepcion', '<=', $validated['fecha_hasta']);
        }

        if (!empty($validated['search'])) {
            $query->where('folio_recepcion', 'like', '%'.$validated['search'].'%');
        }

        $recepciones = $query->orderByDesc('fecha_recepcion')->orderByDesc('id')->get();

        $data = $recepciones->map(function (RecepcionEmpaque $recepcion) {
            $kgRecibidos = (float) ($recepcion->peso_bascula ?? $recepcion->peso_recibido_kg ?? 0);

            return [
                'id' => $recepcion->id,
                'folio_recepcion' => $recepcion->folio_recepcion,
                'fecha_recepcion' => optional($recepcion->fecha_recepcion)->format('Y-m-d'),
                'productor' => $recepcion->productor ? [
                    'id' => $recepcion->productor->id,
                    'nombre_completo' => $recepcion->productor->nombre_completo,
                ] : null,
                'variedad' => $recepcion->variedad ? [
                    'id' => $recepcion->variedad->id,
                    'nombre' => $recepcion->variedad->nombre,
                ] : null,
                'lote' => $recepcion->lote ? [
                    'id' => $recepcion->lote->id,
                    'nombre' => $recepcion->lote->nombre,
                    'numero_lote' => $recepcion->lote->numero_lote,
                ] : null,
                'entity' => $recepcion->entity ? [
                    'id' => $recepcion->entity->id,
                    'name' => $recepcion->entity->name,
                    'code' => $recepcion->entity->code,
                ] : null,
                'cantidad_recibida' => (int) $recepcion->cantidad_recibida,
                'kg_recibidos' => round($kgRecibidos, 2),
                'cajas_producidas' => (int) $recepcion->cajas_producidas,
                'kg_producidos' => round((float) $recepcion->kg_producidos, 2),
                'status' => $recepcion->status,
            ];
        });

        $totalKgRecibidos = round((float) $data->sum('kg_recibidos'), 2);
        $totalKgProducidos = round((float) $data->sum('kg_producidos'), 2);

        $resumen = [
            'total_recepciones' => $data->count(),
            'total_kg_recibidos' => $totalKgRecibidos,
            'total_cajas_producidas' => (int) $data->sum('cajas_producidas'),
            'rendimiento_pct' => $totalKgRecibidos > 0
                ? round($totalKgProducidos / $totalKgRecibidos * 100, 2)
                : null,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'resumen' => $resumen,
                'recepciones' => $data,
            ],
        ]);
    }

    /**
     * Resuelve (recepcion_id, cajas, kg) por cada fila de producción ligada
     * a un proceso, cubriendo pallets simples (sin filas en
     * produccion_empaque_detalles → cajas/kg del pallet vía su proceso_id
     * directo) y pallets con desglose por detalle (con filas → SUM de esas
     * filas, vía el proceso_id de cada una). Mismo criterio que
     * ReporteProductoresController::produccionCajasPorProductor(), aquí
     * agrupado por recepcion_id (vía proceso_empaque.recepcion_id) en vez
     * de productor_id.
     */
    private function produccionPorRecepcion(): QueryBuilder
    {
        $simple = DB::table('produccion_empaque')
            ->join('proceso_empaque', 'proceso_empaque.id', '=', 'produccion_empaque.proceso_id')
            ->whereNull('produccion_empaque.deleted_at')
            ->whereNull('proceso_empaque.deleted_at')
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('produccion_empaque_detalles')
                    ->whereColumn('produccion_empaque_detalles.produccion_id', 'produccion_empaque.id');
            })
            ->select([
                'proceso_empaque.recepcion_id',
                'produccion_empaque.total_cajas as cajas',
                DB::raw('COALESCE(produccion_empaque.peso_bascula_kg, produccion_empaque.peso_neto_kg, 0) as kg'),
            ]);

        $conDetalle = DB::table('produccion_empaque')
            ->join('produccion_empaque_detalles', 'produccion_empaque_detalles.produccion_id', '=', 'produccion_empaque.id')
            ->join('proceso_empaque', 'proceso_empaque.id', '=', 'produccion_empaque_detalles.proceso_id')
            ->whereNull('produccion_empaque.deleted_at')
            ->whereNull('proceso_empaque.deleted_at')
            ->select([
                'proceso_empaque.recepcion_id',
                'produccion_empaque_detalles.total_cajas as cajas',
                DB::raw('COALESCE(produccion_empaque_detalles.peso_neto_kg, 0) as kg'),
            ]);

        return $simple->unionAll($conDetalle);
    }
}
