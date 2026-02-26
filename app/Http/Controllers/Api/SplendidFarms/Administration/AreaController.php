<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Events\AreaUpdated;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Entity;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AreaController extends Controller
{
    /**
     * Display a listing of the resource (catálogo de áreas).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Area::with(['entities' => function ($q) {
            $q->select('entities.id', 'entities.name', 'entities.code', 'entities.branch_id')
              ->with('branch:id,name')
              ->withPivot(['location', 'area_m2', 'responsible', 'is_active', 'allows_inventory']);
        }])->withCount('entities');

        // Filtrar solo activas si se solicita
        if ($request->boolean('active_only')) {
            $query->active();
        }

        $areas = $query->orderBy('name')->get();
        
        return response()->json([
            'success' => true,
            'data' => $areas
        ]);
    }

    /**
     * Store a newly created resource in storage (catálogo).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:50|unique:areas,code',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:areas,slug',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        // Generar código automático si no se proporciona
        if (empty($validated['code'])) {
            $prefix = 'AREA';
            
            $lastArea = Area::where('code', 'like', $prefix . '-%')
                ->orderByRaw('CAST(SUBSTRING(code, ' . (strlen($prefix) + 2) . ') AS UNSIGNED) DESC')
                ->first();
            
            if ($lastArea) {
                $lastNumber = (int) substr($lastArea->code, strlen($prefix) + 1);
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }
            
            $validated['code'] = $prefix . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }

        $area = Area::create($validated);
        $area->loadCount('entities');

        // Broadcast evento en tiempo real
        broadcast(new AreaUpdated('created', $area->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Área creada exitosamente',
            'data' => $area
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Area $area): JsonResponse
    {
        $area->load('entities.branch', 'entities.entityType');
        $area->loadCount('entities');

        return response()->json([
            'success' => true,
            'data' => $area
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Area $area): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'sometimes|string|max:50|unique:areas,code,' . $area->id,
            'name' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:areas,slug,' . $area->id,
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        $area->update($validated);
        
        // Recargar el área
        $area = $area->fresh();
        $area->loadCount('entities');

        // Broadcast evento en tiempo real
        broadcast(new AreaUpdated('updated', $area->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Área actualizada exitosamente',
            'data' => $area
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Area $area): JsonResponse
    {
        // Verificar si tiene asignaciones a entidades
        if ($area->entities()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el área porque está asignada a una o más entidades'
            ], 422);
        }

        $areaData = $area->toArray();
        $area->delete();

        // Broadcast evento en tiempo real
        broadcast(new AreaUpdated('deleted', $areaData));

        return response()->json([
            'success' => true,
            'message' => 'Área eliminada exitosamente'
        ]);
    }

    /**
     * Asignar un área a una entidad.
     */
    public function assignToEntity(Request $request, Area $area): JsonResponse
    {
        $validated = $request->validate([
            'entity_id' => 'required|exists:entities,id',
            'location' => 'nullable|string|max:255',
            'area_m2' => 'nullable|numeric|min:0',
            'responsible' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'allows_inventory' => 'boolean',
        ]);

        $entityId = $validated['entity_id'];
        unset($validated['entity_id']);

        // Verificar si ya está asignada
        if ($area->entities()->where('entity_id', $entityId)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'El área ya está asignada a esta entidad'
            ], 422);
        }

        $area->entities()->attach($entityId, $validated);

        $area->load('entities.branch', 'entities.entityType');
        $area->loadCount('entities');

        broadcast(new AreaUpdated('assigned', $area->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Área asignada exitosamente',
            'data' => $area
        ]);
    }

    /**
     * Desasignar un área de una entidad.
     */
    public function unassignFromEntity(Area $area, Entity $entity): JsonResponse
    {
        if (!$area->entities()->where('entity_id', $entity->id)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'El área no está asignada a esta entidad'
            ], 422);
        }

        $area->entities()->detach($entity->id);

        $area->load('entities.branch', 'entities.entityType');
        $area->loadCount('entities');

        broadcast(new AreaUpdated('unassigned', $area->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Área desasignada exitosamente',
            'data' => $area
        ]);
    }

    /**
     * Actualizar datos de la asignación (tabla pivote).
     */
    public function updateAssignment(Request $request, Area $area, Entity $entity): JsonResponse
    {
        if (!$area->entities()->where('entity_id', $entity->id)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'El área no está asignada a esta entidad'
            ], 422);
        }

        $validated = $request->validate([
            'location' => 'nullable|string|max:255',
            'area_m2' => 'nullable|numeric|min:0',
            'responsible' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'allows_inventory' => 'boolean',
        ]);

        $area->entities()->updateExistingPivot($entity->id, $validated);

        $area->load('entities.branch', 'entities.entityType');
        $area->loadCount('entities');

        broadcast(new AreaUpdated('assignment_updated', $area->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Asignación actualizada exitosamente',
            'data' => $area
        ]);
    }

    /**
     * Obtener áreas asignadas a una entidad específica.
     */
    public function getByEntity(Entity $entity): JsonResponse
    {
        $areas = $entity->areas()
            ->withPivot(['location', 'area_m2', 'responsible', 'is_active', 'allows_inventory'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $areas
        ]);
    }
}
