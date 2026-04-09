<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Models\Entity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntityAccessController extends Controller
{
    /**
     * Listar entidades accesibles por una empresa.
     */
    public function index(Request $request, Enterprise $enterprise): JsonResponse
    {
        $entities = $enterprise->accessibleEntities()
            ->with(['branch:id,name,enterprise_id', 'branch.enterprise:id,name,slug', 'entityType:id,name,icon,color'])
            ->get()
            ->map(function ($entity) use ($enterprise) {
                $isOwn = $entity->branch && $entity->branch->enterprise_id === $enterprise->id;
                return [
                    'id' => $entity->id,
                    'code' => $entity->code,
                    'name' => $entity->name,
                    'branch' => $entity->branch?->name,
                    'owner_enterprise' => $entity->branch?->enterprise?->name,
                    'owner_enterprise_slug' => $entity->branch?->enterprise?->slug,
                    'entity_type' => $entity->entityType?->name,
                    'entity_type_icon' => $entity->entityType?->icon,
                    'entity_type_color' => $entity->entityType?->color,
                    'is_own' => $isOwn,
                    'is_external' => $entity->is_external,
                    'access_level' => $entity->pivot->access_level,
                    'is_active' => $entity->is_active,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $entities,
        ]);
    }

    /**
     * Entidades disponibles para compartir (que no están asignadas a esta empresa).
     */
    public function available(Request $request, Enterprise $enterprise): JsonResponse
    {
        $assignedIds = $enterprise->accessibleEntities()->pluck('entities.id');

        $entities = Entity::with(['branch:id,name,enterprise_id', 'branch.enterprise:id,name,slug', 'entityType:id,name,icon,color'])
            ->whereNotIn('id', $assignedIds)
            ->active()
            ->get()
            ->map(function ($entity) {
                return [
                    'id' => $entity->id,
                    'code' => $entity->code,
                    'name' => $entity->name,
                    'branch' => $entity->branch?->name,
                    'owner_enterprise' => $entity->branch?->enterprise?->name,
                    'owner_enterprise_slug' => $entity->branch?->enterprise?->slug,
                    'entity_type' => $entity->entityType?->name,
                    'entity_type_icon' => $entity->entityType?->icon,
                    'entity_type_color' => $entity->entityType?->color,
                    'is_external' => $entity->is_external,
                    'is_active' => $entity->is_active,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $entities,
        ]);
    }

    /**
     * Compartir entidades con una empresa.
     */
    public function share(Request $request, Enterprise $enterprise): JsonResponse
    {
        $validated = $request->validate([
            'entity_ids' => 'required|array|min:1',
            'entity_ids.*' => 'exists:entities,id',
            'access_level' => 'sometimes|in:read,write',
        ]);

        $accessLevel = $validated['access_level'] ?? 'write';

        $syncData = [];
        foreach ($validated['entity_ids'] as $entityId) {
            $syncData[$entityId] = ['access_level' => $accessLevel];
        }

        $enterprise->accessibleEntities()->syncWithoutDetaching($syncData);

        return response()->json([
            'success' => true,
            'message' => count($validated['entity_ids']) . ' entidad(es) compartida(s) exitosamente',
        ]);
    }

    /**
     * Revocar acceso a una entidad.
     */
    public function revoke(Request $request, Enterprise $enterprise, Entity $entity): JsonResponse
    {
        // No permitir revocar entidades propias
        if ($entity->branch && $entity->branch->enterprise_id === $enterprise->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede revocar acceso a una entidad propia de la empresa',
            ], 422);
        }

        $enterprise->accessibleEntities()->detach($entity->id);

        return response()->json([
            'success' => true,
            'message' => 'Acceso revocado exitosamente',
        ]);
    }

    /**
     * Actualizar nivel de acceso.
     */
    public function updateAccess(Request $request, Enterprise $enterprise, Entity $entity): JsonResponse
    {
        $validated = $request->validate([
            'access_level' => 'required|in:read,write',
        ]);

        $enterprise->accessibleEntities()->updateExistingPivot($entity->id, [
            'access_level' => $validated['access_level'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Nivel de acceso actualizado',
        ]);
    }
}
