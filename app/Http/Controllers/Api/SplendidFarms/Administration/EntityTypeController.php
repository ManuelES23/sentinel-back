<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Events\EntityTypeUpdated;
use App\Http\Controllers\Controller;
use App\Models\EntityType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EntityTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $types = EntityType::withCount('entities')
            ->ordered()
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $types
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:entity_types,code',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:entity_types,slug',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'is_active' => 'boolean',
            'order' => 'integer|min:0',
        ]);

        $type = EntityType::create($validated);
        $type->loadCount('entities');

        // Broadcast evento en tiempo real
        broadcast(new EntityTypeUpdated('created', $type->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Tipo de entidad creado exitosamente',
            'data' => $type
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(EntityType $entityType): JsonResponse
    {
        $entityType->load(['entities.branch', 'entities.areas']);
        $entityType->loadCount('entities');

        return response()->json([
            'success' => true,
            'data' => $entityType
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, EntityType $entityType): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'sometimes|string|max:50|unique:entity_types,code,' . $entityType->id,
            'name' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:entity_types,slug,' . $entityType->id,
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'is_active' => 'boolean',
            'order' => 'integer|min:0',
        ]);

        $entityType->update($validated);
        
        // Recargar el tipo de entidad
        $entityType = $entityType->fresh();
        $entityType->loadCount('entities');

        // Broadcast evento en tiempo real
        broadcast(new EntityTypeUpdated('updated', $entityType->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Tipo de entidad actualizado exitosamente',
            'data' => $entityType
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(EntityType $entityType): JsonResponse
    {
        // Verificar si tiene entidades asociadas
        if ($entityType->entities()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el tipo de entidad porque tiene entidades asociadas'
            ], 422);
        }

        $typeData = $entityType->toArray();
        $entityType->delete();

        // Broadcast evento en tiempo real
        broadcast(new EntityTypeUpdated('deleted', $typeData));

        return response()->json([
            'success' => true,
            'message' => 'Tipo de entidad eliminado exitosamente'
        ]);
    }
}
