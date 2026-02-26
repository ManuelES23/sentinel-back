<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controller de Órdenes de Compra
 * Ubicación: inventario/compras/ordenes-compra
 */
class PurchaseOrderController extends Controller
{
    /**
     * Listar órdenes de compra
     */
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrder::with(['supplier', 'details.product', 'createdByUser', 'approvedByUser']);

        // Filtros
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->where('business_name', 'like', "%{$search}%")
                            ->orWhere('trade_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->has('from_date')) {
            $query->where('order_date', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('order_date', '<=', $request->to_date);
        }

        // Ordenamiento
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDir = $request->get('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        // Paginación
        $perPage = $request->get('per_page', 15);
        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Crear orden de compra
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'expiry_date' => 'nullable|date|after_or_equal:order_date',
            'currency_code' => 'nullable|string|max:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'payment_conditions' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_address' => 'nullable|string',
            'shipping_method' => 'nullable|string|max:100',
            'delivery_instructions' => 'nullable|string',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            // Campos de autorización
            'requested_by' => 'nullable|string|max:255',
            'department_head' => 'nullable|string|max:255',
            'authorized_by_name' => 'nullable|string|max:255',
            // Detalles
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:products,id',
            'details.*.quantity_ordered' => 'required|numeric|min:0.0001',
            'details.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'details.*.unit_price' => 'required|numeric|min:0',
            'details.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'details.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'details.*.expected_date' => 'nullable|date',
            'details.*.notes' => 'nullable|string',
        ]);

        // Obtener datos del proveedor
        $supplier = Supplier::find($validated['supplier_id']);

        // Valores por defecto - días de crédito siempre del proveedor
        $validated['currency_code'] = $validated['currency_code'] ?? 'MXN';
        $validated['payment_terms'] = $supplier->has_credit ? ($supplier->payment_terms ?? 0) : 0;

