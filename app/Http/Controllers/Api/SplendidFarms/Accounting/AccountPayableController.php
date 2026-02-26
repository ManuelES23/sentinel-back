<?php

namespace App\Http\Controllers\Api\SplendidFarms\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountPayable;
use App\Models\AccountPayablePayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controller de Cuentas por Pagar
 * Ubicación: contabilidad/cuentas-por-pagar
 */
class AccountPayableController extends Controller
{
    /**
     * Listar cuentas por pagar
     */
    public function index(Request $request): JsonResponse
    {
        $query = AccountPayable::with(['supplier', 'purchaseReceipt', 'purchaseOrder', 'createdByUser']);

        // Filtros
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('document_number', 'like', "%{$search}%")
                    ->orWhere('supplier_invoice', 'like', "%{$search}%")
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

        if ($request->has('document_type')) {
            $query->where('document_type', $request->document_type);
        }

        if ($request->has('overdue') && $request->boolean('overdue')) {
            $query->overdue();
        }

        if ($request->has('from_date')) {
            $query->where('document_date', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('document_date', '<=', $request->to_date);
        }

        if ($request->has('due_from')) {
            $query->where('due_date', '>=', $request->due_from);
        }

        if ($request->has('due_to')) {
            $query->where('due_date', '<=', $request->due_to);
        }

        // Ordenamiento
        $sortBy = $request->get('sort_by', 'due_date');
        $sortDir = $request->get('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        // Paginación
        $perPage = $request->get('per_page', 15);
        $documents = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $documents,
        ]);
    }

    /**
     * Crear cuenta por pagar (manual)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => 'required|in:invoice,credit_note,debit_note,other',
            'supplier_id' => 'required|exists:suppliers,id',
            'supplier_invoice' => 'nullable|string|max:100',
            'supplier_invoice_date' => 'nullable|date',
            'document_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:document_date',
            'payment_terms_days' => 'nullable|integer|min:0',
            'currency_code' => 'nullable|string|max:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'subtotal' => 'required|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'accounting_account' => 'nullable|string|max:20',
            'cost_center' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
        ]);

        // Calcular total y balance
        $validated['total_amount'] = $validated['subtotal'] + ($validated['tax_amount'] ?? 0);
        $validated['balance'] = $validated['total_amount'];
        $validated['amount_paid'] = 0;
        $validated['status'] = AccountPayable::STATUS_PENDING;
        $validated['document_number'] = AccountPayable::generateDocumentNumber();
        $validated['created_by'] = auth('sanctum')->id();

        $document = AccountPayable::create($validated);
        $document->load('supplier');

        return response()->json([
            'success' => true,
            'message' => 'Documento creado exitosamente',
            'data' => $document,
        ], 201);
    }

    /**
     * Mostrar cuenta por pagar
     */
    public function show(AccountPayable $accountPayable): JsonResponse
    {
        $accountPayable->load([
            'supplier',
            'purchaseReceipt',
            'purchaseOrder',
            'payments',
            'createdByUser',
        ]);

        return response()->json([
            'success' => true,
            'data' => $accountPayable,
        ]);
    }

