<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Models\MovementType;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptDetail;
use App\Services\Compras\AlcanceCompras;
use App\Services\Compras\AvisosCompras;
use App\Services\Compras\PermisosCompras;
use App\Services\Inventory\AlmacenAccessService;
use App\Services\Inventory\LoteCaducidadValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controller de Recepciones de Mercancía
 * Ubicación: inventario/compras/recepciones
 *
 * El encargado captura la recepción contra una orden de compra recibible;
 * las líneas siempre se validan contra lo pendiente de la OC (descontando lo
 * comprometido en otras recepciones abiertas) y contra lote/caducidad.
 */
class PurchaseReceiptController extends Controller
{
    public function __construct(
        private AlmacenAccessService $almacenes,
        private AlcanceCompras $alcance,
        private PermisosCompras $permisos,
        private AvisosCompras $avisos,
        private LoteCaducidadValidator $lotes,
    ) {
    }

    private const RELACIONES = [
        'supplier:id,code,business_name,trade_name',
        'purchaseOrder:id,order_number,status,almacen_destino_id',
        'almacen:id,code,name',
        'capturadaPor:id,name',
        'confirmadaPor:id,name',
        'details.product:id,code,name,track_lots,track_expiry',
        'details.unit:id,name,abbreviation',
    ];

    private function acceso(Request $request, PurchaseReceipt $receipt): Enterprise
    {
        $empresa = $this->almacenes->resolverEmpresa($request);
        abort_unless($this->alcance->puedeVer($request->user(), $empresa, $receipt, 'almacen_id'), 403, 'No tienes acceso a esta recepción');

        return $empresa;
    }

    private function tipoCompra(): MovementType
    {
        return MovementType::where('code', 'COMPRA')->first()
            ?? throw ValidationException::withMessages(['movimiento' => 'No existe el tipo de movimiento COMPRA.']);
    }

    private function reglas(bool $alta): array
    {
        return [
            'purchase_order_id' => ($alta ? 'required' : 'prohibited') . '|integer|exists:purchase_orders,id',
            'receipt_date' => ($alta ? 'required' : 'sometimes|required') . '|date',
            'almacen_id' => 'nullable|integer|exists:entities,id',
            'supplier_document' => 'nullable|string|max:100',
            'supplier_document_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'quality_notes' => 'nullable|string',
            'details' => ($alta ? 'required' : 'sometimes|required') . '|array|min:1',
            'details.*.purchase_order_detail_id' => 'required|integer',
            'details.*.quantity_received' => 'required|numeric|min:0.01',
            'details.*.quantity_accepted' => 'nullable|numeric|min:0',
            'details.*.quantity_rejected' => 'nullable|numeric|min:0',
            'details.*.lot_number' => 'nullable|string|max:100',
            'details.*.expiry_date' => 'nullable|date',
            'details.*.quality_status' => 'nullable|in:pending,approved,rejected,partial',
            'details.*.quality_notes' => 'nullable|string',
            'details.*.notes' => 'nullable|string',
        ];
    }

    /** Cuánto de un renglón de OC está comprometido en recepciones abiertas (draft/pending). */
    private function comprometido(int $purchaseOrderDetailId, ?PurchaseReceipt $excluir = null): float
    {
        return (float) PurchaseReceiptDetail::where('purchase_order_detail_id', $purchaseOrderDetailId)
            ->whereHas('purchaseReceipt', fn ($q) => $q->whereIn('status', [PurchaseReceipt::STATUS_DRAFT, PurchaseReceipt::STATUS_PENDING])
                ->when($excluir, fn ($w) => $w->where('id', '!=', $excluir->id)))
            ->sum('quantity_accepted');
    }

