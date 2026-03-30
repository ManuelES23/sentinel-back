<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\Productor;
use App\Models\Temporada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller simplificado de Productores para Operación Agrícola.
 * Alta sencilla: solo nombre, apellido, tipo y teléfono.
 * Los datos completos se gestionan en Administración.
 */
class ProductorSimpleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Temporada obligatoria para context OA
        if (!$request->filled('temporada_id')) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $temporada = Temporada::findOrFail($request->temporada_id);
        $productorIds = $temporada->productoresActivos()->pluck('productores.id');
        $query = Productor::whereIn('id', $productorIds);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%");
            });
        }

        $productores = $query->orderBy('nombre')->get([
            'id', 'tipo', 'nombre', 'apellido', 'telefono', 'is_active', 'created_at',
        ]);

        return response()->json(['success' => true, 'data' => $productores]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo' => 'required|string|in:interno,externo',
            'nombre' => 'required|string|max:255',
            'apellido' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'temporada_id' => 'nullable|exists:temporadas,id',
        ]);

        $temporadaId = $validated['temporada_id'] ?? null;
        unset($validated['temporada_id']);

        $productor = Productor::create($validated);

        // Vincular a la temporada si se proporcionó
        if ($temporadaId) {
            $temporada = Temporada::findOrFail($temporadaId);
            $temporada->asignarProductor($productor->id);

            // Auto-asignar el cultivo de la temporada al productor
            if ($temporada->cultivo_id) {
                $productor->cultivos()->syncWithoutDetaching([
                    $temporada->cultivo_id => ['is_active' => true],
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Productor registrado.',
            'data' => $productor,
        ], 201);
    }

    public function show(Productor $productor): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $productor->only(['id', 'tipo', 'nombre', 'apellido', 'telefono', 'is_active', 'created_at']),
        ]);
    }

    public function update(Request $request, Productor $productor): JsonResponse
    {
        $validated = $request->validate([
            'tipo' => 'sometimes|required|string|in:interno,externo',
            'nombre' => 'sometimes|required|string|max:255',
            'apellido' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
        ]);

        $productor->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Productor actualizado',
            'data' => $productor->fresh(),
        ]);
    }

    public function destroy(Productor $productor): JsonResponse
    {
        $productor->delete();

        return response()->json([
            'success' => true,
            'message' => 'Productor eliminado',
        ]);
    }
}
