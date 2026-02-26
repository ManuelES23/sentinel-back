<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Events\EntityUpdated;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EntityController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Entity::with(['branch', 'entityType', 'areas']);

        // Filtrar por sucursal si se proporciona
        if ($request->has('branch_id')) {
            $query->byBranch($request->branch_id);
        }

        // Filtrar por tipo si se proporciona
        if ($request->has('entity_type_id')) {
            $query->byType($request->entity_type_id);
        }

        // Filtrar solo activas si se solicita
        if ($request->boolean('active_only')) {
            $query->active();
        }

        $entities = $query->orderBy('name')->get();
        
        return response()->json([
            'success' => true,
            'data' => $entities
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'entity_type_id' => 'required|exists:entity_types,id',
            'code' => 'nullable|string|max:50|unique:entities,code',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:entities,slug',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'responsible' => 'nullable|string|max:255',
            'area_m2' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'is_external' => 'boolean',
            'owner_company' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'contact_email' => 'nullable|email|max:255',
            'contract_number' => 'nullable|string|max:255',
            'contract_start_date' => 'nullable|date',
            'contract_end_date' => 'nullable|date',
            'contract_notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        // Generar código automático si no se proporciona
        if (empty($validated['code'])) {
            // Obtener el tipo de entidad para usar su código
            $entityType = \App\Models\EntityType::find($validated['entity_type_id']);
            $prefix = $entityType->code;
            
            // Obtener el último código con este prefijo
            $lastEntity = Entity::where('code', 'like', $prefix . '-%')
                ->orderByRaw('CAST(SUBSTRING(code, ' . (strlen($prefix) + 2) . ') AS UNSIGNED) DESC')
                ->first();
            
            if ($lastEntity) {
                $lastNumber = (int) substr($lastEntity->code, strlen($prefix) + 1);
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }
            
            $validated['code'] = $prefix . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }

        $entity = Entity::create($validated);
        $entity->load(['branch', 'entityType', 'areas']);

        // Broadcast evento en tiempo real
        broadcast(new EntityUpdated('created', $entity->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Entidad creada exitosamente',
            'data' => $entity
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Entity $entity): JsonResponse
    {
        $entity->load(['branch', 'entityType', 'areas']);

        return response()->json([
            'success' => true,
            'data' => $entity
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Entity $entity): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'sometimes|exists:branches,id',
            'entity_type_id' => 'sometimes|exists:entity_types,id',
            'code' => 'sometimes|string|max:50|unique:entities,code,' . $entity->id,
            'name' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:entities,slug,' . $entity->id,
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'responsible' => 'nullable|string|max:255',
            'area_m2' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'is_external' => 'boolean',
            'owner_company' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'contact_email' => 'nullable|email|max:255',
            'contract_number' => 'nullable|string|max:255',
            'contract_start_date' => 'nullable|date',
            'contract_end_date' => 'nullable|date',
            'contract_notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $entity->update($validated);
        
        // Recargar la entidad con sus relaciones
        $entity = $entity->fresh(['branch', 'entityType', 'areas']);

        // Broadcast evento en tiempo real
        broadcast(new EntityUpdated('updated', $entity->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Entidad actualizada exitosamente',
            'data' => $entity
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Entity $entity): JsonResponse
    {
        // Verificar si tiene áreas asociadas
        if ($entity->areas()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar la entidad porque tiene áreas asociadas'
            ], 422);
        }

        $entityData = $entity->toArray();
        $entity->delete();

        // Broadcast evento en tiempo real
        broadcast(new EntityUpdated('deleted', $entityData));

        return response()->json([
            'success' => true,
            'message' => 'Entidad eliminada exitosamente'
        ]);
    }
}
