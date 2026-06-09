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

    private function entityBelongsToEnterprise(Entity $entity, int $enterpriseId): bool
    {
        $entity->loadMissing('branch:id,enterprise_id');

        return (int) $entity->branch?->enterprise_id === $enterpriseId;
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
            })
            // Excluir entidades ya vinculadas a la empresa actual
            ->whereNotIn('id', function ($sub) use ($currentEnterprise) {
                $sub->select('entity_id')
                    ->from('enterprise_entity')
                    ->where('enterprise_id', $currentEnterprise->id);
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
     * Returns own entities + externally linked entities (via enterprise_entity pivot).
     */
    public function index(Request $request): JsonResponse
    {
        $currentEnterprise = $this->getCurrentEnterprise($request);
        if (!$currentEnterprise) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo determinar la empresa actual desde el header X-Enterprise-Slug',
            ], 422);
        }

        $relations = ['branch.enterprise:id,name,slug', 'entityType', 'areas', 'cultivos:id,nombre'];

        // ── 1. Entidades propias ──────────────────────────────────────────
        $ownQuery = Entity::with($relations)
            ->whereHas('branch', fn ($q) => $q->where('enterprise_id', $currentEnterprise->id));

        if ($request->has('branch_id')) {
            $ownQuery->byBranch($request->branch_id);
        }
        if ($request->has('entity_type_id')) {
            $ownQuery->byType($request->entity_type_id);
        }
        if ($request->boolean('active_only')) {
            $ownQuery->active();
        }
        if ($request->has('cultivo_id') && $request->cultivo_id) {
            $ownQuery->whereHas('cultivos', fn ($q) => $q->where('cultivos.id', $request->cultivo_id));
        }

        $ownEntities = $ownQuery->orderBy('name')->get()
            ->map(fn (Entity $e) => array_merge($e->toArray(), ['is_linked_external' => false]));

        // ── 2. Entidades vinculadas de otras empresas (pivot) ─────────────
        $linkedIds = DB::table('enterprise_entity')
            ->where('enterprise_id', $currentEnterprise->id)
            ->pluck('entity_id');

        $linkedEntities = collect();
        if ($linkedIds->isNotEmpty()) {
            $linkedEntities = Entity::with($relations)
                ->whereIn('id', $linkedIds)
                ->whereHas('branch', fn ($q) => $q->where('enterprise_id', '!=', $currentEnterprise->id))
                ->orderBy('name')
                ->get()
                ->map(fn (Entity $e) => array_merge($e->toArray(), ['is_linked_external' => true]));
        }

        $all = $ownEntities->concat($linkedEntities)->sortBy('name')->values();

        return response()->json([
            'success' => true,
            'data' => $all,
        ]);
    }

    /**
     * Vincular una entidad de otra empresa a la empresa actual mediante pivot.
     * No crea registros duplicados: reutiliza la entidad existente.
     */
    public function vincular(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_id'    => 'required|exists:entities,id',
            'access_level' => 'nullable|in:read,write',
        ]);

        $currentEnterprise = $this->getCurrentEnterprise($request);
        if (!$currentEnterprise) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo determinar la empresa actual desde el header X-Enterprise-Slug',
            ], 422);
        }

        $entity = Entity::with(['branch.enterprise:id,name,slug', 'entityType', 'areas', 'cultivos:id,nombre'])
            ->findOrFail($validated['entity_id']);

        // Debe pertenecer a una empresa diferente
        if ((int) $entity->branch?->enterprise_id === (int) $currentEnterprise->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'La entidad ya pertenece a la empresa actual',
            ], 422);
        }

        // Solo tipos BODEGA / EMPAQUE
        $allowedTypes = ['BODEGA', 'EMPAQUE'];
        if (!in_array($entity->entityType?->code, $allowedTypes, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden vincular entidades tipo Bodega o Empaque',
            ], 422);
        }

        // Ya vinculada?
        $alreadyLinked = DB::table('enterprise_entity')
            ->where('enterprise_id', $currentEnterprise->id)
            ->where('entity_id', $entity->id)
            ->exists();

        if ($alreadyLinked) {
            return response()->json([
                'status' => 'error',
                'message' => 'Esta entidad ya está vinculada a la empresa actual',
            ], 422);
        }

        DB::table('enterprise_entity')->insert([
            'enterprise_id' => $currentEnterprise->id,
            'entity_id'     => $entity->id,
            'access_level'  => $validated['access_level'] ?? 'write',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $entityData = array_merge($entity->toArray(), ['is_linked_external' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Entidad vinculada exitosamente',
            'data'    => $entityData,
        ], 201);
    }

    /**
     * Eliminar el vínculo de una entidad externa de la empresa actual.
     * No elimina la entidad: solo quita la entrada del pivot.
     */
    public function desvincular(Request $request, Entity $entity): JsonResponse
    {
        $currentEnterprise = $this->getCurrentEnterprise($request);
        if (!$currentEnterprise) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo determinar la empresa actual desde el header X-Enterprise-Slug',
            ], 422);
        }

        $entity->loadMissing('branch:id,enterprise_id');

        // Solo se pueden desvincular entidades de otras empresas
        if ((int) $entity->branch?->enterprise_id === (int) $currentEnterprise->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'No puedes desvincular una entidad propia',
            ], 422);
        }

        $deleted = DB::table('enterprise_entity')
            ->where('enterprise_id', $currentEnterprise->id)
            ->where('entity_id', $entity->id)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'status' => 'error',
                'message' => 'El vínculo no existe',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vínculo eliminado exitosamente',
        ]);
    }

    /**
     * Store a newly created resource in storage.
     * Para vincular entidades externas, usa POST /entidades/vincular en su lugar.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'entity_type_id' => 'required|exists:entity_types,id',
            'code' => 'nullable|string|max:50|unique:entities,code',
            'abbreviation' => 'nullable|string|max:10',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:entities,slug',
            'cultivo_ids' => 'nullable|array',
            'cultivo_ids.*' => 'integer|exists:cultivos,id',
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

        $currentEnterprise = $this->getCurrentEnterprise($request);
        if (!$currentEnterprise) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo determinar la empresa actual desde el header X-Enterprise-Slug',
            ], 422);
        }

        $targetBranch = Branch::with('enterprise')->findOrFail($validated['branch_id']);
        if ((int) $targetBranch->enterprise_id !== (int) $currentEnterprise->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'La sucursal seleccionada no pertenece a la empresa actual',
            ], 422);
        }

        $cultivoIds = $validated['cultivo_ids'] ?? [];
        unset($validated['cultivo_ids']);

        $isAutoCode = empty($validated['code']);
        $maxAttempts = $isAutoCode ? 5 : 1;
        $entity = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $entity = DB::transaction(function () use ($validated, $isAutoCode, $cultivoIds) {
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

                    $created = Entity::create($data);

                    if (!empty($cultivoIds)) {
                        $created->cultivos()->sync($cultivoIds);
                    }

                    return $created;
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

        $entity->load(['branch.enterprise:id,name,slug', 'entityType', 'areas', 'cultivos:id,nombre']);

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
    public function show(Request $request, Entity $entity): JsonResponse
    {
        $currentEnterprise = $this->getCurrentEnterprise($request);
        if (!$currentEnterprise) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo determinar la empresa actual desde el header X-Enterprise-Slug',
            ], 422);
        }

        if (!$this->entityBelongsToEnterprise($entity, $currentEnterprise->id)) {
            return response()->json([
                'status' => 'error',
                'message' => 'La entidad solicitada no pertenece a la empresa actual',
            ], 404);
        }

        $entity->load(['branch.enterprise:id,name,slug', 'entityType', 'areas', 'cultivos:id,nombre']);

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
        $currentEnterprise = $this->getCurrentEnterprise($request);
        if (!$currentEnterprise) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo determinar la empresa actual desde el header X-Enterprise-Slug',
            ], 422);
        }

        if (!$this->entityBelongsToEnterprise($entity, $currentEnterprise->id)) {
            return response()->json([
                'status' => 'error',
                'message' => 'La entidad que intentas editar no pertenece a la empresa actual',
            ], 404);
        }

        if ((bool) $entity->is_external) {
            return response()->json([
                'status' => 'error',
                'message' => 'Las entidades vinculadas desde otra empresa son de solo lectura',
            ], 422);
        }

        $validated = $request->validate([
            'branch_id' => 'sometimes|exists:branches,id',
            'entity_type_id' => 'sometimes|exists:entity_types,id',
            'code' => 'sometimes|string|max:50|unique:entities,code,' . $entity->id,
            'abbreviation' => 'nullable|string|max:10',
            'name' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:entities,slug,' . $entity->id,
            'cultivo_ids' => 'nullable|array',
            'cultivo_ids.*' => 'integer|exists:cultivos,id',
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

        if (isset($validated['branch_id'])) {
            $targetBranch = Branch::findOrFail($validated['branch_id']);
            if ((int) $targetBranch->enterprise_id !== (int) $currentEnterprise->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La sucursal seleccionada no pertenece a la empresa actual',
                ], 422);
            }
        }

        $cultivoIds = $validated['cultivo_ids'] ?? null;
        unset($validated['cultivo_ids']);

        $entity->update($validated);

        // Sincronizar cultivos si se enviaron (null = no tocar, [] = limpiar todos)
        if ($cultivoIds !== null) {
            $entity->cultivos()->sync($cultivoIds);
        }

        // Recargar la entidad con sus relaciones
        $entity = $entity->fresh(['branch.enterprise:id,name,slug', 'entityType', 'areas', 'cultivos:id,nombre']);

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
    public function destroy(Request $request, Entity $entity): JsonResponse
    {
        $currentEnterprise = $this->getCurrentEnterprise($request);
        if (!$currentEnterprise) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo determinar la empresa actual desde el header X-Enterprise-Slug',
            ], 422);
        }

        if (!$this->entityBelongsToEnterprise($entity, $currentEnterprise->id)) {
            return response()->json([
                'status' => 'error',
                'message' => 'La entidad que intentas eliminar no pertenece a la empresa actual',
            ], 404);
        }

        if ((bool) $entity->is_external) {
            return response()->json([
                'status' => 'error',
                'message' => 'Las entidades vinculadas desde otra empresa no se pueden eliminar desde este catálogo',
            ], 422);
        }

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