    /**
     * Arma las líneas desde la OC (producto, unidad, costo) validando que no
     * excedan lo pendiente: pedido − recibido − lo comprometido en otras
     * recepciones abiertas (draft/pending) − lo ya usado en este envío.
     */
    private function lineasDesdeOrden(PurchaseOrder $oc, array $details, ?PurchaseReceipt $excluir = null): array
    {
        $errores = [];
        $usado = [];
        $lineas = [];

        foreach (array_values($details) as $i => $d) {
            $renglon = $oc->details->firstWhere('id', (int) $d['purchase_order_detail_id']);
            if (! $renglon) {
                $errores["details.$i.purchase_order_detail_id"] = ['El renglón no pertenece a la orden de compra.'];
                continue;
            }

            $recibido = (float) $d['quantity_received'];
            // $rechazado se deriva de $aceptado (y no al revés) para que nunca
            // queden ambos en 0 con $recibido > 0: si eso pasa, el hook del
            // modelo (PurchaseReceiptDetail::boot) interpreta "no vino nada" y
            // rellena quantity_accepted con TODO quantity_received, sin pasar
            // por la validación de disponible de abajo.
            if (isset($d['quantity_accepted'])) {
                $aceptado = (float) $d['quantity_accepted'];
                $rechazado = isset($d['quantity_rejected']) ? (float) $d['quantity_rejected'] : max(0, $recibido - $aceptado);
            } else {
                $rechazado = (float) ($d['quantity_rejected'] ?? 0);
                $aceptado = max(0, $recibido - $rechazado);
            }

            $comprometido = $this->comprometido($renglon->id, $excluir);
            $disponible = (float) $renglon->quantity_ordered - (float) $renglon->quantity_received - $comprometido - ($usado[$renglon->id] ?? 0);

            if ($aceptado > $disponible + 0.0001) {
                $errores["details.$i.quantity_accepted"] = ['Excede lo pendiente por recibir (' . max(0, round($disponible, 4)) . ').'];
                continue;
            }
            $usado[$renglon->id] = ($usado[$renglon->id] ?? 0) + $aceptado;

            $lineas[] = [
                'purchase_order_detail_id' => $renglon->id,
                'product_id' => $renglon->product_id,
                'unit_id' => $renglon->unit_id,
                'quantity_ordered' => $renglon->quantity_ordered,
                'quantity_received' => $recibido,
                'quantity_accepted' => $aceptado,
                'quantity_rejected' => $rechazado,
                'unit_cost' => $renglon->unit_price,
                'tax_rate' => $renglon->tax_rate,
                'lot_number' => $d['lot_number'] ?? null,
                'expiry_date' => $d['expiry_date'] ?? null,
                'quality_status' => $d['quality_status'] ?? 'pending',
                'quality_notes' => $d['quality_notes'] ?? null,
                'notes' => $d['notes'] ?? null,
            ];
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        return $lineas;
    }

    private function validarLotes(int $almacenId, array $lineas): void
    {
        $errores = $this->lotes->validar($this->tipoCompra(), null, $almacenId, $lineas, false);
        if ($errores) {
            throw ValidationException::withMessages(array_map(fn ($m) => [$m], $errores));
        }
    }

    /** OC visible y recibible, y almacén que recibe (el de la OC, o el indicado si es antigua). */
    private function ordenRecibible(Request $request, Enterprise $empresa, int $ocId, ?int $almacenPedido): array
    {
        $oc = PurchaseOrder::with('details')->findOrFail($ocId);
        abort_unless($this->alcance->puedeVer($request->user(), $empresa, $oc, 'almacen_destino_id'), 403, 'No tienes acceso a esta orden de compra');
        if (! in_array($oc->status, PurchaseOrder::STATUSES_RECIBIBLES, true)) {
            throw ValidationException::withMessages(['purchase_order_id' => 'La orden de compra no está autorizada para recibirse.']);
        }

        $almacenId = $oc->almacen_destino_id ?? $almacenPedido;
        if (! $almacenId) {
            throw ValidationException::withMessages(['almacen_id' => 'Elige el almacén que recibe.']);
        }
        abort_unless($this->almacenes->puedeVer($request->user(), $empresa, (int) $almacenId), 403, 'No tienes acceso a ese almacén');

        return [$oc, (int) $almacenId];
    }

    public function index(Request $request): JsonResponse
    {
        $empresa = $this->almacenes->resolverEmpresa($request);
        $query = $this->alcance->aplicar(PurchaseReceipt::query(), 'almacen_id', $request->user(), $empresa)
            ->with(['supplier:id,code,business_name,trade_name', 'purchaseOrder:id,order_number,status', 'almacen:id,code,name', 'capturadaPor:id,name', 'confirmadaPor:id,name']);

        if ($request->input('bandeja') === 'por_confirmar') {
            abort_unless($this->permisos->puedeConfirmar($request->user(), $empresa), 403, 'No tienes permiso para confirmar entradas');
            $query->where('status', PurchaseReceipt::STATUS_PENDING);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                    ->orWhere('supplier_document', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('business_name', 'like', "%{$search}%")->orWhere('trade_name', 'like', "%{$search}%"))
                    ->orWhereHas('purchaseOrder', fn ($sq) => $sq->where('order_number', 'like', "%{$search}%"));
            });
        }
        foreach (['status', 'supplier_id', 'purchase_order_id', 'almacen_id'] as $filtro) {
            if ($request->filled($filtro)) {
                $query->where($filtro, $request->input($filtro));
            }
        }
        if ($request->filled('from_date')) {
            $query->where('receipt_date', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->where('receipt_date', '<=', $request->to_date);
        }

        $sortBy = in_array($request->get('sort_by'), ['created_at', 'receipt_date', 'receipt_number', 'total_amount', 'status'], true) ? $request->get('sort_by') : 'created_at';
        $query->orderBy($sortBy, $request->get('sort_dir') === 'asc' ? 'asc' : 'desc');

        return response()->json(['success' => true, 'data' => $query->paginate((int) $request->get('per_page', 15))]);
    }

