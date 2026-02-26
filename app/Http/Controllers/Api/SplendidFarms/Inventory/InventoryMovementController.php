<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
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
            'details.*.quantity' => 'required|numeric|min:0.0001',
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

        DB::beginTransaction();

        try {
            // Crear movimiento
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
                'metadata' => $validated['metadata'] ?? null,
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
            'details.*.quantity' => 'required|numeric|min:0.0001',
            'details.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'details.*.unit_cost' => 'nullable|numeric|min:0',
            'details.*.lot_number' => 'nullable|string|max:100',
            'details.*.serial_number' => 'nullable|string|max:100',
            'details.*.expiry_date' => 'nullable|date',
            'details.*.notes' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            // Actualizar movimiento
            $movement->update([
                'source_entity_id' => $validated['source_entity_id'] ?? $movement->source_entity_id,
                'source_entity_type' => $validated['source_entity_type'] ?? $movement->source_entity_type,
                'destination_entity_id' => $validated['destination_entity_id'] ?? $movement->destination_entity_id,
                'destination_entity_type' => $validated['destination_entity_type'] ?? $movement->destination_entity_type,
                'reference_number' => $validated['reference_number'] ?? $movement->reference_number,
                'movement_date' => $validated['movement_date'] ?? $movement->movement_date,
                'description' => $validated['description'] ?? $movement->description,
                'metadata' => $validated['metadata'] ?? $movement->metadata,
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
    public function approve(InventoryMovement $movement): JsonResponse
    {
        if ($movement->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden aprobar movimientos pendientes'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $movementType = $movement->movementType;

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
        // Firma: updateStock(productId, entityId, areaId, quantityChange, unitCost, lotNumber, expiryDate, movementId)
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

        // Registrar en kardex
        // Firma: recordEntry(productId, entityId, entityType, movementId, transactionType, quantity, unitCost, lotNumber, serialNumber, areaId)
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
            null // area_id
        );
    }

    /**
     * Decrease stock for a product.
     */
    private function decreaseStock($detail, $entityId, $entityType, $movement, $isReversal = false): void
    {
        $quantity = $detail->base_quantity ?? $detail->quantity;

        // Actualizar stock (cantidad negativa para decrementar)
        // Firma: updateStock(productId, entityId, areaId, quantityChange, unitCost, lotNumber, expiryDate, movementId)
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

        // Registrar en kardex
        // Firma: recordEntry(productId, entityId, entityType, movementId, transactionType, quantity, unitCost, lotNumber, serialNumber, areaId)
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
            null // area_id
        );
    }
}
