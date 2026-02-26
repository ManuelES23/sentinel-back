<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controller de Recepciones de Mercancía
 * Ubicación: inventario/compras/recepciones
 */
class PurchaseReceiptController extends Controller
{
    /**
     * Listar recepciones
     */
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseReceipt::with(['supplier', 'purchaseOrder', 'receivedByUser']);

        // Filtros
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                  ->orWhere('supplier_document', 'like', "%{$search}%")
                  ->orWhereHas('supplier', function ($sq) use ($search) {
                      $sq->where('business_name', 'like', "%{$search}%")
                        ->orWhere('trade_name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('purchaseOrder', function ($sq) use ($search) {
                      $sq->where('order_number', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->has('purchase_order_id')) {
            $query->where('purchase_order_id', $request->purchase_order_id);
        }

        if ($request->has('from_date')) {
            $query->where('receipt_date', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('receipt_date', '<=', $request->to_date);
        }

        // Ordenamiento
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDir = $request->get('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        // Paginación
        $perPage = $request->get('per_page', 15);
        $receipts = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $receipts
        ]);
    }

    /**
     * Crear recepción
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'supplier_id' => 'required|exists:suppliers,id',
            'receipt_date' => 'required|date',
            'supplier_document' => 'nullable|string|max:100',
            'supplier_document_date' => 'nullable|date',
            'warehouse_id' => 'nullable|integer',
            'warehouse_type' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'quality_notes' => 'nullable|string',
            // Detalles
            'details' => 'required|array|min:1',
            'details.*.purchase_order_detail_id' => 'nullable|exists:purchase_order_details,id',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity_ordered' => 'nullable|numeric|min:0',
            'details.*.quantity_received' => 'required|numeric|min:0.0001',
            'details.*.quantity_accepted' => 'nullable|numeric|min:0',
            'details.*.quantity_rejected' => 'nullable|numeric|min:0',
            'details.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'details.*.unit_cost' => 'required|numeric|min:0',
            'details.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'details.*.lot_number' => 'nullable|string|max:100',
            'details.*.expiry_date' => 'nullable|date',
            'details.*.serial_number' => 'nullable|string|max:100',
            'details.*.quality_status' => 'nullable|in:pending,approved,rejected,partial',
            'details.*.quality_notes' => 'nullable|string',
            'details.*.notes' => 'nullable|string',
        ]);

        // Validar que la orden de compra esté en estado válido para recepción
        if (!empty($validated['purchase_order_id'])) {
            $order = PurchaseOrder::find($validated['purchase_order_id']);
            if (!in_array($order->status, [
                PurchaseOrder::STATUS_SENT,
                PurchaseOrder::STATUS_CONFIRMED,
                PurchaseOrder::STATUS_PARTIAL
            ])) {
                return response()->json([
                    'success' => false,
                    'message' => 'La orden de compra no está en estado válido para recepción'
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            // Generar número de recepción
            $validated['receipt_number'] = PurchaseReceipt::generateReceiptNumber();
            $validated['status'] = PurchaseReceipt::STATUS_DRAFT;
            $validated['received_by'] = auth('sanctum')->id();

            // Crear recepción
            $receipt = PurchaseReceipt::create($validated);

            // Crear detalles
            foreach ($validated['details'] as $detailData) {
                $receipt->details()->create($detailData);
            }

            // Recalcular totales
            $receipt->recalculateTotals();
            $receipt->load(['supplier', 'purchaseOrder', 'details.product', 'details.unit']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Recepción creada exitosamente',
                'data' => $receipt
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la recepción: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar recepción
     */
    public function show(PurchaseReceipt $receipt): JsonResponse
    {
        $receipt->load([
            'supplier',
            'purchaseOrder',
            'details.product',
            'details.unit',
            'details.purchaseOrderDetail',
            'inventoryMovement',
            'accountPayable',
            'receivedByUser',
            'validatedByUser'
        ]);

        return response()->json([
            'success' => true,
            'data' => $receipt
        ]);
    }

    /**
     * Actualizar recepción
     */
    public function update(Request $request, PurchaseReceipt $receipt): JsonResponse
    {
        if (!$receipt->is_editable) {
            return response()->json([
                'success' => false,
                'message' => 'La recepción no puede ser modificada en su estado actual'
            ], 422);
        }

        $validated = $request->validate([
            'receipt_date' => 'sometimes|required|date',
            'supplier_document' => 'nullable|string|max:100',
            'supplier_document_date' => 'nullable|date',
            'warehouse_id' => 'nullable|integer',
            'warehouse_type' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'quality_notes' => 'nullable|string',
        ]);

        $receipt->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Recepción actualizada exitosamente',
            'data' => $receipt->fresh(['supplier', 'purchaseOrder', 'details.product'])
        ]);
    }

