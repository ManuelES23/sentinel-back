<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\CosteoAgricola;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\Temporada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CosteoAgricolaController extends Controller
{
    /**
     * Dashboard de costeo: resumen por temporada.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
        ]);

        $temporadaId = $request->temporada_id;

        // Total general
        $totalGeneral = CosteoAgricola::byTemporada($temporadaId)->sum('costo_total');

        // Por categoría
        $porCategoria = CosteoAgricola::byTemporada($temporadaId)
            ->select('categoria', DB::raw('SUM(costo_total) as total'), DB::raw('COUNT(*) as registros'))
            ->groupBy('categoria')
            ->orderByDesc('total')
            ->get();

        // Por lote
        $porLote = CosteoAgricola::byTemporada($temporadaId)
            ->whereNotNull('lote_id')
            ->join('lotes', 'costeos_agricolas.lote_id', '=', 'lotes.id')
            ->select(
                'lotes.id as lote_id',
                'lotes.nombre as lote_nombre',
                DB::raw('SUM(costeos_agricolas.costo_total) as total'),
                DB::raw('COUNT(*) as registros')
            )
            ->groupBy('lotes.id', 'lotes.nombre')
            ->orderByDesc('total')
            ->get();

        // Por tipo de fuente
        $porFuente = CosteoAgricola::byTemporada($temporadaId)
            ->select('tipo_fuente', DB::raw('SUM(costo_total) as total'), DB::raw('COUNT(*) as registros'))
            ->groupBy('tipo_fuente')
            ->get();

        // Superficie total de la temporada para calcular costo/ha
        $temporada = Temporada::findOrFail($temporadaId);
        $loteIds = $temporada->lotesActivos()->pluck('lotes.id');
        $superficieTotal = $loteIds->isNotEmpty()
            ? Etapa::whereIn('lote_id', $loteIds)->sum('superficie')
            : 0;

        // Últimos 10 registros
        $ultimosRegistros = CosteoAgricola::byTemporada($temporadaId)
            ->with([
                'lote:id,nombre',
                'etapa:id,nombre,codigo,superficie',
                'product:id,name,code',
                'user:id,name',
            ])
            ->orderByDesc('fecha')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_general' => (float) $totalGeneral,
                'superficie_total' => (float) $superficieTotal,
                'costo_por_hectarea' => $superficieTotal > 0 ? round($totalGeneral / $superficieTotal, 2) : 0,
                'por_categoria' => $porCategoria,
                'por_lote' => $porLote,
                'por_fuente' => $porFuente,
                'ultimos_registros' => $ultimosRegistros,
            ],
        ]);
    }

    /**
     * Detalle de costeo por lote.
     */
    public function porLote(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'lote_id' => 'required|exists:lotes,id',
        ]);

        $costeos = CosteoAgricola::byTemporada($request->temporada_id)
            ->byLote($request->lote_id)
            ->with([
                'etapa:id,nombre,codigo,superficie',
                'product:id,name,code',
                'unit:id,abbreviation',
                'user:id,name',
            ])
            ->orderBy('fecha')
            ->get();

        $lote = Lote::with('etapas:id,lote_id,nombre,codigo,superficie')
            ->findOrFail($request->lote_id);

        $totalLote = $costeos->sum('costo_total');
        $superficieLote = $lote->superficie ?? $lote->etapas->sum('superficie');

        // Agrupar por etapa
        $porEtapa = $costeos->groupBy('etapa_id')->map(function ($items, $etapaId) {
            $etapa = $items->first()->etapa;
            return [
                'etapa_id' => $etapaId,
                'etapa_nombre' => $etapa?->nombre ?? 'Sin asignar',
                'etapa_superficie' => $etapa?->superficie ?? 0,
                'total' => $items->sum('costo_total'),
                'registros' => $items->count(),
                'costo_por_ha' => $etapa?->superficie > 0
                    ? round($items->sum('costo_total') / $etapa->superficie, 2)
                    : 0,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'lote' => $lote,
                'total_lote' => (float) $totalLote,
                'superficie_lote' => (float) $superficieLote,
                'costo_por_hectarea' => $superficieLote > 0 ? round($totalLote / $superficieLote, 2) : 0,
                'por_etapa' => $porEtapa,
                'detalle' => $costeos,
            ],
        ]);
    }

    /**
     * Listado completo de registros de costeo (con filtros).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
        ]);

        $query = CosteoAgricola::byTemporada($request->temporada_id)
            ->with([
                'lote:id,nombre',
                'etapa:id,nombre,codigo,superficie,lote_id',
                'product:id,name,code',
                'unit:id,abbreviation',
                'user:id,name',
            ]);

        if ($request->has('lote_id')) {
            $query->byLote($request->lote_id);
        }

        if ($request->has('etapa_id')) {
            $query->byEtapa($request->etapa_id);
        }

        if ($request->has('categoria')) {
            $query->byCategoria($request->categoria);
        }

        if ($request->has('tipo_fuente')) {
            $query->where('tipo_fuente', $request->tipo_fuente);
        }

        if ($request->has('fecha_desde') && $request->has('fecha_hasta')) {
            $query->entreFechas($request->fecha_desde, $request->fecha_hasta);
        }

        $costeos = $query->orderByDesc('fecha')->get();

        $total = $costeos->sum('costo_total');

        return response()->json([
            'success' => true,
            'data' => $costeos,
            'meta' => [
                'total' => (float) $total,
                'registros' => $costeos->count(),
            ],
        ]);
    }

    /**
     * Crear registro manual de costeo.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'lote_id' => 'nullable|exists:lotes,id',
            'etapa_id' => 'nullable|exists:etapas,id',
            'product_id' => 'nullable|exists:products,id',
            'descripcion' => 'required|string|max:300',
            'categoria' => 'required|string|max:100',
            'cantidad' => 'nullable|numeric|min:0',
            'unit_id' => 'nullable|exists:units_of_measure,id',
            'costo_unitario' => 'nullable|numeric|min:0',
            'costo_total' => 'required|numeric|min:0.01',
            'fecha' => 'required|date',
            'notas' => 'nullable|string',
        ]);

        $validated['tipo_fuente'] = CosteoAgricola::TIPO_FUENTE_MANUAL;
        $validated['user_id'] = Auth::id();

        $costeo = CosteoAgricola::create($validated);
        $costeo->load(['lote:id,nombre', 'etapa:id,nombre,codigo', 'product:id,name', 'user:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Registro de costeo creado',
            'data' => $costeo,
        ], 201);
    }

    /**
     * Actualizar registro manual.
     */
    public function update(Request $request, CosteoAgricola $costeo): JsonResponse
    {
        if ($costeo->tipo_fuente !== CosteoAgricola::TIPO_FUENTE_MANUAL) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden editar registros manuales',
            ], 422);
        }

        $validated = $request->validate([
            'lote_id' => 'nullable|exists:lotes,id',
            'etapa_id' => 'nullable|exists:etapas,id',
            'product_id' => 'nullable|exists:products,id',
            'descripcion' => 'sometimes|required|string|max:300',
            'categoria' => 'sometimes|required|string|max:100',
            'cantidad' => 'nullable|numeric|min:0',
            'unit_id' => 'nullable|exists:units_of_measure,id',
            'costo_unitario' => 'nullable|numeric|min:0',
            'costo_total' => 'sometimes|required|numeric|min:0.01',
            'fecha' => 'sometimes|required|date',
            'notas' => 'nullable|string',
        ]);

        $costeo->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Registro actualizado',
            'data' => $costeo->fresh(['lote:id,nombre', 'etapa:id,nombre,codigo', 'product:id,name', 'user:id,name']),
        ]);
    }

    /**
     * Eliminar registro (solo manual).
     */
    public function destroy(CosteoAgricola $costeo): JsonResponse
    {
        if ($costeo->tipo_fuente !== CosteoAgricola::TIPO_FUENTE_MANUAL) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden eliminar registros manuales',
            ], 422);
        }

        $costeo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Registro eliminado',
        ]);
    }

    /**
     * Obtener categorías disponibles.
     */
    public function categorias(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => CosteoAgricola::CATEGORIAS,
        ]);
    }
}
