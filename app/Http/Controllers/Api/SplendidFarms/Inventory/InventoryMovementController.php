<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Events\InventoryMovementUpdated;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementDetail;
use App\Models\InventoryStock;
use App\Models\InventoryKardex;
use App\Models\MovementType;
use App\Models\Product;
use App\Services\Inventory\AlmacenAccessService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class InventoryMovementController extends Controller
{
    public function __construct(private AlmacenAccessService $almacenes)
    {
    }

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
     * IDs de entidades que el usuario puede ver en la empresa actual
     * (asignadas en user_entity_access, o todas con ver_todos_almacenes).
     * Lanza 422/403 si la empresa del header no es válida para el usuario.
     */
    private function getAccessibleEntityIds(Request $request): array
    {
        $empresa = $this->almacenes->resolverEmpresa($request);

        return $this->almacenes->idsVisibles($request->user(), $empresa);
    }

    /**
     * IDs de entidades propias (la sucursal pertenece a la empresa actual)
     * que además son visibles para el usuario.
     */
    private function getOwnEntityIds(Request $request): array
    {
        $enterprise = $this->almacenes->resolverEmpresa($request);

        $own = Entity::whereHas('branch', function ($q) use ($enterprise) {
            $q->where('enterprise_id', $enterprise->id);
        })->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_intersect($own, $this->getAccessibleEntityIds($request)));
    }

    private function movimientoVisible(Request $request, InventoryMovement $movement): bool
    {
        $ids = $this->getAccessibleEntityIds($request);

        return in_array((int) $movement->source_entity_id, $ids, true)
            || in_array((int) $movement->destination_entity_id, $ids, true);
    }

    /**
     * Entidad cuyo stock afecta la acción: destino en entradas, ajustes
     * positivos y transferencias (el receptor aprueba); origen en salidas
     * y ajustes negativos.
     */
    private function entidadOperada(MovementType $type, InventoryMovement $movement): ?int
    {
        $usaDestino = $type->direction === 'in'
            || $type->direction === 'transfer'
            || ($type->direction === 'adjustment' && $type->effect === 'increase');

        $id = $usaDestino ? $movement->destination_entity_id : $movement->source_entity_id;

        return $id ? (int) $id : null;
    }

    private function noVisible(string $mensaje = 'Movimiento no encontrado', int $status = 404): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $mensaje], $status);
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
        $enterprise = $this->almacenes->resolverEmpresa($request);
        $visibles = $this->almacenes->idsVisibles($request->user(), $enterprise);

        // 1. Entidades propias
        $ownEntities = Entity::with(['branch:id,name,enterprise_id', 'branch.enterprise:id,name,slug', 'entityType:id,name,icon,color'])
            ->active()
            ->whereHas('branch', fn ($q) => $q->where('enterprise_id', $enterprise->id))
            ->whereIn('id', $visibles)
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
                ->whereIn('id', $linkedIds->intersect($visibles)->values())
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
     * Retorna el siguiente número de folio para una dirección dada.
     * GET ?direction=transfer|in|out|adjustment
     */
    public function nextFolio(Request $request): JsonResponse
    {
        $direction = $request->input('direction', 'transfer');
        $nextFolio = InventoryMovement::generateDocumentNumber($direction);

        return response()->json([
            'success' => true,
            'data' => ['next_folio' => $nextFolio],
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $with = [
            'movementType:id,code,name,direction,effect,color,icon',
            'sourceEntity:id,name,code',
            'destinationEntity:id,name,code',
            'createdBy:id,name',
        ];

        if ($request->boolean('include_details')) {
            $with[] = 'details.product:id,code,name,sku,brand_id,category_id,unit_id';
            $with[] = 'details.product.brand:id,name,code';
            $with[] = 'details.product.category:id,name,code';
            $with[] = 'details.product.unit:id,name,abbreviation';
            $with[] = 'details.unit:id,name,abbreviation';
        }

        $query = InventoryMovement::with($with)->withCount('details');

        // Filtrar por entidades visibles para el usuario (vacío = no ve nada)
        $entityIds = $this->getAccessibleEntityIds($request);
        $query->where(function ($q) use ($entityIds) {
            $q->whereIn('source_entity_id', $entityIds)
              ->orWhereIn('destination_entity_id', $entityIds);
        });

        // Filtrar por estado
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filtrar por tipo de movimiento (singular o array)
        if ($request->filled('movement_type_id')) {
            $query->where('movement_type_id', $request->movement_type_id);
        } elseif ($request->filled('movement_type_ids')) {
            $ids = array_filter(array_map('intval', explode(',', $request->movement_type_ids)));
            if (!empty($ids)) {
                $query->whereIn('movement_type_id', $ids);
            }
        }

        // Filtrar por dirección del tipo de movimiento
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
        if (!empty($validated['source_entity_id']) && !in_array((int) $validated['source_entity_id'], $entityIds, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes acceso a la entidad origen seleccionada'
            ], 403);
        }
        if (!empty($validated['destination_entity_id']) && !in_array((int) $validated['destination_entity_id'], $entityIds, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes acceso a la entidad destino seleccionada'
            ], 403);
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

        // Validar stock desde la creación para salidas/transferencias/ajustes negativos.
        // Esto evita que se registren operaciones inviables que luego fallen al aprobar.
        $requiresStockValidation =
            in_array($movementType->direction, ['out', 'transfer']) ||
            ($movementType->direction === 'adjustment' && $movementType->effect === 'decrease');

        if ($requiresStockValidation) {
            $sourceEntityId = (int) ($validated['source_entity_id'] ?? 0);
            $insufficientStock = $this->findInsufficientStockItems($sourceEntityId, $validated['details'] ?? []);

            if (!empty($insufficientStock)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Stock insuficiente para los siguientes productos: ' . implode(', ', $insufficientStock),
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
                'document_number' => filled($validated['document_number'] ?? null)
                    ? trim($validated['document_number'])
                    : InventoryMovement::generateDocumentNumber($movementType->direction),
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

            // Transferencias: descontar stock del origen de inmediato (el emisor pierde el stock
            // al iniciar la transferencia; el receptor lo recibe al aprobar).
            if ($movementType->direction === 'transfer') {
                $movement->load('details.product:id,name');
                foreach ($movement->details as $detail) {
                    $this->decreaseStock(
                        $detail,
                        $movement->source_entity_id,
                        $movement->source_entity_type,
                        $movement
                    );
                }
                // Marcar en metadata para que approve() sepa que el origen ya fue descontado
                $movement->metadata = array_merge($movement->metadata ?? [], [
                    'stock_deducted_at_creation' => true,
                    'stock_deducted_at' => now()->toISOString(),
                ]);
                $movement->saveQuietly();
            }

            DB::commit();

            $movement->load([
                'movementType',
                'details.product:id,code,name,sku,brand_id,category_id,unit_id',
                'details.product.brand:id,name,code',
                'details.product.category:id,name,code',
                'details.product.unit:id,name,abbreviation',
                'details.unit:id,name,abbreviation',
                'createdBy:id,name',
            ]);

            $this->broadcastMovement('created', $movement);

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
    public function show(Request $request, InventoryMovement $movement): JsonResponse
    {
        if (! $this->movimientoVisible($request, $movement)) {
            return $this->noVisible();
        }

        $movement->load([
            'movementType',
            'sourceEntity',
            'destinationEntity',
            'details.product:id,code,name,sku,brand_id,category_id,unit_id',
            'details.product.brand:id,name,code',
            'details.product.category:id,name,code',
            'details.product.unit:id,name,abbreviation',
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

        $requiresStockValidation =
            ($movementType && in_array($movementType->direction, ['out', 'transfer'])) ||
            ($movementType && $movementType->direction === 'adjustment' && $movementType->effect === 'decrease');

        if ($requiresStockValidation) {
            $sourceEntityId = (int) ($validated['source_entity_id'] ?? $movement->source_entity_id ?? 0);

            $detailsForValidation = $validated['details'] ?? $movement->details()
                ->get(['product_id', 'quantity', 'base_quantity', 'conversion_factor', 'lot_number'])
                ->map(fn ($detail) => [
                    'product_id' => $detail->product_id,
                    'quantity' => $detail->quantity,
                    'base_quantity' => $detail->base_quantity,
                    'conversion_factor' => $detail->conversion_factor,
                    'lot_number' => $detail->lot_number,
                ])
                ->toArray();

            $insufficientStock = $this->findInsufficientStockItems($sourceEntityId, $detailsForValidation);

            if (!empty($insufficientStock)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Stock insuficiente para los siguientes productos: ' . implode(', ', $insufficientStock),
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

            $movement = $movement->fresh([
                'movementType',
                'details.product:id,code,name,sku,brand_id,category_id,unit_id',
                'details.product.brand:id,name,code',
                'details.product.category:id,name,code',
                'details.product.unit:id,name,abbreviation',
                'details.unit:id,name,abbreviation',
                'createdBy:id,name',
            ]);

            $this->broadcastMovement('updated', $movement);

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
    public function destroy(Request $request, InventoryMovement $movement): JsonResponse
    {
        if (! $this->movimientoVisible($request, $movement)) {
            return $this->noVisible();
        }

        // Solo se pueden eliminar movimientos pendientes o cancelados
        if (!in_array($movement->status, ['pending', 'cancelled'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden eliminar movimientos pendientes o cancelados'
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Si es una transferencia pendiente con stock ya descontado, revertir antes de borrar
            if ($movement->status === 'pending') {
                $movement->loadMissing(['movementType', 'details.product:id,name']);
                if (
                    $movement->movementType?->direction === 'transfer' &&
                    data_get($movement->metadata, 'stock_deducted_at_creation', false)
                ) {
                    foreach ($movement->details as $detail) {
                        $this->increaseStock(
                            $detail,
                            $movement->source_entity_id,
                            $movement->source_entity_type,
                            $movement,
                            true // isReversal
                        );
                    }
                }
            }

            // Eliminar detalles
            $movement->details()->delete();
            
            // Eliminar movimiento
            $movement->delete();

            DB::commit();

            $this->broadcastMovement('deleted', $movement);

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

        $tipoOperado = $movement->movementType;
        $entidad = $tipoOperado ? $this->entidadOperada($tipoOperado, $movement) : null;
        if (! in_array((int) $entidad, $this->getAccessibleEntityIds($request), true)) {
            return $this->noVisible('No tienes acceso al almacén de este movimiento', 403);
        }

        $userEnterpriseId = $this->resolveUserEnterpriseId($request);
        $requiresExternalValidation = (bool) data_get($movement->metadata, 'requires_external_validation', false);
        $approvalEnterpriseId = data_get($movement->metadata, 'approval_enterprise_id');

        if ($requiresExternalValidation && $approvalEnterpriseId && (int) $userEnterpriseId !== (int) $approvalEnterpriseId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Este movimiento debe validarse por la empresa propietaria de la entidad externa',
            ], 403);
        }

        $validated = $request->validate([
            'received_quantities' => 'nullable|array',
            'received_quantities.*.detail_id' => 'required_with:received_quantities|integer|exists:inventory_movement_details,id',
            'received_quantities.*.quantity' => 'required_with:received_quantities|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $movementType = $movement->movementType;
            $movement->loadMissing('details.product:id,name');

            $receivedQuantities = collect($validated['received_quantities'] ?? [])
                ->keyBy(fn ($row) => (int) ($row['detail_id'] ?? 0));

            $allowsReceptionValidation =
                $movementType?->direction === 'transfer' &&
                $requiresExternalValidation;

            if ($receivedQuantities->isNotEmpty()) {
                if (! $allowsReceptionValidation) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Solo las transferencias con validación externa permiten registrar recepción por cantidades',
                    ], 422);
                }

                $movementDetailIds = $movement->details->pluck('id')->map(fn ($id) => (int) $id);
                $unknownIds = $receivedQuantities->keys()->diff($movementDetailIds);

                if ($unknownIds->isNotEmpty()) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Se recibieron detalles inválidos para esta transferencia',
                    ], 422);
                }
            }

            $receptionSummaryLines = [];

            foreach ($movement->details as $detail) {
                $requestedQuantity = (float) ($detail->quantity ?? 0);
                $requestedBaseQuantity = (float) ($detail->base_quantity ?? 0);

                if ($requestedQuantity <= 0 && $requestedBaseQuantity > 0) {
                    $requestedQuantity = $requestedBaseQuantity;
                }

                $receivedQuantity = $requestedQuantity;

                if ($allowsReceptionValidation && $receivedQuantities->has((int) $detail->id)) {
                    $receivedQuantity = (float) ($receivedQuantities->get((int) $detail->id)['quantity'] ?? 0);

                    if ($receivedQuantity > $requestedQuantity) {
                        $productName = $detail->product?->name ?? "ID:{$detail->product_id}";
                        DB::rollBack();
                        return response()->json([
                            'status' => 'error',
                            'message' => "La cantidad recibida de {$productName} no puede exceder lo transferido ({$requestedQuantity})",
                        ], 422);
                    }
                }

                $conversionFactor = (float) ($detail->conversion_factor ?? 1);
                $receivedBaseQuantity = round($receivedQuantity * ($conversionFactor > 0 ? $conversionFactor : 1), 4);

                // Persistir cantidades validadas de recepción para trazabilidad y reportes.
                if ($allowsReceptionValidation && abs($receivedQuantity - $requestedQuantity) > 0.0001) {
                    $detail->forceFill([
                        'quantity' => $receivedQuantity,
                        'base_quantity' => $receivedBaseQuantity,
                        'total_cost' => round($receivedBaseQuantity * (float) ($detail->unit_cost ?? 0), 4),
                        'notes' => trim(((string) ($detail->notes ?? '')) . " | Recibido: {$receivedQuantity} de {$requestedQuantity}"),
                    ])->save();
                }

                $receptionSummaryLines[] = [
                    'detail_id' => (int) $detail->id,
                    'product_id' => (int) $detail->product_id,
                    'quantity_requested' => $requestedQuantity,
                    'quantity_received' => $receivedQuantity,
                    'difference' => round($requestedQuantity - $receivedQuantity, 4),
                ];
            }

            // Validar stock suficiente para salidas/transferencias/ajustes negativos.
            // En recepción externa de transferencia no se vuelve a validar aquí,
            // porque ya debió validarse al registrar/editar la transferencia emisora.
            $skipStockValidationOnReception =
                $movementType->direction === 'transfer' && $allowsReceptionValidation;

            if (! $skipStockValidationOnReception &&
                (in_array($movementType->direction, ['out', 'transfer']) ||
                ($movementType->direction === 'adjustment' && $movementType->effect === 'decrease'))) {

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

            // Recargar detalles por si hubo ajuste de cantidades recibido/transferido.
            $movement->load('details.product:id,name');

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
                    // Transferencia: el origen se descontó al crear; solo incrementar destino.
                    // Para transferencias antiguas (sin la marca) seguir descontando origen.
                    $stockAlreadyDeducted = (bool) data_get($movement->metadata, 'stock_deducted_at_creation', false);
                    if (!$stockAlreadyDeducted) {
                        $this->decreaseStock(
                            $detail,
                            $movement->source_entity_id,
                            $movement->source_entity_type,
                            $movement
                        );
                    }
                    $this->increaseStock(
                        $detail,
                        $movement->destination_entity_id,
                        $movement->destination_entity_type,
                        $movement
                    );
                }
            }

            if ($allowsReceptionValidation) {
                $movementMetadata = $movement->metadata ?? [];
                $movementMetadata['external_reception'] = [
                    'validated_at' => now()->toISOString(),
                    'validated_by_user_id' => Auth::id(),
                    'validated_by_enterprise_id' => $userEnterpriseId,
                    'lines' => $receptionSummaryLines,
                ];

                $movement->metadata = $movementMetadata;
            }

            // Actualizar estado del movimiento
            $movement->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
            ]);

            DB::commit();

            $freshMovement = $movement->fresh(['movementType', 'sourceEntity:id,name,code', 'destinationEntity:id,name,code', 'details', 'approvedBy:id,name', 'createdBy:id,name']);
            $this->broadcastMovement('approved', $freshMovement);

            return response()->json([
                'success' => true,
                'message' => 'Movimiento aprobado y ejecutado exitosamente',
                'data' => $freshMovement
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
     * Descargar PDF del movimiento con detalle de líneas.
     */
    public function pdf(Request $request, InventoryMovement $movement): Response
    {
        abort_unless($this->movimientoVisible($request, $movement), 404);

        $movement->load([
            'movementType:id,code,name,direction,effect',
            'sourceEntity:id,name,code',
            'destinationEntity:id,name,code',
            'details.product:id,code,name,sku',
            'details.unit:id,name,abbreviation',
            'createdBy:id,name',
            'approvedBy:id,name',
        ]);

        $pdf = Pdf::loadView('pdf.inventory.movement', [
            'movement' => $movement,
        ])->setPaper('a4', 'portrait');

        $safeNumber = preg_replace('/[^A-Za-z0-9\-_]/', '-', (string) ($movement->document_number ?? $movement->id));
        return $pdf->download("movimiento-{$safeNumber}.pdf");
    }

    /**
     * Emite un evento de broadcast a todas las empresas con entidades involucradas en el movimiento.
     * Para transferencias cross-enterprise, notifica tanto al emisor como al receptor.
     */
    private function broadcastMovement(string $action, InventoryMovement $movement): void
    {
        try {
            $movement->loadMissing([
                'movementType:id,code,name,direction,effect,color,icon',
                'sourceEntity:id,name,code',
                'destinationEntity:id,name,code',
                'createdBy:id,name',
            ]);

            $slugs = [];

            if ($movement->source_entity_id) {
                $src = $this->getEntityOwnerEnterprise($movement->source_entity_id);
                if ($src?->slug) $slugs[] = $src->slug;
            }

            if ($movement->destination_entity_id) {
                $dst = $this->getEntityOwnerEnterprise($movement->destination_entity_id);
                if ($dst?->slug) $slugs[] = $dst->slug;
            }

            $reqSlug = request()->header('X-Enterprise-Slug');
            if ($reqSlug) $slugs[] = $reqSlug;

            $slugs = array_values(array_unique(array_filter($slugs)));
            if (empty($slugs)) return;

            $data = $movement->toArray();
            $data['details_count'] = $movement->details()->count();

            event(new InventoryMovementUpdated($action, $data, $slugs));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[InventoryMovement] broadcastMovement failed: ' . $e->getMessage());
        }
    }

    private function resolveUserEnterpriseId(Request $request): ?int
    {
        $user = Auth::user();
        $headerEnterprise = $this->getEnterprise($request);

        if ($user && $headerEnterprise) {
            $hasAccess = DB::table('user_enterprise_access')
                ->where('user_id', $user->id)
                ->where('enterprise_id', $headerEnterprise->id)
                ->where('is_active', true)
                ->exists();

            if ($hasAccess) {
                return (int) $headerEnterprise->id;
            }
        }

        return $user?->employee?->enterprise_id;
    }

    /**
     * Devuelve lista de productos con stock insuficiente para una entidad origen.
     *
     * @param int $entityId
     * @param array<int, array<string, mixed>> $details
     * @return array<int, string>
     */
    private function findInsufficientStockItems(int $entityId, array $details): array
    {
        if ($entityId <= 0 || empty($details)) {
            return [];
        }

        $productIds = collect($details)
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $productNames = Product::whereIn('id', $productIds)
            ->pluck('name', 'id');

        $insufficient = [];

        foreach ($details as $detail) {
            $productId = (int) ($detail['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $baseQuantity = isset($detail['base_quantity'])
                ? (float) $detail['base_quantity']
                : 0.0;

            if ($baseQuantity <= 0) {
                $quantity = (float) ($detail['quantity'] ?? 0);
                $conversionFactor = (float) ($detail['conversion_factor'] ?? 1);
                $baseQuantity = $quantity * ($conversionFactor > 0 ? $conversionFactor : 1);
            }

            if ($baseQuantity <= 0) {
                continue;
            }

            $stock = InventoryStock::where('product_id', $productId)
                ->where('entity_id', $entityId)
                ->when(!empty($detail['lot_number']), fn ($q) => $q->where('lot_number', $detail['lot_number']))
                ->sum('quantity');

            if ((float) $stock < $baseQuantity) {
                $productName = $productNames->get($productId) ?? "ID:{$productId}";
                $insufficient[] = "{$productName} (disponible: {$stock}, requerido: {$baseQuantity})";
            }
        }

        return $insufficient;
    }

    /**
     * Cancel an approved movement (reverse stock).
     */
    public function cancel(InventoryMovement $movement, Request $request): JsonResponse
    {
        if (! $this->movimientoVisible($request, $movement)) {
            return $this->noVisible('No tienes acceso al almacén de este movimiento', 403);
        }

        if (!in_array($movement->status, ['pending', 'approved'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden cancelar movimientos pendientes o aprobados'
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();

        try {
            // Si es una transferencia pendiente con stock ya descontado, revertir el descuento
            if ($movement->status === 'pending') {
                $movementType = $movement->movementType;
                if (
                    $movementType?->direction === 'transfer' &&
                    data_get($movement->metadata, 'stock_deducted_at_creation', false)
                ) {
                    $movement->loadMissing('details.product:id,name');
                    foreach ($movement->details as $detail) {
                        $this->increaseStock(
                            $detail,
                            $movement->source_entity_id,
                            $movement->source_entity_type,
                            $movement,
                            true // isReversal
                        );
                    }
                }
            }

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

            $freshMovement = $movement->fresh(['movementType', 'sourceEntity:id,name,code', 'destinationEntity:id,name,code', 'createdBy:id,name']);
            $this->broadcastMovement('cancelled', $freshMovement);

            return response()->json([
                'success' => true,
                'message' => 'Movimiento cancelado exitosamente',
                'data' => $freshMovement
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