    /**
     * Eliminar recepción
     */
    public function destroy(PurchaseReceipt $receipt): JsonResponse
    {
        if ($receipt->status !== PurchaseReceipt::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden eliminar recepciones en estado borrador'
            ], 422);
        }

        $receipt->details()->delete();
        $receipt->delete();

        return response()->json([
            'success' => true,
            'message' => 'Recepción eliminada exitosamente'
        ]);
    }

    // ==================== GESTIÓN DE DETALLES ====================

    /**
     * Agregar detalle a la recepción
     */
    public function addDetail(Request $request, PurchaseReceipt $receipt): JsonResponse
    {
        if (!$receipt->is_editable) {
            return response()->json([
                'success' => false,
                'message' => 'La recepción no puede ser modificada'
            ], 422);
        }

        $validated = $request->validate([
            'purchase_order_detail_id' => 'nullable|exists:purchase_order_details,id',
            'product_id' => 'required|exists:products,id',
            'quantity_ordered' => 'nullable|numeric|min:0',
            'quantity_received' => 'required|numeric|min:0.0001',
            'quantity_accepted' => 'nullable|numeric|min:0',
            'quantity_rejected' => 'nullable|numeric|min:0',
            'unit_id' => 'nullable|exists:units_of_measure,id',
            'unit_cost' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'lot_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'serial_number' => 'nullable|string|max:100',
            'quality_status' => 'nullable|in:pending,approved,rejected,partial',
            'quality_notes' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $detail = $receipt->details()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Producto agregado exitosamente',
            'data' => $detail->load('product', 'unit')
        ], 201);
    }

    /**
     * Actualizar detalle
     */
    public function updateDetail(Request $request, PurchaseReceipt $receipt, $detailId): JsonResponse
    {
        if (!$receipt->is_editable) {
            return response()->json([
                'success' => false,
                'message' => 'La recepción no puede ser modificada'
            ], 422);
        }

        $detail = $receipt->details()->find($detailId);
        if (!$detail) {
            return response()->json([
                'success' => false,
                'message' => 'El detalle no existe'
            ], 404);
        }

        $validated = $request->validate([
            'quantity_received' => 'sometimes|required|numeric|min:0.0001',
            'quantity_accepted' => 'nullable|numeric|min:0',
            'quantity_rejected' => 'nullable|numeric|min:0',
            'unit_cost' => 'sometimes|required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'lot_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'serial_number' => 'nullable|string|max:100',
            'quality_status' => 'nullable|in:pending,approved,rejected,partial',
            'quality_notes' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $detail->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Detalle actualizado exitosamente',
            'data' => $detail->fresh(['product', 'unit'])
        ]);
    }

    /**
     * Eliminar detalle
     */
    public function deleteDetail(PurchaseReceipt $receipt, $detailId): JsonResponse
    {
        if (!$receipt->is_editable) {
            return response()->json([
                'success' => false,
                'message' => 'La recepción no puede ser modificada'
            ], 422);
        }

        $detail = $receipt->details()->find($detailId);
        if (!$detail) {
            return response()->json([
                'success' => false,
                'message' => 'El detalle no existe'
            ], 404);
        }

        $detail->delete();

        return response()->json([
            'success' => true,
            'message' => 'Detalle eliminado exitosamente'
        ]);
    }

    // ==================== ACCIONES DE WORKFLOW ====================

    /**
     * Enviar a validación
     */
    public function submit(PurchaseReceipt $receipt): JsonResponse
    {
        if ($receipt->status !== PurchaseReceipt::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden enviar recepciones en estado borrador'
            ], 422);
        }

        if ($receipt->details()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'La recepción debe tener al menos un producto'
            ], 422);
        }

        $receipt->status = PurchaseReceipt::STATUS_PENDING;
        $receipt->save();

        return response()->json([
            'success' => true,
            'message' => 'Recepción enviada a validación',
            'data' => $receipt
        ]);
    }

    /**
     * Completar recepción (genera inventario y cuenta por pagar)
     */
    public function complete(PurchaseReceipt $receipt): JsonResponse
    {
        if ($receipt->status !== PurchaseReceipt::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden completar recepciones en estado pendiente'
            ], 422);
        }

        try {
            if (!$receipt->complete(auth('sanctum')->id())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al completar la recepción'
                ], 500);
            }

            $receipt->load([
                'inventoryMovement',
                'accountPayable',
                'purchaseOrder'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Recepción completada exitosamente. Se generó movimiento de inventario y cuenta por pagar.',
                'data' => $receipt
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al completar la recepción: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancelar recepción
     */
    public function cancel(Request $request, PurchaseReceipt $receipt): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        if (!$receipt->cancel(auth('sanctum')->id(), $validated['reason'])) {
            return response()->json([
                'success' => false,
                'message' => 'La recepción no puede ser cancelada en su estado actual'
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Recepción cancelada exitosamente',
            'data' => $receipt
        ]);
    }

    // ==================== UTILIDADES ====================

    /**
     * Crear recepción desde orden de compra
     */
    public function fromPurchaseOrder(PurchaseOrder $order): JsonResponse
    {
        if (!in_array($order->status, [
            PurchaseOrder::STATUS_SENT,
            PurchaseOrder::STATUS_CONFIRMED,
            PurchaseOrder::STATUS_PARTIAL
        ])) {
            return response()->json([
                'success' => false,
                'message' => 'La orden de compra no está en estado válido para recepción'
            ], 422);
        }

        // Obtener items pendientes
        $pendingItems = $order->details()
            ->with(['product', 'unit'])
            ->whereRaw('quantity > quantity_received')
            ->get();

        if ($pendingItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No hay productos pendientes de recepción en esta orden'
            ], 422);
        }

        // Preparar datos para la recepción
        $receiptData = [
            'receipt_number' => PurchaseReceipt::generateReceiptNumber(),
            'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'receipt_date' => now()->toDateString(),
            'status' => PurchaseReceipt::STATUS_DRAFT,
            'received_by' => auth('sanctum')->id(),
        ];

        DB::beginTransaction();
        try {
            $receipt = PurchaseReceipt::create($receiptData);

            foreach ($pendingItems as $item) {
                $receipt->details()->create([
                    'purchase_order_detail_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity_ordered' => $item->quantity,
                    'quantity_received' => $item->quantity_pending, // Por defecto, lo pendiente
                    'quantity_accepted' => $item->quantity_pending,
                    'unit_id' => $item->unit_id,
                    'unit_cost' => $item->unit_price,
                    'tax_rate' => $item->tax_rate,
                ]);
            }

            $receipt->recalculateTotals();
            $receipt->load(['supplier', 'purchaseOrder', 'details.product', 'details.unit']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Recepción creada desde la orden de compra',
                'data' => $receipt
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la recepción: ' . $e->getMessage()
            ], 500);
        }
    }
}