        DB::beginTransaction();
        try {
            // Generar número de orden
            $validated['order_number'] = PurchaseOrder::generateOrderNumber();
            $validated['status'] = PurchaseOrder::STATUS_DRAFT;
            $validated['created_by'] = auth('sanctum')->id();

            // Crear orden
            $order = PurchaseOrder::create($validated);

            // Crear detalles
            foreach ($validated['details'] as $index => $detailData) {
                $detailData['line_number'] = $index + 1;
                $order->details()->create($detailData);
            }

            // Recalcular totales (se hace automáticamente en el modelo pero por seguridad)
            $order->recalculateTotals();
            $order->load(['supplier', 'details.product', 'details.unit', 'createdByUser']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Orden de compra creada exitosamente',
                'data' => $order,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la orden de compra: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mostrar orden de compra
     */
    public function show(PurchaseOrder $order): JsonResponse
    {
        $order->load([
            'supplier',
            'details.product',
            'details.unit',
            'receipts',
            'createdByUser',
            'approvedByUser',
        ]);

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    /**
     * Actualizar orden de compra
     */
    public function update(Request $request, PurchaseOrder $order): JsonResponse
    {
        if (! $order->is_editable) {
            return response()->json([
                'success' => false,
                'message' => 'La orden de compra no puede ser modificada en su estado actual',
            ], 422);
        }

        $validated = $request->validate([
            'supplier_id' => 'sometimes|required|exists:suppliers,id',
            'order_date' => 'sometimes|required|date',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'expiry_date' => 'nullable|date|after_or_equal:order_date',
            'currency_code' => 'nullable|string|max:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'payment_conditions' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_address' => 'nullable|string',
            'shipping_method' => 'nullable|string|max:100',
            'delivery_instructions' => 'nullable|string',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            // Campos de autorización
            'requested_by' => 'nullable|string|max:255',
            'department_head' => 'nullable|string|max:255',
            'authorized_by_name' => 'nullable|string|max:255',
        ]);

        // Si cambió el proveedor, actualizar payment_terms
        if (isset($validated['supplier_id']) && $validated['supplier_id'] != $order->supplier_id) {
            $supplier = Supplier::find($validated['supplier_id']);
            $validated['payment_terms'] = $supplier->has_credit ? ($supplier->payment_terms ?? 0) : 0;
        }

        $order->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Orden de compra actualizada exitosamente',
            'data' => $order->fresh(['supplier', 'details.product', 'details.unit']),
        ]);
    }

    /**
     * Eliminar orden de compra
     */
    public function destroy(PurchaseOrder $order): JsonResponse
    {
        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden eliminar órdenes en estado borrador',
            ], 422);
        }

        $order->details()->delete();
        $order->delete();

        return response()->json([
            'success' => true,
            'message' => 'Orden de compra eliminada exitosamente',
        ]);
    }

    // ==================== GESTIÓN DE DETALLES ====================

    /**
     * Agregar detalle a la orden
     */
    public function addDetail(Request $request, PurchaseOrder $order): JsonResponse
    {
        if (! $order->is_editable) {
            return response()->json([
                'success' => false,
                'message' => 'La orden de compra no puede ser modificada',
            ], 422);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity_ordered' => 'required|numeric|min:0.0001',
            'unit_id' => 'nullable|exists:units_of_measure,id',
            'unit_price' => 'required|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'expected_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        // Asignar line_number
        $maxLineNumber = $order->details()->max('line_number') ?? 0;
        $validated['line_number'] = $maxLineNumber + 1;

        $detail = $order->details()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Producto agregado exitosamente',
            'data' => $detail->load('product', 'unit'),
        ], 201);
    }

    /**
     * Actualizar detalle
     */
    public function updateDetail(Request $request, PurchaseOrder $order, PurchaseOrderDetail $detail): JsonResponse
    {
        if (! $order->is_editable) {
            return response()->json([
                'success' => false,
                'message' => 'La orden de compra no puede ser modificada',
            ], 422);
        }

        if ($detail->purchase_order_id !== $order->id) {
            return response()->json([
                'success' => false,
                'message' => 'El detalle no pertenece a esta orden',
            ], 404);
        }

        $validated = $request->validate([
            'product_id' => 'sometimes|required|exists:products,id',
            'quantity_ordered' => 'sometimes|required|numeric|min:0.0001',
            'unit_id' => 'nullable|exists:units_of_measure,id',
            'unit_price' => 'sometimes|required|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'expected_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $detail->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Detalle actualizado exitosamente',
            'data' => $detail->fresh(['product', 'unit']),
        ]);
    }

    /**
     * Eliminar detalle
     */
    public function deleteDetail(PurchaseOrder $order, PurchaseOrderDetail $detail): JsonResponse
    {
        if (! $order->is_editable) {
            return response()->json([
                'success' => false,
                'message' => 'La orden de compra no puede ser modificada',
            ], 422);
        }

        if ($detail->purchase_order_id !== $order->id) {
            return response()->json([
                'success' => false,
                'message' => 'El detalle no pertenece a esta orden',
            ], 404);
        }

        $detail->delete();

        return response()->json([
            'success' => true,
            'message' => 'Detalle eliminado exitosamente',
        ]);
    }

    // ==================== ACCIONES DE WORKFLOW ====================

    /**
     * Enviar a aprobación
     */
    public function submit(PurchaseOrder $order): JsonResponse
    {
        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden enviar órdenes en estado borrador',
            ], 422);
        }

        if ($order->details()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'La orden debe tener al menos un producto',
            ], 422);
        }

        $order->status = PurchaseOrder::STATUS_PENDING;
        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Orden enviada a aprobación',
            'data' => $order,
        ]);
    }

    /**
     * Aprobar orden
     */
    public function approve(PurchaseOrder $order): JsonResponse
    {
        if (! $order->approve(auth('sanctum')->id())) {
            return response()->json([
                'success' => false,
                'message' => 'La orden no puede ser aprobada en su estado actual',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Orden aprobada exitosamente',
            'data' => $order,
        ]);
    }

    /**
     * Rechazar orden
     */
    public function reject(Request $request, PurchaseOrder $order): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        if ($order->status !== PurchaseOrder::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden rechazar órdenes pendientes de aprobación',
            ], 422);
        }

        $order->status = PurchaseOrder::STATUS_DRAFT;
        $order->internal_notes = ($order->internal_notes ? $order->internal_notes."\n\n" : '')
            .'RECHAZADA: '.$validated['reason'].' (Por: '.auth('sanctum')->user()->name.' - '.now().')';
        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Orden rechazada',
            'data' => $order,
        ]);
    }

    /**
     * Marcar como enviada al proveedor
     */
    public function send(PurchaseOrder $order): JsonResponse
    {
        if (! $order->markAsSent(auth('sanctum')->id())) {
            return response()->json([
                'success' => false,
                'message' => 'La orden no puede ser enviada en su estado actual',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Orden marcada como enviada al proveedor',
            'data' => $order,
        ]);
    }

    /**
     * Confirmar orden (proveedor confirmó)
     */
    public function confirm(PurchaseOrder $order): JsonResponse
    {
        if ($order->status !== PurchaseOrder::STATUS_SENT) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden confirmar órdenes enviadas',
            ], 422);
        }

        $order->status = PurchaseOrder::STATUS_CONFIRMED;
        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Orden confirmada',
            'data' => $order,
        ]);
    }

    /**
     * Cancelar orden
     */
    public function cancel(Request $request, PurchaseOrder $order): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        if (! $order->cancel(auth('sanctum')->id(), $validated['reason'])) {
            return response()->json([
                'success' => false,
                'message' => 'La orden no puede ser cancelada en su estado actual',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Orden cancelada exitosamente',
            'data' => $order,
        ]);
    }

    // ==================== UTILIDADES ====================

    /**
     * Duplicar orden
     */
    public function duplicate(PurchaseOrder $order): JsonResponse
    {
        DB::beginTransaction();
        try {
            $newOrder = $order->replicate([
                'order_number', 'status', 'approved_by', 'approved_at',
                'cancelled_by', 'cancelled_at', 'cancellation_reason',
            ]);
            $newOrder->order_number = PurchaseOrder::generateOrderNumber();
            $newOrder->status = PurchaseOrder::STATUS_DRAFT;
            $newOrder->order_date = now();
            $newOrder->created_by = auth('sanctum')->id();
            $newOrder->save();

            foreach ($order->details as $detail) {
                $newDetail = $detail->replicate(['quantity_received']);
                $newDetail->purchase_order_id = $newOrder->id;
                $newDetail->quantity_received = 0;
                $newDetail->save();
            }

            $newOrder->recalculateTotals();
            $newOrder->load(['supplier', 'details.product']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Orden duplicada exitosamente',
                'data' => $newOrder,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al duplicar la orden: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtener productos pendientes de recepción
     */
    public function pendingItems(PurchaseOrder $order): JsonResponse
    {
        $pendingItems = $order->details()
            ->with(['product', 'unit'])
            ->whereRaw('quantity > quantity_received')
            ->get()
            ->map(function ($detail) {
                return [
                    'id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'product' => $detail->product,
                    'unit' => $detail->unit,
                    'quantity_ordered' => $detail->quantity,
                    'quantity_received' => $detail->quantity_received,
                    'quantity_pending' => $detail->quantity_pending,
                    'unit_price' => $detail->unit_price,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $pendingItems,
        ]);
    }
}
