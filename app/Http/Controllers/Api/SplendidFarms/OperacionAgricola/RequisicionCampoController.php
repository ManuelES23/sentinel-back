<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\CosteoAgricola;
use App\Models\PurchaseOrder;
use App\Models\RequisicionCampo;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RequisicionCampoController extends Controller
{
    /**
     * Listar requisiciones filtradas por temporada.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
        ]);

        $query = RequisicionCampo::byTemporada($request->temporada_id)
            ->with([
                'solicitante:id,name',
                'aprobadoPor:id,name',
                'detalles.product:id,name,code',
                'detalles.unit:id,name,abbreviation',
                'detalles.etapa:id,nombre,codigo,lote_id',
                'detalles.etapa.lote:id,nombre',
                'visitaCampo:id,fecha_visita',
                'purchaseOrder:id,order_number,status',
            ])
            ->withCount('detalles');

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        if ($request->has('prioridad')) {
            $query->where('prioridad', $request->prioridad);
        }

        if ($request->has('fecha_desde')) {
            $query->where('fecha_solicitud', '>=', $request->fecha_desde);
        }

        if ($request->has('fecha_hasta')) {
            $query->where('fecha_solicitud', '<=', $request->fecha_hasta);
        }

        $requisiciones = $query->orderByDesc('fecha_solicitud')->get();

        return response()->json([
            'success' => true,
            'data' => $requisiciones,
        ]);
    }

    /**
     * Crear requisición de campo.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'visita_campo_id' => 'nullable|exists:visitas_campo,id',
            'fecha_solicitud' => 'required|date',
            'prioridad' => 'required|in:baja,media,alta,urgente',
            'justificacion' => 'nullable|string',
            'observaciones' => 'nullable|string',
            'detalles' => 'required|array|min:1',
            'detalles.*.product_id' => 'nullable|exists:products,id',
            'detalles.*.nombre_producto' => 'required|string|max:200',
            'detalles.*.cantidad' => 'required|numeric|min:0.01',
            'detalles.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'detalles.*.precio_estimado' => 'nullable|numeric|min:0',
            'detalles.*.etapa_id' => 'nullable|exists:etapas,id',
            'detalles.*.lote_id' => 'nullable|exists:lotes,id',
            'detalles.*.visita_campo_recomendacion_id' => 'nullable|integer',
            'detalles.*.observaciones' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $validated['numero_requisicion'] = RequisicionCampo::generateNumero();
            $validated['solicitante_user_id'] = Auth::id();
            $validated['status'] = RequisicionCampo::STATUS_BORRADOR;

            $requisicion = RequisicionCampo::create($validated);

            foreach ($validated['detalles'] as $detalle) {
                $requisicion->detalles()->create($detalle);
            }

            $requisicion->load([
                'solicitante:id,name',
                'detalles.product:id,name,code',
                'detalles.unit:id,name,abbreviation',
                'detalles.etapa:id,nombre,codigo,lote_id',
                'detalles.etapa.lote:id,nombre',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Requisición creada exitosamente',
                'data' => $requisicion,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la requisición: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mostrar requisición con detalles completos.
     */
    public function show(RequisicionCampo $requisicion): JsonResponse
    {
        $requisicion->load([
            'solicitante:id,name',
            'aprobadoPor:id,name',
            'temporada:id,nombre',
            'visitaCampo:id,fecha_visita,observaciones_generales',
            'purchaseOrder:id,order_number,status,total_amount',
            'detalles.product:id,name,code,cost_price',
            'detalles.unit:id,name,abbreviation',
            'detalles.etapa:id,nombre,codigo,lote_id,superficie',
            'detalles.etapa.lote:id,nombre',
            'detalles.lote:id,nombre',
        ]);

        return response()->json([
            'success' => true,
            'data' => $requisicion,
        ]);
    }

    /**
     * Actualizar requisición (solo en borrador/rechazada).
     */
    public function update(Request $request, RequisicionCampo $requisicion): JsonResponse
    {
        if (!$requisicion->is_editable) {
            return response()->json([
                'success' => false,
                'message' => 'La requisición no puede ser modificada en su estado actual',
            ], 422);
        }

        $validated = $request->validate([
            'fecha_solicitud' => 'sometimes|required|date',
            'prioridad' => 'sometimes|required|in:baja,media,alta,urgente',
            'justificacion' => 'nullable|string',
            'observaciones' => 'nullable|string',
            'detalles' => 'sometimes|required|array|min:1',
            'detalles.*.product_id' => 'nullable|exists:products,id',
            'detalles.*.nombre_producto' => 'required|string|max:200',
            'detalles.*.cantidad' => 'required|numeric|min:0.01',
            'detalles.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'detalles.*.precio_estimado' => 'nullable|numeric|min:0',
            'detalles.*.etapa_id' => 'nullable|exists:etapas,id',
            'detalles.*.lote_id' => 'nullable|exists:lotes,id',
            'detalles.*.visita_campo_recomendacion_id' => 'nullable|integer',
            'detalles.*.observaciones' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Si estaba rechazada, volver a borrador
            if ($requisicion->status === RequisicionCampo::STATUS_RECHAZADA) {
                $validated['status'] = RequisicionCampo::STATUS_BORRADOR;
                $validated['notas_rechazo'] = null;
            }

            $requisicion->update($validated);

            // Reemplazar detalles si se enviaron
            if (isset($validated['detalles'])) {
                $requisicion->detalles()->delete();
                foreach ($validated['detalles'] as $detalle) {
                    $requisicion->detalles()->create($detalle);
                }
            }

            $requisicion->load([
                'solicitante:id,name',
                'detalles.product:id,name,code',
                'detalles.unit:id,name,abbreviation',
                'detalles.etapa:id,nombre,codigo,lote_id',
                'detalles.etapa.lote:id,nombre',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Requisición actualizada exitosamente',
                'data' => $requisicion,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar requisición (solo borrador).
     */
    public function destroy(RequisicionCampo $requisicion): JsonResponse
    {
        if ($requisicion->status !== RequisicionCampo::STATUS_BORRADOR) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden eliminar requisiciones en borrador',
            ], 422);
        }

        $requisicion->detalles()->delete();
        $requisicion->delete();

        return response()->json([
            'success' => true,
            'message' => 'Requisición eliminada',
        ]);
    }

    // ═══════════════════════════════════════
    // ACCIONES DE WORKFLOW
    // ═══════════════════════════════════════

    /**
     * Enviar a aprobación.
     */
    public function submit(RequisicionCampo $requisicion): JsonResponse
    {
        if ($requisicion->status !== RequisicionCampo::STATUS_BORRADOR) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden enviar requisiciones en borrador',
            ], 422);
        }

        if ($requisicion->detalles()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'La requisición debe tener al menos un producto',
            ], 422);
        }

        $requisicion->update(['status' => RequisicionCampo::STATUS_PENDIENTE]);

        return response()->json([
            'success' => true,
            'message' => 'Requisición enviada a aprobación',
            'data' => $requisicion,
        ]);
    }

    /**
     * Aprobar requisición.
     */
    public function approve(RequisicionCampo $requisicion): JsonResponse
    {
        if ($requisicion->status !== RequisicionCampo::STATUS_PENDIENTE) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden aprobar requisiciones pendientes',
            ], 422);
        }

        $requisicion->update([
            'status' => RequisicionCampo::STATUS_APROBADA,
            'aprobado_por_user_id' => Auth::id(),
            'fecha_aprobacion' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Requisición aprobada',
            'data' => $requisicion->fresh(['solicitante:id,name', 'aprobadoPor:id,name']),
        ]);
    }

    /**
     * Rechazar requisición.
     */
    public function reject(Request $request, RequisicionCampo $requisicion): JsonResponse
    {
        if ($requisicion->status !== RequisicionCampo::STATUS_PENDIENTE) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden rechazar requisiciones pendientes',
            ], 422);
        }

        $validated = $request->validate([
            'notas_rechazo' => 'required|string|max:500',
        ]);

        $requisicion->update([
            'status' => RequisicionCampo::STATUS_RECHAZADA,
            'notas_rechazo' => $validated['notas_rechazo'],
            'aprobado_por_user_id' => Auth::id(),
            'fecha_aprobacion' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Requisición rechazada',
            'data' => $requisicion,
        ]);
    }

    /**
     * Cancelar requisición.
     */
    public function cancel(RequisicionCampo $requisicion): JsonResponse
    {
        if (in_array($requisicion->status, [
            RequisicionCampo::STATUS_COMPLETADA,
            RequisicionCampo::STATUS_CANCELADA,
        ])) {
            return response()->json([
                'success' => false,
                'message' => 'La requisición no puede ser cancelada en su estado actual',
            ], 422);
        }

        $requisicion->update(['status' => RequisicionCampo::STATUS_CANCELADA]);

        return response()->json([
            'success' => true,
            'message' => 'Requisición cancelada',
            'data' => $requisicion,
        ]);
    }

    /**
     * Generar Orden de Compra a partir de la requisición aprobada.
     */
    public function generarOrden(Request $request, RequisicionCampo $requisicion): JsonResponse
    {
        if ($requisicion->status !== RequisicionCampo::STATUS_APROBADA) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden generar OC de requisiciones aprobadas',
            ], 422);
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'notes' => 'nullable|string',
            'detalles_override' => 'nullable|array',
            'detalles_override.*.product_id' => 'required|exists:products,id',
            'detalles_override.*.quantity_ordered' => 'required|numeric|min:0.01',
            'detalles_override.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'detalles_override.*.unit_price' => 'required|numeric|min:0',
            'detalles_override.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        DB::beginTransaction();
        try {
            $supplier = Supplier::findOrFail($validated['supplier_id']);

            // Crear Orden de Compra
            $order = PurchaseOrder::create([
                'order_number' => PurchaseOrder::generateOrderNumber(),
                'supplier_id' => $supplier->id,
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'currency_code' => 'MXN',
                'payment_terms' => $supplier->has_credit ? ($supplier->payment_terms ?? 0) : 0,
                'notes' => ($validated['notes'] ?? '') . "\nGenerada desde Requisición: {$requisicion->numero_requisicion}",
                'requested_by' => $requisicion->solicitante->name ?? '',
                'created_by' => Auth::id(),
                'metadata' => [
                    'requisicion_campo_id' => $requisicion->id,
                    'temporada_id' => $requisicion->temporada_id,
                ],
            ]);

            // Usar detalles override o los de la requisición
            $detalles = $validated['detalles_override'] ?? null;

            if ($detalles) {
                foreach ($detalles as $index => $det) {
                    $order->details()->create([
                        'product_id' => $det['product_id'],
                        'quantity_ordered' => $det['quantity_ordered'],
                        'unit_id' => $det['unit_id'] ?? null,
                        'unit_price' => $det['unit_price'],
                        'tax_rate' => $det['tax_rate'] ?? 0,
                        'line_number' => $index + 1,
                    ]);
                }
            } else {
                // Usar detalles de requisición que tengan product_id
                $requisicionDetalles = $requisicion->detalles()
                    ->whereNotNull('product_id')
                    ->with('product')
                    ->get();

                foreach ($requisicionDetalles as $index => $det) {
                    $order->details()->create([
                        'product_id' => $det->product_id,
                        'quantity_ordered' => $det->cantidad,
                        'unit_id' => $det->unit_id,
                        'unit_price' => $det->precio_estimado ?? $det->product->cost_price ?? 0,
                        'line_number' => $index + 1,
                    ]);
                }
            }

            $order->recalculateTotals();

            // Actualizar requisición
            $requisicion->update([
                'status' => RequisicionCampo::STATUS_ORDEN_GENERADA,
                'purchase_order_id' => $order->id,
            ]);

            // Crear registros de costeo vinculados
            foreach ($requisicion->detalles as $det) {
                if ($det->subtotal_estimado > 0) {
                    CosteoAgricola::create([
                        'temporada_id' => $requisicion->temporada_id,
                        'lote_id' => $det->lote_id,
                        'etapa_id' => $det->etapa_id,
                        'tipo_fuente' => CosteoAgricola::TIPO_FUENTE_REQUISICION,
                        'fuente_id' => $requisicion->id,
                        'product_id' => $det->product_id,
                        'descripcion' => $det->nombre_producto,
                        'categoria' => $det->product?->category?->name ?? 'otro',
                        'cantidad' => $det->cantidad,
                        'unit_id' => $det->unit_id,
                        'costo_unitario' => $det->precio_estimado,
                        'costo_total' => $det->subtotal_estimado,
                        'fecha' => $requisicion->fecha_solicitud,
                        'user_id' => Auth::id(),
                        'notas' => "Requisición {$requisicion->numero_requisicion} → OC {$order->order_number}",
                    ]);
                }
            }

            $order->load(['supplier', 'details.product', 'details.unit']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Orden de compra {$order->order_number} generada exitosamente",
                'data' => [
                    'requisicion' => $requisicion->fresh(),
                    'purchase_order' => $order,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al generar orden de compra: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Listar proveedores para el select de generar OC.
     */
    public function suppliers(): JsonResponse
    {
        $suppliers = Supplier::where('is_active', true)
            ->select('id', 'business_name', 'trade_name', 'rfc', 'has_credit', 'payment_terms')
            ->orderBy('business_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $suppliers,
        ]);
    }
}