    public function ordenesRecibibles(Request $request): JsonResponse
    {
        $empresa = $this->almacenes->resolverEmpresa($request);
        $ordenes = $this->alcance->aplicar(PurchaseOrder::query(), 'almacen_destino_id', $request->user(), $empresa)
            ->whereIn('status', PurchaseOrder::STATUSES_RECIBIBLES)
            ->with([
                'supplier:id,code,business_name,trade_name',
                'almacenDestino:id,code,name',
                'details.product:id,code,name,track_lots,track_expiry',
                'details.unit:id,name,abbreviation',
            ])
            ->orderByDesc('order_date')
            ->get();

        return response()->json(['success' => true, 'data' => $ordenes]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->reglas(true));
        $empresa = $this->almacenes->resolverEmpresa($request);
        [$oc, $almacenId] = $this->ordenRecibible($request, $empresa, (int) $validated['purchase_order_id'], $validated['almacen_id'] ?? null);
        $lineas = $this->lineasDesdeOrden($oc, $validated['details']);
        $this->validarLotes($almacenId, $lineas);

        $receipt = DB::transaction(function () use ($validated, $oc, $almacenId, $empresa, $lineas) {
            $receipt = PurchaseReceipt::create([
                ...collect($validated)->only(['receipt_date', 'supplier_document', 'supplier_document_date', 'notes', 'quality_notes'])->all(),
                'receipt_number' => PurchaseReceipt::generateReceiptNumber(),
                'purchase_order_id' => $oc->id,
                'supplier_id' => $oc->supplier_id,
                'enterprise_id' => $empresa->id,
                'almacen_id' => $almacenId,
                'status' => PurchaseReceipt::STATUS_DRAFT,
                'received_by' => Auth::id(),
                'capturada_por' => Auth::id(),
            ]);
            foreach ($lineas as $linea) {
                $receipt->details()->create($linea);
            }

            return $receipt;
        });

        return response()->json(['success' => true, 'message' => 'Recepción capturada', 'data' => $receipt->fresh(self::RELACIONES)], 201);
    }

    public function show(Request $request, PurchaseReceipt $receipt): JsonResponse
    {
        $this->acceso($request, $receipt);

        return response()->json(['success' => true, 'data' => $receipt->load([...self::RELACIONES, 'inventoryMovement:id,document_number', 'accountPayable:id,document_number'])]);
    }

    public function update(Request $request, PurchaseReceipt $receipt): JsonResponse
    {
        $empresa = $this->acceso($request, $receipt);
        if ($receipt->status !== PurchaseReceipt::STATUS_DRAFT) {
            return response()->json(['success' => false, 'message' => 'Solo se editan recepciones en captura'], 422);
        }
        $validated = $request->validate($this->reglas(false));
        [$oc] = $this->ordenRecibible($request, $empresa, (int) $receipt->purchase_order_id, $receipt->almacen_id);
        $lineas = isset($validated['details']) ? $this->lineasDesdeOrden($oc, $validated['details'], $receipt) : null;
        if ($lineas !== null) {
            $this->validarLotes((int) $receipt->almacen_id, $lineas);
        }

        DB::transaction(function () use ($receipt, $validated, $lineas) {
            $receipt->update([
                ...collect($validated)->only(['receipt_date', 'supplier_document', 'supplier_document_date', 'notes', 'quality_notes'])->all(),
                'motivo_rechazo' => null,
            ]);
            if ($lineas !== null) {
                $receipt->details()->delete();
                foreach ($lineas as $linea) {
                    $receipt->details()->create($linea);
                }
                $receipt->recalculateTotals();
            }
        });

        return response()->json(['success' => true, 'message' => 'Recepción actualizada', 'data' => $receipt->fresh(self::RELACIONES)]);
    }

