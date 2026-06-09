<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementDetail;
use App\Models\InventoryStock;
use App\Models\InventoryKardex;
use App\Models\MovementType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryMovementController extends Controller
{
    /**
     * Obtiene el enterprise actual a partir del header X-Enterprise-Slug.
     */
    private function getEnterprise(Request $request): ?Enterprise
    {
        $slug = $request->header('X-Enterprise-Slug');
        if (!$slug) return null;
        return Enterprise::where('slug', $slug)->first();
    }

    /**
     * IDs de entidades accesibles por la empresa actual.
     */
    private function getAccessibleEntityIds(Request $request): array
    {
        $enterprise = $this->getEnterprise($request);
        if (!$enterprise) return [];
        return $enterprise->accessibleEntities()->pluck('entities.id')->toArray();
    }

    /**
     * IDs de entidades propias (la sucursal pertenece a la empresa actual).
     */
    private function getOwnEntityIds(Request $request): array
    {
        $enterprise = $this->getEnterprise($request);
        if (!$enterprise) return [];

        return Entity::whereHas('branch', function ($q) use ($enterprise) {
            $q->where('enterprise_id', $enterprise->id);
        })->pluck('id')->toArray();
    }

    private function getEntityOwnerEnterprise(?int $entityId): ?Enterprise
    {
        if (!$entityId) {
            return null;
        }

        $entity = Entity::with('branch.enterprise:id,name,slug')->find($entityId);

        return $entity?->branch?->enterprise;
    }

    private function buildApprovalMetadata(
        Request $request,
        ?int $sourceEntityId,
        ?int $destinationEntityId,
        ?array $existingMetadata = null
    ): array {
        $currentEnterprise = $this->getEnterprise($request);
        $metadata = $existingMetadata ?? [];

        if (!$currentEnterprise) {
            return $metadata;
        }

        $sourceOwner = $this->getEntityOwnerEnterprise($sourceEntityId);
        $destinationOwner = $this->getEntityOwnerEnterprise($destinationEntityId);

        $approvalEnterprise = collect([$sourceOwner, $destinationOwner])
            ->filter(fn ($enterprise) => $enterprise && (int) $enterprise->id !== (int) $currentEnterprise->id)
            ->first();

        $approvalMetadata = [
            'requires_external_validation' => (bool) $approvalEnterprise,
            'requesting_enterprise_id' => $currentEnterprise->id,
            'requesting_enterprise_slug' => $currentEnterprise->slug,
            'requesting_enterprise_name' => $currentEnterprise->name,
            'source_owner_enterprise_id' => $sourceOwner?->id,
            'source_owner_enterprise_slug' => $sourceOwner?->slug,
            'source_owner_enterprise_name' => $sourceOwner?->name,
            'destination_owner_enterprise_id' => $destinationOwner?->id,
            'destination_owner_enterprise_slug' => $destinationOwner?->slug,
            'destination_owner_enterprise_name' => $destinationOwner?->name,
            'approval_enterprise_id' => $approvalEnterprise?->id,
            'approval_enterprise_slug' => $approvalEnterprise?->slug,
            'approval_enterprise_name' => $approvalEnterprise?->name,
        ];

        return array_merge($metadata, $approvalMetadata);
    }

    /**
     * Entidades accesibles para selects del frontend.
     * Devuelve:
     *  - Entidades propias (isOwn: true)  → usables en entradas, salidas, ajustes y como origen de transferencias
     *  - Entidades vinculadas (isOwn: false) → solo como destino de transferencias y lectura de stock
     */
    public function accessibleEntities(Request $request): JsonResponse
    {
        $enterprise = $this->getEnterprise($request);
        if (!$enterprise) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // 1. Entidades propias
        $ownEntities = Entity::with(['branch:id,name,enterprise_id', 'branch.enterprise:id,name,slug', 'entityType:id,name,icon,color'])
            ->active()
            ->whereHas('branch', fn ($q) => $q->where('enterprise_id', $enterprise->id))
            ->get()
            ->map(fn ($entity) => [
                'id'             => $entity->id,
                'code'           => $entity->code,
                'name'           => $entity->name,
                'branch'         => $entity->branch?->name,
                'ownerEnterprise' => $entity->branch?->enterprise?->name,
                'entityType'     => $entity->entityType?->name,
                'entityTypeIcon' => $entity->entityType?->icon,
                'entityTypeColor'=> $entity->entityType?->color,
                'isOwn'          => true,
                'accessLevel'    => 'write',
            ]);

        // 2. Entidades vinculadas (pivot enterprise_entity, de otras empresas)
        $linkedIds = DB::table('enterprise_entity')
            ->where('enterprise_id', $enterprise->id)
            ->pluck('entity_id');

        $linkedEntities = collect();
        if ($linkedIds->isNotEmpty()) {
            $linkedEntities = Entity::with(['branch:id,name,enterprise_id', 'branch.enterprise:id,name,slug', 'entityType:id,name,icon,color'])
                ->active()
                ->whereIn('id', $linkedIds)
                ->whereHas('branch', fn ($q) => $q->where('enterprise_id', '!=', $enterprise->id))
                ->get()
                ->map(fn ($entity) => [
                    'id'             => $entity->id,
                    'code'           => $entity->code,
                    'name'           => $entity->name,
                    'branch'         => $entity->branch?->name,
                    'ownerEnterprise' => $entity->branch?->enterprise?->name,
                    'entityType'     => $entity->entityType?->name,
                    'entityTypeIcon' => $entity->entityType?->icon,
                    'entityTypeColor'=> $entity->entityType?->color,
                    'isOwn'          => false,
                    'accessLevel'    => 'read',
                ]);
        }

        $all = $ownEntities->concat($linkedEntities)->sortBy('name')->values();

        return response()->json([
            'success' => true,
            'data'    => $all,
        ]);
    }

    /**
     * Consultar stock de una entidad para validación del frontend.
     */
    public function entityStock(Request $request, Entity $entity): JsonResponse
    {
        $entityIds = $this->getAccessibleEntityIds($request);
        if (!in_array($entity->id, $entityIds)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes acceso a esta entidad',
            ], 403);
        }

        $query = InventoryStock::where('entity_id', $entity->id)
            ->where('quantity', '>', 0)
            ->with(['product:id,code,name,sku']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $stock = $query->get();

        return response()->json([
            'success' => true,
            'data' => $stock,
        ]);
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = InventoryMovement::with([
            'movementType:id,code,name,direction,effect,color,icon',
            'sourceEntity:id,name,code',
            'destinationEntity:id,name,code',
            'createdBy:id,name'
        ]);

        // Filtrar por entidades accesibles de la empresa actual
        $entityIds = $this->getAccessibleEntityIds($request);
        if (!empty($entityIds)) {
            $query->where(function ($q) use ($entityIds) {
                $q->whereIn('source_entity_id', $entityIds)
                  ->orWhereIn('destination_entity_id', $entityIds);
            });
        }

        // Filtrar por estado
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filtrar por tipo de movimiento
        if ($request->filled('movement_type_id')) {
            $query->where('movement_type_id', $request->movement_type_id);
        }

        // Filtrar por dirección
        if ($request->filled('direction')) {
            $query->whereHas('movementType', function ($q) use ($request) {
                $q->where('direction', $request->direction);
            });
        }

        // Filtrar por entidad origen
        if ($request->filled('source_entity_id')) {
            $query->where('source_entity_id', $request->source_entity_id);
        }

        // Filtrar por entidad destino
        if ($request->filled('destination_entity_id')) {
            $query->where('destination_entity_id', $request->destination_entity_id);
        }

        // Filtrar por rango de fechas
        if ($request->filled('date_from')) {
            $query->whereDate('movement_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('movement_date', '<=', $request->date_to);
        }

        // Búsqueda por documento
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('document_number', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Ordenamiento
        $sortBy = $request->input('sort_by', 'movement_date');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        // Paginación
        $perPage = $request->input('per_page', 20);
        $movements = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $movements
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'movement_type_id' => 'required|exists:movement_types,id',
            'source_entity_id' => 'nullable|integer',
            'source_entity_type' => 'nullable|string|max:100',
            'destination_entity_id' => 'nullable|integer',
            'destination_entity_type' => 'nullable|string|max:100',
            'reference_number' => 'nullable|string|max:100',
            'movement_date' => 'nullable|date',
            'description' => 'nullable|string',
            'metadata' => 'nullable|array',
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'details.*.unit_cost' => 'nullable|numeric|min:0',
            'details.*.lot_number' => 'nullable|string|max:100',
            'details.*.serial_number' => 'nullable|string|max:100',
            'details.*.expiry_date' => 'nullable|date',
            'details.*.notes' => 'nullable|string',
        ]);

        // Obtener tipo de movimiento
        $movementType = MovementType::findOrFail($validated['movement_type_id']);

        // Validaciones según tipo
        if ($movementType->requires_source_entity && empty($validated['source_entity_id'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Este tipo de movimiento requiere una entidad origen'
            ], 422);
        }

        if ($movementType->requires_destination_entity && empty($validated['destination_entity_id'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Este tipo de movimiento requiere una entidad destino'
            ], 422);
        }

        // Validar que las entidades sean accesibles por la empresa actual
        $entityIds = $this->getAccessibleEntityIds($request);
        if (!empty($entityIds)) {
            if (!empty($validated['source_entity_id']) && !in_array($validated['source_entity_id'], $entityIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tienes acceso a la entidad origen seleccionada'
                ], 403);
            }
            if (!empty($validated['destination_entity_id']) && !in_array($validated['destination_entity_id'], $entityIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tienes acceso a la entidad destino seleccionada'
                ], 403);
            }
        }

        // ── Restricciones de ownership por dirección ─────────────────────────
        // entradas/salidas/ajustes: la entidad operada debe ser propia.
        // transferencias: origen debe ser propio; destino puede ser propio o vinculada.
        $ownIds = $this->getOwnEntityIds($request);

        if (in_array($movementType->direction, ['in', 'out', 'adjustment'])) {
            $operatedEntityId = $movementType->direction === 'in'
                ? ($validated['destination_entity_id'] ?? null)
                : ($validated['source_entity_id'] ?? null);

            if ($operatedEntityId && !in_array((int) $operatedEntityId, array_map('intval', $ownIds))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Las entradas, salidas y ajustes solo pueden realizarse en entidades propias de la empresa. Las entidades vinculadas son de solo lectura.',
                ], 422);
            }
        }

        if ($movementType->direction === 'transfer') {
            $sourceId = $validated['source_entity_id'] ?? null;
            if ($sourceId && !in_array((int) $sourceId, array_map('intval', $ownIds))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El origen de una transferencia debe ser una entidad propia.',
                ], 422);
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        DB::beginTransaction();

        try {
            // Crear movimiento
            $movementMetadata = $this->buildApprovalMetadata(
                $request,
                $validated['source_entity_id'] ?? null,
                $validated['destination_entity_id'] ?? null,
                $validated['metadata'] ?? null,
            );

            $movement = InventoryMovement::create([
                'document_number' => InventoryMovement::generateDocumentNumber($movementType->direction),
                'movement_type_id' => $validated['movement_type_id'],
                'source_entity_id' => $validated['source_entity_id'] ?? null,
                'source_entity_type' => $validated['source_entity_type'] ?? null,
                'destination_entity_id' => $validated['destination_entity_id'] ?? null,
                'destination_entity_type' => $validated['destination_entity_type'] ?? null,
                'reference_number' => $validated['reference_number'] ?? null,
                'movement_date' => $validated['movement_date'] ?? now(),
                'description' => $validated['description'] ?? null,
                'status' => 'pending',
                'created_by' => Auth::id(),
                'metadata' => $movementMetadata,
            ]);

            // Crear detalles
            foreach ($validated['details'] as $detail) {
                InventoryMovementDetail::create([
                    'movement_id' => $movement->id,
                    'product_id' => $detail['product_id'],
                    'quantity' => $detail['quantity'],
                    'unit_id' => $detail['unit_id'] ?? null,
                    'unit_cost' => $detail['unit_cost'] ?? 0,
                    'lot_number' => $detail['lot_number'] ?? null,
                    'serial_number' => $detail['serial_number'] ?? null,
                    'expiry_date' => $detail['expiry_date'] ?? null,
                    'notes' => $detail['notes'] ?? null,
                ]);
            }

            // Recalcular totales
            $movement->recalculateTotals();

            DB::commit();

            $movement->load(['movementType', 'details.product:id,code,name', 'createdBy:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Movimiento creado exitosamente',
                'data' => $movement
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear el movimiento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(InventoryMovement $movement): JsonResponse
    {
        $movement->load([
            'movementType',
            'sourceEntity',
            'destinationEntity',
            'details.product:id,code,name,sku',
            'details.unit:id,name,abbreviation',
            'createdBy:id,name',
            'approvedBy:id,name'
        ]);

        return response()->json([
            'success' => true,
            'data' => $movement
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, InventoryMovement $movement): JsonResponse
    {
        // Solo se pueden editar movimientos pendientes
        if ($movement->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden editar movimientos pendientes'
            ], 422);
        }

        $validated = $request->validate([
            'source_entity_id' => 'nullable|integer',
            'source_entity_type' => 'nullable|string|max:100',
            'destination_entity_id' => 'nullable|integer',
            'destination_entity_type' => 'nullable|string|max:100',
            'reference_number' => 'nullable|string|max:100',
            'movement_date' => 'nullable|date',
            'description' => 'nullable|string',
            'metadata' => 'nullable|array',
            'details' => 'sometimes|array|min:1',
            'details.*.id' => 'nullable|exists:inventory_movement_details,id',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'details.*.unit_cost' => 'nullable|numeric|min:0',
            'details.*.lot_number' => 'nullable|string|max:100',
            'details.*.serial_number' => 'nullable|string|max:100',
            'details.*.expiry_date' => 'nullable|date',
            'details.*.notes' => 'nullable|string',
        ]);

        // Validar que las entidades finales sean accesibles por la empresa actual
        $entityIds = $this->getAccessibleEntityIds($request);
        if (!empty($entityIds)) {
            $sourceId = $validated['source_entity_id'] ?? $movement->source_entity_id;
            $destinationId = $validated['destination_entity_id'] ?? $movement->destination_entity_id;

            if (!empty($sourceId) && !in_array((int) $sourceId, array_map('intval', $entityIds))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tienes acceso a la entidad origen seleccionada',
                ], 403);
            }

            if (!empty($destinationId) && !in_array((int) $destinationId, array_map('intval', $entityIds))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tienes acceso a la entidad destino seleccionada',
                ], 403);
            }
        }

        // ── Restricciones de ownership en update ─────────────────────────────
        $ownIds = $this->getOwnEntityIds($request);
        $movementType = $movement->movementType;

        if ($movementType && in_array($movementType->direction, ['in', 'out', 'adjustment'])) {
            $operatedEntityId = $movementType->direction === 'in'
                ? ($validated['destination_entity_id'] ?? $movement->destination_entity_id)
                : ($validated['source_entity_id'] ?? $movement->source_entity_id);

            if ($operatedEntityId && !in_array((int) $operatedEntityId, array_map('intval', $ownIds))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Las entradas, salidas y ajustes solo pueden realizarse en entidades propias.',
                ], 422);
            }
        }

        if ($movementType && $movementType->direction === 'transfer') {
            $sourceId = $validated['source_entity_id'] ?? $movement->source_entity_id;
            if ($sourceId && !in_array((int) $sourceId, array_map('intval', $ownIds))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El origen de una transferencia debe ser una entidad propia.',
                ], 422);
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        DB::beginTransaction();

        try {
            // Actualizar movimiento
            $movementMetadata = $this->buildApprovalMetadata(
                $request,
                $validated['source_entity_id'] ?? $movement->source_entity_id,
                $validated['destination_entity_id'] ?? $movement->destination_entity_id,
                $validated['metadata'] ?? $movement->metadata,
            );

            $movement->update([
                'source_entity_id' => $validated['source_entity_id'] ?? $movement->source_entity_id,
                'source_entity_type' => $validated['source_entity_type'] ?? $movement->source_entity_type,
                'destination_entity_id' => $validated['destination_entity_id'] ?? $movement->destination_entity_id,
                'destination_entity_type' => $validated['destination_entity_type'] ?? $movement->destination_entity_type,
                'reference_number' => $validated['reference_number'] ?? $movement->reference_number,
                'movement_date' => $validated['movement_date'] ?? $movement->movement_date,
                'description' => $validated['description'] ?? $movement->description,
                'metadata' => $movementMetadata,
            ]);

            // Actualizar detalles si se proporcionan
            if (isset($validated['details'])) {
                $detailIds = collect($validated['details'])->pluck('id')->filter()->toArray();
                
                // Eliminar detalles que no están en la lista
                $movement->details()->whereNotIn('id', $detailIds)->delete();

                foreach ($validated['details'] as $detail) {
                    if (isset($detail['id'])) {
                        // Actualizar existente
                        InventoryMovementDetail::where('id', $detail['id'])->update([
                            'product_id' => $detail['product_id'],
                            'quantity' => $detail['quantity'],
                            'unit_id' => $detail['unit_id'] ?? null,
                            'unit_cost' => $detail['unit_cost'] ?? 0,
                            'lot_number' => $detail['lot_number'] ?? null,
                            'serial_number' => $detail['serial_number'] ?? null,
                            'expiry_date' => $detail['expiry_date'] ?? null,
                            'notes' => $detail['notes'] ?? null,
                        ]);
                    } else {
                        // Crear nuevo
                        InventoryMovementDetail::create([
                            'movement_id' => $movement->id,
                            'product_id' => $detail['product_id'],
                            'quantity' => $detail['quantity'],
                            'unit_id' => $detail['unit_id'] ?? null,
                            'unit_cost' => $detail['unit_cost'] ?? 0,
                            'lot_number' => $detail['lot_number'] ?? null,
                            'serial_number' => $detail['serial_number'] ?? null,
                            'expiry_date' => $detail['expiry_date'] ?? null,
                            'notes' => $detail['notes'] ?? null,
                        ]);
                    }
                }

                // Recalcular totales
                $movement->recalculateTotals();
            }

            DB::commit();

            $movement = $movement->fresh(['movementType', 'details.product:id,code,name', 'createdBy:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Movimiento actualizado exitosamente',
                'data' => $movement
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar el movimiento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(InventoryMovement $movement): JsonResponse
    {
        // Solo se pueden eliminar movimientos pendientes o cancelados
        if (!in_array($movement->status, ['pending', 'cancelled'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden eliminar movimientos pendientes o cancelados'
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Eliminar detalles
            $movement->details()->delete();
            
            // Eliminar movimiento
            $movement->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Movimiento eliminado exitosamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar el movimiento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve and execute the movement.
     */
    public function approve(Request $request, InventoryMovement $movement): JsonResponse
    {
        if ($movement->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden aprobar movimientos pendientes'
            ], 422);
        }

        $userEnterpriseId = Auth::user()?->employee?->enterprise_id;
        $requiresExternalValidation = (bool) data_get($movement->metadata, 'requires_external_validation', false);
        $approvalEnterpriseId = data_get($movement->metadata, 'approval_enterprise_id');

        if ($requiresExternalValidation && $approvalEnterpriseId && (int) $userEnterpriseId !== (int) $approvalEnterpriseId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Este movimiento debe validarse por la empresa propietaria de la entidad externa',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $movementType = $movement->movementType;

            // Validar stock suficiente para salidas y transferencias
            if (in_array($movementType->direction, ['out', 'transfer']) ||
                ($movementType->direction === 'adjustment' && $movementType->effect === 'decrease')) {

                $entityId = $movement->source_entity_id;
                $insufficientStock = [];

                foreach ($movement->details as $detail) {
                    $qty = $detail->base_quantity ?? $detail->quantity;
                    $stock = InventoryStock::where('product_id', $detail->product_id)
                        ->where('entity_id', $entityId)
                        ->when($detail->lot_number, fn($q) => $q->where('lot_number', $detail->lot_number))
                        ->sum('quantity');

                    if ($stock < $qty) {
                        $productName = $detail->product?->name ?? "ID:{$detail->product_id}";
                        $insufficientStock[] = "{$productName} (disponible: {$stock}, requerido: {$qty})";
                    }
                }

                if (!empty($insufficientStock)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Stock insuficiente para los siguientes productos: ' . implode(', ', $insufficientStock),
                    ], 422);
                }
            }

            foreach ($movement->details as $detail) {
                // Procesar según dirección y efecto
                if ($movementType->direction === 'in' || 
                    ($movementType->direction === 'adjustment' && $movementType->effect === 'increase')) {
                    // Entrada: incrementar stock en destino
                    $this->increaseStock(
                        $detail,
                        $movement->destination_entity_id,
                        $movement->destination_entity_type,
                        $movement
                    );
                } elseif ($movementType->direction === 'out' || 
                          ($movementType->direction === 'adjustment' && $movementType->effect === 'decrease')) {
                    // Salida: decrementar stock en origen
                    $this->decreaseStock(
                        $detail,
                        $movement->source_entity_id,
                        $movement->source_entity_type,
                        $movement
                    );
                } elseif ($movementType->direction === 'transfer') {
                    // Transferencia: decrementar origen, incrementar destino
                    $this->decreaseStock(
                        $detail,
                        $movement->source_entity_id,
                        $movement->source_entity_type,
                        $movement
                    );
                    $this->increaseStock(
                        $detail,
                        $movement->destination_entity_id,
                        $movement->destination_entity_type,
                        $movement
                    );
                }
            }

            // Actualizar estado del movimiento
            $movement->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Movimiento aprobado y ejecutado exitosamente',
                'data' => $movement->fresh(['movementType', 'details', 'approvedBy:id,name'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al aprobar el movimiento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel an approved movement (reverse stock).
     */
    public function cancel(InventoryMovement $movement, Request $request): JsonResponse
    {
        if (!in_array($movement->status, ['pending', 'approved'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden cancelar movimientos pendientes o aprobados'
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        DB::beginTransaction();

        try {
            // Si estaba aprobado, revertir stock
            if ($movement->status === 'approved') {
                $movementType = $movement->movementType;

                foreach ($movement->details as $detail) {
                    // Revertir según dirección (inverso de approve)
                    if ($movementType->direction === 'in' || 
                        ($movementType->direction === 'adjustment' && $movementType->effect === 'increase')) {
                        $this->decreaseStock(
                            $detail,
                            $movement->destination_entity_id,
                            $movement->destination_entity_type,
                            $movement,
                            true // isReversal
                        );
                    } elseif ($movementType->direction === 'out' || 
                              ($movementType->direction === 'adjustment' && $movementType->effect === 'decrease')) {
                        $this->increaseStock(
                            $detail,
                            $movement->source_entity_id,
                            $movement->source_entity_type,
                            $movement,
                            true
                        );
                    } elseif ($movementType->direction === 'transfer') {
                        $this->increaseStock(
                            $detail,
                            $movement->source_entity_id,
                            $movement->source_entity_type,
                            $movement,
                            true
                        );
                        $this->decreaseStock(
                            $detail,
                            $movement->destination_entity_id,
                            $movement->destination_entity_type,
                            $movement,
                            true
                        );
                    }
                }
            }

            // Actualizar estado
            $movement->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancellation_reason' => $validated['reason'],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Movimiento cancelado exitosamente',
                'data' => $movement->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cancelar el movimiento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Increase stock for a product.
     */
    private function increaseStock($detail, $entityId, $entityType, $movement, $isReversal = false): void
    {
        $quantity = $detail->base_quantity ?? $detail->quantity;

        // Actualizar o crear stock
        InventoryStock::updateStock(
            $detail->product_id,
            $entityId,
            null, // area_id (no manejado en este contexto)
            $quantity,
            $detail->unit_cost ?? 0,
            $detail->lot_number,
            $detail->expiry_date,
            $movement->id
        );

        $productorId = $this->resolveProductorIdFromDetail($detail);

        // Registrar en kardex
        InventoryKardex::recordEntry(
            $detail->product_id,
            $entityId,
            $entityType,
            $movement->id,
            $isReversal ? 'decrease' : 'increase',
            $quantity,
            $detail->unit_cost ?? 0,
            $detail->lot_number,
            $detail->serial_number,
            null, // area_id
            $productorId
        );
    }

    /**
     * Decrease stock for a product.
     */
    private function decreaseStock($detail, $entityId, $entityType, $movement, $isReversal = false): void
    {
        $quantity = $detail->base_quantity ?? $detail->quantity;

        // Actualizar stock (cantidad negativa para decrementar)
        InventoryStock::updateStock(
            $detail->product_id,
            $entityId,
            null, // area_id (no manejado en este contexto)
            -$quantity,
            $detail->unit_cost ?? 0,
            $detail->lot_number,
            $detail->expiry_date,
            $movement->id
        );

        $productorId = $this->resolveProductorIdFromDetail($detail);

        // Registrar en kardex
        InventoryKardex::recordEntry(
            $detail->product_id,
            $entityId,
            $entityType,
            $movement->id,
            $isReversal ? 'increase' : 'decrease',
            $quantity,
            $detail->unit_cost ?? 0,
            $detail->lot_number,
            $detail->serial_number,
            null, // area_id
            $productorId
        );
    }

    /**
     * Intenta resolver productor_id para persistirlo en kardex.
     */
    private function resolveProductorIdFromDetail($detail): ?int
    {
        if (!empty($detail->productor_id)) {
            return (int) $detail->productor_id;
        }

        if (!empty($detail->lote_id)) {
            $lote = \App\Models\Lote::find($detail->lote_id);
            return $lote?->productor_id ? (int) $lote->productor_id : null;
        }

        if (!empty($detail->lot_number) && is_numeric($detail->lot_number)) {
            $lote = \App\Models\Lote::where('numero_lote', (int) $detail->lot_number)->first();
            return $lote?->productor_id ? (int) $lote->productor_id : null;
        }

        return null;
    }
}
