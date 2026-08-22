<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\Productor;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReporteProductoresController extends Controller
{
    /**
     * Lista maestra de productores (todo el catálogo) con métricas resumen
     * calculadas en BD vía subqueries agregadas — nunca se traen datasets
     * completos a PHP. Filtrable por temporada, cultivo, tipo y búsqueda.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'nullable|exists:temporadas,id',
            'cultivo_id' => 'nullable|exists:cultivos,id',
            'tipo' => 'nullable|in:interno,externo',
            'search' => 'nullable|string|max:100',
        ]);

        $temporadaId = $request->integer('temporada_id') ?: null;
        $cultivoId = $request->integer('cultivo_id') ?: null;

        $query = Productor::query()
            ->with(['temporadas:id,nombre', 'cultivos:id,nombre'])
            ->select('productores.*')
            ->selectSub(
                fn (QueryBuilder $q) => $this->scopeAggregateByPeriod(
                    $q->from('salidas_campo_cosecha')->selectRaw('COUNT(*)')
                        ->whereColumn('salidas_campo_cosecha.productor_id', 'productores.id')
                        // salidas_campo_cosecha usa la columna `eliminado` (no SoftDeletes) — mismo
                        // filtro que SalidaCampoCosecha::scopeActivos() y que ya usa
                        // TableroProductoresController::index() para este mismo propósito.
                        ->where('salidas_campo_cosecha.eliminado', false),
                    'salidas_campo_cosecha',
                    $temporadaId,
                    $cultivoId,
                ),
                'total_salidas_campo',
            )
            ->selectSub(
                fn (QueryBuilder $q) => $this->scopeAggregateByPeriod(
                    $q->from('recepciones_empaque')
                        ->selectRaw('COALESCE(SUM(COALESCE(peso_bascula, peso_recibido_kg, 0)), 0)')
                        ->whereColumn('recepciones_empaque.productor_id', 'productores.id')
                        ->whereNull('recepciones_empaque.deleted_at'),
                    'recepciones_empaque',
                    $temporadaId,
                    $cultivoId,
                ),
                'total_kilos_recibidos',
            )
            ->selectSub(
                fn (QueryBuilder $q) => $this->scopeAggregateByPeriod(
                    $q->fromSub($this->produccionCajasPorProductor(), 'produccion_cajas')
                        ->selectRaw('COALESCE(SUM(produccion_cajas.cajas), 0)')
                        ->whereColumn('produccion_cajas.productor_id', 'productores.id'),
                    'produccion_cajas',
                    $temporadaId,
                    $cultivoId,
                ),
                'total_cajas_producidas',
            )
            ->selectSub(
                fn (QueryBuilder $q) => $this->scopeAggregateByPeriod(
                    $q->fromSub($this->embarqueCajasPorProductor(), 'embarque_cajas')
                        ->selectRaw('COALESCE(SUM(embarque_cajas.cajas), 0)')
                        ->whereColumn('embarque_cajas.productor_id', 'productores.id'),
                    'embarque_cajas',
                    $temporadaId,
                    $cultivoId,
                ),
                'total_cajas_embarcadas',
            )
            ->selectSub(
                fn (QueryBuilder $q) => $this->scopeAggregateByPeriod(
                    $q->from('rezaga_empaque')
                        ->selectRaw('COALESCE(SUM(rezaga_empaque.cantidad_kg), 0)')
                        ->join('proceso_empaque', 'proceso_empaque.id', '=', 'rezaga_empaque.proceso_id')
                        ->whereColumn('proceso_empaque.productor_id', 'productores.id')
                        ->whereNull('rezaga_empaque.deleted_at')
                        ->whereNull('proceso_empaque.deleted_at'),
                    'rezaga_empaque',
                    $temporadaId,
                    $cultivoId,
                ),
                'total_rezaga_kg',
            );

        if ($temporadaId) {
            $query->whereHas('temporadas', fn ($q) => $q->where('temporadas.id', $temporadaId));
        }

        if ($cultivoId) {
            $query->whereHas('cultivos', fn ($q) => $q->where('cultivos.id', $cultivoId));
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->input('tipo'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('apellido', 'like', "%{$search}%")
                    ->orWhere('rfc', 'like', "%{$search}%");
            });
        }

        $productores = $query->orderBy('nombre')->orderBy('apellido')->get();

        $data = $productores->map(function (Productor $productor) {
            $kilos = (float) $productor->total_kilos_recibidos;
            $rezagaKg = (float) $productor->total_rezaga_kg;

            return [
                'productor' => [
                    'id' => $productor->id,
                    'nombre' => $productor->nombre,
                    'apellido' => $productor->apellido,
                    'nombre_completo' => $productor->nombre_completo,
                    'tipo' => $productor->tipo,
                    'tipo_label' => $productor->tipo_label,
                    'telefono' => $productor->telefono,
                    'email' => $productor->email,
                    'is_active' => $productor->is_active,
                ],
                'temporadas' => $productor->temporadas->map(fn ($t) => ['id' => $t->id, 'nombre' => $t->nombre])->values(),
                'cultivos' => $productor->cultivos->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre])->values(),
                'metricas' => [
                    'total_salidas_campo' => (int) $productor->total_salidas_campo,
                    'total_kilos_recibidos' => round($kilos, 2),
                    'total_cajas_producidas' => (int) $productor->total_cajas_producidas,
                    'total_cajas_embarcadas' => (int) $productor->total_cajas_embarcadas,
                    'porcentaje_rezaga' => $kilos > 0 ? round(($rezagaKg / $kilos) * 100, 2) : 0,
                ],
            ];
        });

        $resumen = [
            'total_productores' => $data->count(),
            'total_kilos_recibidos' => round((float) $data->sum(fn ($p) => $p['metricas']['total_kilos_recibidos']), 2),
            'total_cajas_embarcadas' => (int) $data->sum(fn ($p) => $p['metricas']['total_cajas_embarcadas']),
            'total_salidas_campo' => (int) $data->sum(fn ($p) => $p['metricas']['total_salidas_campo']),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'resumen' => $resumen,
                'productores' => $data,
            ],
        ]);
    }

    /**
     * Acota una subquery agregada por temporada_id (columna directa de $table)
     * y, si se pide cultivo_id, hace join a temporadas para filtrar por su cultivo_id.
     */
    private function scopeAggregateByPeriod(QueryBuilder $subQuery, string $table, ?int $temporadaId, ?int $cultivoId): QueryBuilder
    {
        if ($cultivoId) {
            $subQuery->join('temporadas', 'temporadas.id', '=', "{$table}.temporada_id")
                ->where('temporadas.cultivo_id', $cultivoId);
        }

        if ($temporadaId) {
            $subQuery->where("{$table}.temporada_id", $temporadaId);
        }

        return $subQuery;
    }

    /**
     * Resuelve (productor_id, temporada_id, cajas) por cada fila de
     * producción, cubriendo pallets normales (proceso_id directo) y
     * pallets mixtos (ProduccionEmpaqueController::mixtear() deja
     * proceso_id NULL en el pallet y coloca el productor real de cada
     * pieza en produccion_empaque_detalles) — sin esta unión, las cajas
     * de pallets mixtos desaparecen de las métricas agregadas.
     */
    private function produccionCajasPorProductor(): QueryBuilder
    {
        $normal = DB::table('produccion_empaque')
            ->join('proceso_empaque', 'proceso_empaque.id', '=', 'produccion_empaque.proceso_id')
            ->whereNull('produccion_empaque.deleted_at')
            ->whereNull('proceso_empaque.deleted_at')
            ->select([
                'produccion_empaque.id as produccion_id',
                'produccion_empaque.temporada_id',
                'proceso_empaque.productor_id',
                'produccion_empaque.total_cajas as cajas',
            ]);

        $mixto = DB::table('produccion_empaque')
            ->join('produccion_empaque_detalles', 'produccion_empaque_detalles.produccion_id', '=', 'produccion_empaque.id')
            ->join('proceso_empaque', 'proceso_empaque.id', '=', 'produccion_empaque_detalles.proceso_id')
            ->whereNull('produccion_empaque.proceso_id')
            ->whereNull('produccion_empaque.deleted_at')
            ->whereNull('proceso_empaque.deleted_at')
            ->select([
                'produccion_empaque.id as produccion_id',
                'produccion_empaque.temporada_id',
                'proceso_empaque.productor_id',
                'produccion_empaque_detalles.total_cajas as cajas',
            ]);

        return $normal->unionAll($mixto);
    }

    /**
     * Igual que produccionCajasPorProductor() pero para lo efectivamente
     * embarcado: en pallets mixtos, cada pieza (produccion_empaque_detalles)
     * aporta sus propias cajas al total embarcado — mismo criterio que ya
     * usa el frontend (rowBuilders.js::buildEmbarqueRows) para el detalle,
     * así la métrica de la lista y las filas del detalle quedan consistentes.
     */
    private function embarqueCajasPorProductor(): QueryBuilder
    {
        $normal = DB::table('embarque_empaque_detalles')
            ->join('produccion_empaque', 'produccion_empaque.id', '=', 'embarque_empaque_detalles.produccion_id')
            ->join('proceso_empaque', 'proceso_empaque.id', '=', 'produccion_empaque.proceso_id')
            ->join('embarques_empaque', 'embarques_empaque.id', '=', 'embarque_empaque_detalles.embarque_id')
            ->whereNull('produccion_empaque.deleted_at')
            ->whereNull('proceso_empaque.deleted_at')
            ->whereNull('embarques_empaque.deleted_at')
            ->select([
                'embarques_empaque.temporada_id',
                'proceso_empaque.productor_id',
                'embarque_empaque_detalles.cajas',
            ]);

        $mixto = DB::table('embarque_empaque_detalles')
            ->join('produccion_empaque', 'produccion_empaque.id', '=', 'embarque_empaque_detalles.produccion_id')
            ->join('produccion_empaque_detalles', 'produccion_empaque_detalles.produccion_id', '=', 'produccion_empaque.id')
            ->join('proceso_empaque', 'proceso_empaque.id', '=', 'produccion_empaque_detalles.proceso_id')
            ->join('embarques_empaque', 'embarques_empaque.id', '=', 'embarque_empaque_detalles.embarque_id')
            ->whereNull('produccion_empaque.proceso_id')
            ->whereNull('produccion_empaque.deleted_at')
            ->whereNull('proceso_empaque.deleted_at')
            ->whereNull('embarques_empaque.deleted_at')
            ->select([
                'embarques_empaque.temporada_id',
                'proceso_empaque.productor_id',
                'produccion_empaque_detalles.total_cajas as cajas',
            ]);

        return $normal->unionAll($mixto);
    }
}
