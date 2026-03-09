<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\Temporada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller para gestión de temporadas en Operación Agrícola.
 * 
 * Reutiliza el modelo Temporada existente pero expone endpoints
 * simplificados para el flujo de selección de temporada.
 */
class TemporadaOAController extends Controller
{
    /**
     * Listar todas las temporadas disponibles.
     * Devuelve información resumida para el selector.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Temporada::with(['cultivo:id,nombre,imagen']);

        // Filtro por estado
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        // Búsqueda
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('folio_temporada', 'like', "%{$search}%")
                    ->orWhere('locacion', 'like', "%{$search}%");
            });
        }

        $temporadas = $query->orderByDesc('created_at')->get()->map(function ($temporada) {
            return [
                'id' => $temporada->id,
                'nombre' => $temporada->nombre,
                'folio_temporada' => $temporada->folio_temporada,
                'locacion' => $temporada->locacion,
                'cultivo' => $temporada->cultivo ? [
                    'id' => $temporada->cultivo->id,
                    'nombre' => $temporada->cultivo->nombre,
                ] : null,
                'año_inicio' => $temporada->año_inicio,
                'año_fin' => $temporada->año_fin,
                'fecha_inicio' => $temporada->fecha_inicio?->format('Y-m-d'),
                'fecha_fin' => $temporada->fecha_fin?->format('Y-m-d'),
                'estado' => $temporada->estado,
                'created_at' => $temporada->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $temporadas,
        ]);
    }

    /**
     * Obtener detalle de una temporada con estadísticas.
     */
    public function show(Temporada $temporada): JsonResponse
    {
        $temporada->load(['cultivo:id,nombre', 'usuario:id,name']);

        // Contar elementos asignados
        $stats = [
            'productores_count' => $temporada->productores()->count(),
            'zonas_cultivo_count' => $temporada->zonasCultivo()->count(),
            'lotes_count' => $temporada->lotes()->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $temporada->id,
                'nombre' => $temporada->nombre,
                'folio_temporada' => $temporada->folio_temporada,
                'locacion' => $temporada->locacion,
                'cultivo' => $temporada->cultivo ? [
                    'id' => $temporada->cultivo->id,
                    'nombre' => $temporada->cultivo->nombre,
                ] : null,
                'año_inicio' => $temporada->año_inicio,
                'año_fin' => $temporada->año_fin,
                'fecha_inicio' => $temporada->fecha_inicio?->format('Y-m-d'),
                'fecha_fin' => $temporada->fecha_fin?->format('Y-m-d'),
                'estado' => $temporada->estado,
                'created_at' => $temporada->created_at,
                'stats' => $stats,
            ],
        ]);
    }
}