    /**
     * Actualizar cuenta por pagar
     */
    public function update(Request $request, AccountPayable $accountPayable): JsonResponse
    {
        if ($accountPayable->status === AccountPayable::STATUS_PAID) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede modificar un documento ya pagado',
            ], 422);
        }

        $validated = $request->validate([
            'supplier_invoice' => 'nullable|string|max:100',
            'supplier_invoice_date' => 'nullable|date',
            'due_date' => 'sometimes|required|date',
            'accounting_account' => 'nullable|string|max:20',
            'cost_center' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
        ]);

        $accountPayable->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Documento actualizado exitosamente',
            'data' => $accountPayable->fresh('supplier'),
        ]);
    }

    /**
     * Cancelar cuenta por pagar
     */
    public function cancel(Request $request, AccountPayable $accountPayable): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        if (! $accountPayable->cancel(auth('sanctum')->id(), $validated['reason'])) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede cancelar un documento ya pagado',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Documento cancelado exitosamente',
            'data' => $accountPayable,
        ]);
    }

    // ==================== PAGOS ====================

    /**
     * Listar pagos de una cuenta
     */
    public function payments(AccountPayable $accountPayable): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $accountPayable->payments()->with('processedByUser')->get(),
        ]);
    }

    /**
     * Registrar pago
     */
    public function registerPayment(Request $request, AccountPayable $accountPayable): JsonResponse
    {
        if (! in_array($accountPayable->status, [AccountPayable::STATUS_PENDING, AccountPayable::STATUS_PARTIAL])) {
            return response()->json([
                'success' => false,
                'message' => 'El documento no acepta pagos en su estado actual',
            ], 422);
        }

        $validated = $request->validate([
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01|max:'.$accountPayable->balance,
            'currency_code' => 'nullable|string|max:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:cash,bank_transfer,check,credit_card,direct_debit,other',
            'bank_reference' => 'nullable|string|max:100',
            'check_number' => 'nullable|string|max:50',
            'bank_account' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $validated['account_payable_id'] = $accountPayable->id;
            $validated['payment_number'] = AccountPayablePayment::generatePaymentNumber();
            $validated['status'] = AccountPayablePayment::STATUS_PENDING;

            $payment = AccountPayablePayment::create($validated);

            // Procesar el pago inmediatamente (o dejarlo pendiente según lógica de negocio)
            $payment->process(auth('sanctum')->id());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pago registrado exitosamente',
                'data' => [
                    'payment' => $payment,
                    'account_payable' => $accountPayable->fresh(),
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar el pago: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancelar pago
     */
    public function cancelPayment(Request $request, AccountPayable $accountPayable, AccountPayablePayment $payment): JsonResponse
    {
        if ($payment->account_payable_id !== $accountPayable->id) {
            return response()->json([
                'success' => false,
                'message' => 'El pago no pertenece a este documento',
            ], 404);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        if (! $payment->cancel(auth('sanctum')->id(), $validated['reason'])) {
            return response()->json([
                'success' => false,
                'message' => 'El pago no puede ser cancelado',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pago cancelado exitosamente',
            'data' => [
                'payment' => $payment,
                'account_payable' => $accountPayable->fresh(),
            ],
        ]);
    }

    // ==================== REPORTES Y CONSULTAS ====================

    /**
     * Resumen de cuentas por pagar
     */
    public function summary(Request $request): JsonResponse
    {
        $pending = AccountPayable::pending()
            ->selectRaw('SUM(balance) as total, COUNT(*) as count')
            ->first();

        $overdue = AccountPayable::overdue()
            ->selectRaw('SUM(balance) as total, COUNT(*) as count')
            ->first();

        $dueSoon = AccountPayable::dueSoon(7)
            ->selectRaw('SUM(balance) as total, COUNT(*) as count')
            ->first();

        $thisMonth = AccountPayable::pending()
            ->whereMonth('due_date', now()->month)
            ->whereYear('due_date', now()->year)
            ->selectRaw('SUM(balance) as total, COUNT(*) as count')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'pending' => [
                    'total' => $pending->total ?? 0,
                    'count' => $pending->count ?? 0,
                ],
                'overdue' => [
                    'total' => $overdue->total ?? 0,
                    'count' => $overdue->count ?? 0,
                ],
                'due_soon_7_days' => [
                    'total' => $dueSoon->total ?? 0,
                    'count' => $dueSoon->count ?? 0,
                ],
                'due_this_month' => [
                    'total' => $thisMonth->total ?? 0,
                    'count' => $thisMonth->count ?? 0,
                ],
            ],
        ]);
    }

    /**
     * Antigüedad de saldos
     */
    public function aging(Request $request): JsonResponse
    {
        $supplierId = $request->get('supplier_id');

        $query = AccountPayable::pending();
        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        $documents = $query->get();

        $aging = [
            'current' => 0,       // No vencido
            '1_30' => 0,          // 1-30 días vencido
            '31_60' => 0,         // 31-60 días vencido
            '61_90' => 0,         // 61-90 días vencido
            'over_90' => 0,       // Más de 90 días
        ];

        foreach ($documents as $doc) {
            $daysOverdue = $doc->days_overdue;

            if ($daysOverdue <= 0) {
                $aging['current'] += $doc->balance;
            } elseif ($daysOverdue <= 30) {
                $aging['1_30'] += $doc->balance;
            } elseif ($daysOverdue <= 60) {
                $aging['31_60'] += $doc->balance;
            } elseif ($daysOverdue <= 90) {
                $aging['61_90'] += $doc->balance;
            } else {
                $aging['over_90'] += $doc->balance;
            }
        }

        $aging['total'] = array_sum($aging);

        return response()->json([
            'success' => true,
            'data' => $aging,
        ]);
    }

    /**
     * Saldo por proveedor
     */
    public function balanceBySupplier(Request $request): JsonResponse
    {
        $balances = AccountPayable::pending()
            ->with('supplier')
            ->selectRaw('supplier_id, SUM(balance) as total_balance, COUNT(*) as document_count')
            ->groupBy('supplier_id')
            ->orderByDesc('total_balance')
            ->get()
            ->map(function ($item) {
                return [
                    'supplier_id' => $item->supplier_id,
                    'supplier' => $item->supplier,
                    'total_balance' => $item->total_balance,
                    'document_count' => $item->document_count,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $balances,
        ]);
    }

    /**
     * Documentos vencidos
     */
    public function overdue(Request $request): JsonResponse
    {
        $query = AccountPayable::overdue()
            ->with(['supplier', 'purchaseReceipt', 'purchaseOrder']);

        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        $sortBy = $request->get('sort_by', 'due_date');
        $sortDir = $request->get('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = $request->get('per_page', 15);
        $documents = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $documents,
        ]);
    }

    /**
     * Próximos a vencer
     */
    public function dueSoon(Request $request): JsonResponse
    {
        $days = $request->get('days', 7);

        $query = AccountPayable::dueSoon($days)
            ->with(['supplier', 'purchaseReceipt', 'purchaseOrder']);

        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        $query->orderBy('due_date', 'asc');

        $perPage = $request->get('per_page', 15);
        $documents = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $documents,
        ]);
    }
}
