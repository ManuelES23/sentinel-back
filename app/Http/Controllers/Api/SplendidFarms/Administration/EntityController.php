<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Events\EntityUpdated;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\EntityType;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EntityController extends Controller
{
    private function getCurrentEnterprise(Request $request): ?Enterprise
    {
        $slug = $request->header('X-Enterprise-Slug');
        if (!$slug) {
            return null;
        }

        return Enterprise::where('slug', $slug)->first();
    }

    /**
     * Entidades externas disponibles (otras empresas) para vincular como externa.
     * Solo tipos BODEGA/EMPAQUE.
     */
    public function externalCandidates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type_id' => 'nullable|exists:entity_types,id',
        ]);

        $currentEnterprise = $this->getCurrentEnterprise($request);
        if (!$currentEnterprise) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo determinar la empresa actual desde el header X-Enterprise-Slug',
            ], 422);
        }

        $query = Entity::with([
            'branch:id,enterprise_id,name,code',
            'branch.enterprise:id,name,slug',
            'entityType:id,code,name',
        ])
            ->where('is_active', true)
            ->whereHas('branch', function ($q) use ($currentEnterprise) {
                $q->where('enterprise_id', '!=', $currentEnterprise->id);
            })
            ->whereHas('entityType', function ($q) {
                $q->whereIn('code', ['BODEGA', 'EMPAQUE']);
            });

        if (!empty($validated['entity_type_id'])) {
            $query->where('entity_type_id', $validated['entity_type_id']);
        }

        $entities = $query
            ->orderBy('name')
            ->get()
            ->map(function ($entity) {
                return [
                    'id' => $entity->id,
                    'code' => $entity->code,
                    'name' => $entity->name,
                    'description' => $entity->description,
                    'location' => $entity->location,
                    'responsible' => $entity->responsible,
                    'area_m2' => $entity->area_m2,
                    'usa_hidrotermico' => (bool) $entity->usa_hidrotermico,
                    'entity_type' => [
                        'id' => $entity->entityType?->id,
                        'code' => $entity->entityType?->code,
                        'name' => $entity->entityType?->name,
                    ],
                    'source_branch' => [
                        'id' => $entity->branch?->id,
                        'name' => $entity->branch?->name,
                        'code' => $entity->branch?->code,
                    ],
                    'source_enterprise' => [
                        'id' => $entity->branch?->enterprise?->id,
                        'slug' => $entity->branch?->enterprise?->slug,
                        'name' => $entity->branch?->enterprise?->name,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $entities,
        ]);
    }

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
            'external_source_entity_id' => 'nullable|exists:entities,id',
            'code' => 'nullable|string|max:50|unique:entities,code',
            'abbreviation' => 'nullable|string|max:10',
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
            'usa_hidrotermico' => 'boolean',
        ]);

        $targetBranch = Branch::with('enterprise')->findOrFail($validated['branch_id']);
        $sourceEntity = null;

        if (!empty($validated['external_source_entity_id'])) {
            $sourceEntity = Entity::with(['branch.enterprise', 'entityType'])->findOrFail($validated['external_source_entity_id']);

            if ($sourceEntity->branch?->enterprise_id === $targetBranch->enterprise_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La entidad origen debe pertenecer a otra empresa',
                ], 422);
            }

            $allowedTypes = ['BODEGA', 'EMPAQUE'];
            if (!in_array($sourceEntity->entityType?->code, $allowedTypes, true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solo se pueden vincular entidades externas tipo Bodega o Empaque',
                ], 422);
            }

            $alreadyLinked = Entity::with('branch')
                ->whereHas('branch', function ($q) use ($targetBranch) {
                    $q->where('enterprise_id', $targetBranch->enterprise_id);
                })
                ->get()
                ->first(function ($entity) use ($sourceEntity) {
                    $meta = $entity->metadata ?? [];
                    return (int) ($meta['external_source_entity_id'] ?? 0) === (int) $sourceEntity->id;
                });

            if ($alreadyLinked) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta entidad externa ya fue vinculada en la empresa actual',
                ], 422);
            }

            $validated['entity_type_id'] = $sourceEntity->entity_type_id;
            $validated['is_external'] = true;
            $validated['owner_company'] = $sourceEntity->branch?->enterprise?->name;
            $validated['name'] = $validated['name'] ?: $sourceEntity->name;
            $validated['description'] = $validated['description'] ?: $sourceEntity->description;
            $validated['location'] = $validated['location'] ?: $sourceEntity->location;
            $validated['responsible'] = $validated['responsible'] ?: $sourceEntity->responsible;
            $validated['area_m2'] = $validated['area_m2'] ?: $sourceEntity->area_m2;
            $validated['usa_hidrotermico'] = $validated['usa_hidrotermico'] ?? (bool) $sourceEntity->usa_hidrotermico;

            $metadata = $validated['metadata'] ?? [];
            $validated['metadata'] = array_merge($metadata, [
                'external_source_entity_id' => $sourceEntity->id,
                'external_source_entity_code' => $sourceEntity->code,
                'external_source_enterprise_id' => $sourceEntity->branch?->enterprise?->id,
                'external_source_enterprise_slug' => $sourceEntity->branch?->enterprise?->slug,
                'external_source_enterprise_name' => $sourceEntity->branch?->enterprise?->name,
            ]);
        }

        unset($validated['external_source_entity_id']);

        $isAutoCode = empty($validated['code']);
        $maxAttempts = $isAutoCode ? 5 : 1;
        $entity = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $entity = DB::transaction(function () use ($validated, $isAutoCode) {
                    $data = $validated;

                    // Generación atómica del código por tipo para evitar colisiones en concurrencia.
                    if ($isAutoCode) {
                        $entityType = EntityType::query()
                            ->whereKey($data['entity_type_id'])
                            ->lockForUpdate()
                            ->firstOrFail();

                        $prefix = $entityType->code;
                        $lastCode = Entity::withTrashed()
                            ->where('code', 'like', $prefix . '-%')
                            ->select('code')
                            ->orderByRaw('CAST(SUBSTRING(code, ' . (strlen($prefix) + 2) . ') AS UNSIGNED) DESC')
                            ->value('code');

                        $nextNumber = $lastCode
                            ? ((int) substr($lastCode, strlen($prefix) + 1)) + 1
                            : 1;

                        $data['code'] = $prefix . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
                    }

                    // Evita colisiones de slug cuando se repite el nombre entre entidades.
                    $data['slug'] = $this->generateUniqueEntitySlug(
                        $data['slug'] ?? null,
                        $data['name'] ?? ''
                    );

                    return Entity::create($data);
                });

                break;
            } catch (QueryException $e) {
                if ((int) $e->getCode() !== 23000) {
                    throw $e;
                }

                $constraint = $this->getUniqueConstraintName($e);

                if ($this->isSlugConstraint($constraint)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Ya existe una entidad con ese nombre/slug. Cambia el nombre o captura un slug diferente.',
                    ], 422);
                }

                if (!$isAutoCode) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'El código capturado ya existe. Usa otro código o déjalo vacío para autogenerarlo.',
                    ], 422);
                }

                if (!$this->isCodeConstraint($constraint)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'No se pudo crear la entidad por conflicto de datos únicos. Revisa código y nombre.',
                    ], 422);
                }

                if ($attempt === $maxAttempts) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'No se pudo crear la entidad por colisión de código. Intenta nuevamente.',
                    ], 422);
                }
            }
        }

        if (!$entity) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo crear la entidad. Intenta nuevamente.',
            ], 422);
        }

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
            'abbreviation' => 'nullable|string|max:10',
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
            'usa_hidrotermico' => 'boolean',
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

    private function generateUniqueEntitySlug(?string $requestedSlug, string $name): string
    {
        $base = Str::slug($requestedSlug ?: $name);
        if ($base === '') {
            $base = 'entidad';
        }

        $slug = $base;
        $counter = 2;

        while (Entity::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function getUniqueConstraintName(QueryException $e): string
    {
        $details = strtolower((string) ($e->errorInfo[2] ?? ''));

        if (str_contains($details, 'entities.entities_code_unique')) {
            return 'entities.entities_code_unique';
        }

        if (str_contains($details, 'entities.entities_slug_unique')) {
            return 'entities.entities_slug_unique';
        }

        return $details;
    }

    private function isCodeConstraint(string $constraint): bool
    {
        return str_contains($constraint, 'entities.entities_code_unique') || str_contains($constraint, 'code');
    }

    private function isSlugConstraint(string $constraint): bool
    {
        return str_contains($constraint, 'entities.entities_slug_unique') || str_contains($constraint, 'slug');
    }
}
