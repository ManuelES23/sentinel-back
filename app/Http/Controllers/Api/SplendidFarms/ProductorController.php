<?php

namespace App\Http\Controllers\Api\SplendidFarms;

use App\Events\ProductorUpdated;
use App\Http\Controllers\Controller;
use App\Models\Productor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProductorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $productores = Productor::with('cultivos')
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $productores
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo' => 'required|string|in:interno,externo',
            'nombre' => 'required|string|max:255',
            'apellido' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'direccion' => 'nullable|string|max:500',
            'rfc' => 'nullable|string|max:13',
            'notas' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $productor = Productor::create($validated);

        // Broadcast evento en tiempo real
        broadcast(new ProductorUpdated('created', $productor->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Productor creado exitosamente',
            'data' => $productor
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Productor $productor): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $productor->load('cultivos')
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Productor $productor): JsonResponse
    {
        $validated = $request->validate([
            'tipo' => 'sometimes|required|string|in:interno,externo',
            'nombre' => 'sometimes|required|string|max:255',
            'apellido' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'direccion' => 'nullable|string|max:500',
            'rfc' => 'nullable|string|max:13',
            'notas' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $productor->update($validated);

        // Broadcast evento en tiempo real
        broadcast(new ProductorUpdated('updated', $productor->fresh()->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Productor actualizado exitosamente',
            'data' => $productor->fresh()
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Productor $productor): JsonResponse
    {
        $productorData = $productor->toArray();
        /** @phpstan-ignore-next-line */
        $productor->delete();

        // Broadcast evento en tiempo real
        broadcast(new ProductorUpdated('deleted', $productorData));

        return response()->json([
            'success' => true,
            'message' => 'Productor eliminado exitosamente'
        ]);
    }

    /**
     * Get only active productores.
     */
    public function activos(): JsonResponse
    {
        $productores = Productor::with('cultivos')
            ->active()
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $productores
        ]);
    }

    /**
     * Sync cultivos for a productor.
     */
    public function syncCultivos(Request $request, Productor $productor): JsonResponse
    {
        $validated = $request->validate([
            'cultivo_ids' => 'required|array',
            'cultivo_ids.*' => 'integer|exists:cultivos,id',
        ]);

        // Sync cultivos - esto reemplaza todos los cultivos existentes
        $productor->cultivos()->sync($validated['cultivo_ids']);

        // Broadcast evento en tiempo real
        broadcast(new ProductorUpdated('updated', $productor->load('cultivos')->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Cultivos asignados exitosamente',
            'data' => $productor->load('cultivos')
        ]);
    }

    /**
     * Get cultivos for a specific productor.
     */
    public function getCultivos(Productor $productor): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $productor->cultivos
        ]);
    }
}