    public function destroy(Request $request, PurchaseReceipt $receipt): JsonResponse
    {
        $this->acceso($request, $receipt);
        if ($receipt->status !== PurchaseReceipt::STATUS_DRAFT) {
            return response()->json(['success' => false, 'message' => 'Solo se pueden eliminar recepciones en captura'], 422);
        }
        $receipt->details()->delete();
        $receipt->delete();

        return response()->json(['success' => true, 'message' => 'Recepción eliminada']);
    }

    public function submit(Request $request, PurchaseReceipt $receipt): JsonResponse
    {
        $this->acceso($request, $receipt);
        if ($receipt->status !== PurchaseReceipt::STATUS_DRAFT) {
            return response()->json(['success' => false, 'message' => 'Solo se pueden enviar recepciones en captura'], 422);
        }
        if ($receipt->details()->count() === 0) {
            return response()->json(['success' => false, 'message' => 'La recepción debe tener al menos un producto'], 422);
        }

        $receipt->update(['status' => PurchaseReceipt::STATUS_PENDING, 'enviada_at' => now()]);
        $this->avisos->recepcionPorConfirmar($receipt);

        return response()->json(['success' => true, 'message' => 'Recepción enviada a confirmar', 'data' => $receipt->fresh(self::RELACIONES)]);
    }

    public function regresar(Request $request, PurchaseReceipt $receipt): JsonResponse
    {
        $empresa = $this->acceso($request, $receipt);
        abort_unless($this->permisos->puedeConfirmar($request->user(), $empresa), 403, 'No tienes permiso para confirmar entradas');
        if ($receipt->status !== PurchaseReceipt::STATUS_PENDING) {
            return response()->json(['success' => false, 'message' => 'La recepción no está por confirmar'], 422);
        }
        $validated = $request->validate(['motivo' => 'required|string|max:500']);

        $receipt->update(['status' => PurchaseReceipt::STATUS_DRAFT, 'motivo_rechazo' => $validated['motivo']]);
        $this->avisos->recepcionRegresada($receipt);

        return response()->json(['success' => true, 'message' => 'Recepción regresada al almacén', 'data' => $receipt->fresh(self::RELACIONES)]);
    }

    public function cancel(Request $request, PurchaseReceipt $receipt): JsonResponse
    {
        $this->acceso($request, $receipt);
        $validated = $request->validate(['reason' => 'required|string|max:500']);
        if ($receipt->status !== PurchaseReceipt::STATUS_DRAFT || ! $receipt->cancel(Auth::id(), $validated['reason'])) {
            return response()->json(['success' => false, 'message' => 'Solo se cancelan recepciones en captura'], 422);
        }

        return response()->json(['success' => true, 'message' => 'Recepción cancelada exitosamente', 'data' => $receipt]);
    }

    public function fromPurchaseOrder(Request $request, PurchaseOrder $order): JsonResponse
    {
        $empresa = $this->almacenes->resolverEmpresa($request);
        [$oc, $almacenId] = $this->ordenRecibible($request, $empresa, $order->id, null);

        // Todo lo pendiente (descontando lo comprometido en recepciones abiertas)
        $pendientes = [];
        foreach ($oc->details as $d) {
            $comprometido = $this->comprometido($d->id);
            $pendiente = (float) $d->quantity_ordered - (float) $d->quantity_received - $comprometido;
            if ($pendiente > 0) {
                $pendientes[] = ['purchase_order_detail_id' => $d->id, 'quantity_received' => $pendiente];
            }
        }
        if (! $pendientes) {
            return response()->json(['success' => false, 'message' => 'No hay productos pendientes de recepción en esta orden'], 422);
        }
        $lineas = $this->lineasDesdeOrden($oc, $pendientes);

        $receipt = DB::transaction(function () use ($oc, $almacenId, $empresa, $lineas) {
            $receipt = PurchaseReceipt::create([
                'receipt_number' => PurchaseReceipt::generateReceiptNumber(),
                'purchase_order_id' => $oc->id,
                'supplier_id' => $oc->supplier_id,
                'enterprise_id' => $empresa->id,
                'almacen_id' => $almacenId,
                'receipt_date' => now()->toDateString(),
                'status' => PurchaseReceipt::STATUS_DRAFT,
                'received_by' => Auth::id(),
                'capturada_por' => Auth::id(),
            ]);
            foreach ($lineas as $linea) {
                $receipt->details()->create($linea);
            }

            return $receipt;
        });

        return response()->json(['success' => true, 'message' => 'Recepción creada desde la orden de compra', 'data' => $receipt->fresh(self::RELACIONES)], 201);
    }
}
