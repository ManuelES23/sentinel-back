<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\CosteoAgricola;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\RequisicionCampo;
use App\Models\Supplier;
use App\Services\Compras\AlcanceCompras;
use App\Services\Compras\AvisosCompras;
use App\Services\Compras\PermisosCompras;
use App\Services\Inventory\AlmacenAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequisicionCampoController extends Controller
{
    public function __construct(
        private AlmacenAccessService $almacenes,
        private AlcanceCompras $alcance,
        private PermisosCompras $permisos,
        private AvisosCompras $avisos,
    ) {
    }

    private const RELACIONES = [
        'solicitante:id,name',
        'rechazadaPor:id,name',
        'almacen:id,code,name',
        'detalles.product:id,name,code,ingrediente_activo',
        'detalles.unit:id,name,abbreviation',
        'detalles.etapa:id,nombre,codigo,lote_id',
        'detalles.etapa.lote:id,nombre',
        'visitaCampo:id,fecha_visita',
        'purchaseOrder:id,order_number,status,approved_at,rejected_at,rejection_reason',
        'cotizacionGanadora.supplier:id,business_name,trade_name',
    ];

    /** Resuelve la empresa y exige que la requisición sea visible (403). */
    private function empresaConAcceso(Request $request, RequisicionCampo $requisicion): Enterprise
    {
        $empresa = $this->almacenes->resolverEmpresa($request);
        abort_unless(
            $this->alcance->puedeVer($request->user(), $empresa, $requisicion, 'almacen_id'),
            403,
            'No tienes acceso a esta requisición'
        );

        return $empresa;
    }

    private function exigirAlmacen(Request $request, Enterprise $empresa, int $almacenId): void
    {
        abort_unless(
            $this->almacenes->puedeVer($request->user(), $empresa, $almacenId),
            403,
            'No tienes acceso a ese almacén'
        );
    }

    /**
     * Completa nombre y unidad desde el catálogo; responde 422 si algún
     * artículo no pertenece a la empresa.
     */
    private function detallesDelCatalogo(Enterprise $empresa, array $detalles): array
    {
        $ids = collect($detalles)->pluck('product_id')->map(fn ($id) => (int) $id)->unique();
        $validos = DB::table('enterprise_product')->where('enterprise_id', $empresa->id)
            ->whereIn('product_id', $ids)->pluck('product_id')->map(fn ($id) => (int) $id)->all();

        $errores = [];
        foreach (array_values($detalles) as $i => $d) {
            if (! in_array((int) $d['product_id'], $validos, true)) {
                $errores["detalles.$i.product_id"] = ['El artículo no pertenece al catálogo de la empresa.'];
            }
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        $productos = Product::whereIn('id', $ids)->get(['id', 'name', 'unit_id'])->keyBy('id');

        return array_map(function (array $d) use ($productos) {
            $p = $productos[(int) $d['product_id']];
            $d['nombre_producto'] = $p->name;
            $d['unit_id'] = $d['unit_id'] ?? $p->unit_id;

            return $d;
        }, array_values($detalles));
    }

    private function reglasDetalles(string $prefijo = 'required'): array
    {
        return [
            'detalles' => "$prefijo|array|min:1",
            'detalles.*.product_id' => 'required|integer|exists:products,id',
            'detalles.*.cantidad' => 'required|numeric|min:0.01',
            'detalles.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'detalles.*.precio_estimado' => 'nullable|numeric|min:0',
            'detalles.*.etapa_id' => 'nullable|exists:etapas,id',
            'detalles.*.lote_id' => 'nullable|exists:lotes,id',
            'detalles.*.visita_campo_recomendacion_id' => 'nullable|integer',
            'detalles.*.observaciones' => 'nullable|string',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'nullable|exists:temporadas,id',
            'bandeja' => 'nullable|in:por_cotizar',
            'almacen_id' => 'nullable|integer',
        ]);
        $empresa = $this->almacenes->resolverEmpresa($request);

        $query = $this->alcance->aplicar(RequisicionCampo::query(), 'almacen_id', $request->user(), $empresa)
            ->with(self::RELACIONES)
            ->withCount(['detalles', 'cotizaciones']);

        if ($request->input('bandeja') === 'por_cotizar') {
            abort_unless($this->permisos->puedeCotizar($request->user(), $empresa), 403, 'No tienes permiso para cotizar');
            $query->whereIn('status', RequisicionCampo::STATUS_EN_COMPRAS);
        } elseif ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }

        if ($request->filled('almacen_id')) {
            $query->where('almacen_id', $request->almacen_id);
        }
        // filled() y no has(): un filtro vacío (?status=) dejaba la lista en blanco
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }
        if ($request->filled('prioridad')) {
            $query->where('prioridad', $request->prioridad);
        }
        if ($request->filled('fecha_desde')) {
            $query->where('fecha_solicitud', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->where('fecha_solicitud', '<=', $request->fecha_hasta);
        }

        return response()->json(['success' => true, 'data' => $query->orderByDesc('fecha_solicitud')->orderByDesc('id')->get()]);
    }

    public function contexto(Request $request): JsonResponse
    {
        $empresa = $this->almacenes->resolverEmpresa($request);
        $user = $request->user();

        return response()->json(['success' => true, 'data' => [
            'almacenes' => Entity::whereIn('id', $this->almacenes->idsVisibles($user, $empresa))
                ->orderBy('name')->get(['id', 'code', 'name']),
            'puede_cotizar' => $this->permisos->puedeCotizar($user, $empresa),
            'puede_ver_todos' => $this->almacenes->puedeVerTodos($user, $empresa),
        ]]);
    }

    public function productos(Request $request): JsonResponse
    {
        $empresa = $this->almacenes->resolverEmpresa($request);
        $busqueda = trim((string) $request->input('search', ''));

        $productos = Product::whereHas('enterprises', fn ($q) => $q->where('enterprises.id', $empresa->id))
            ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$busqueda}%")
                ->orWhere('code', 'like', "%{$busqueda}%")
                ->orWhere('ingrediente_activo', 'like', "%{$busqueda}%")))
            ->with('unit:id,name,abbreviation')
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'code', 'name', 'ingrediente_activo', 'unit_id', 'track_lots', 'track_expiry']);

        return response()->json(['success' => true, 'data' => $productos]);
    }

    public function stock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'almacen_id' => 'required|integer',
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer',
        ]);
        $empresa = $this->almacenes->resolverEmpresa($request);
        $this->exigirAlmacen($request, $empresa, (int) $validated['almacen_id']);

        $stock = InventoryStock::where('entity_id', $validated['almacen_id'])
            ->whereIn('product_id', $validated['product_ids'])
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as cantidad')
            ->pluck('cantidad', 'product_id')
            ->map(fn ($c) => (float) $c);

        return response()->json(['success' => true, 'data' => $stock]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'almacen_id' => 'required|integer|exists:entities,id',
            'visita_campo_id' => 'nullable|exists:visitas_campo,id',
            'fecha_solicitud' => 'required|date',
            'prioridad' => 'required|in:baja,media,alta,urgente',
            'justificacion' => 'nullable|string',
            'observaciones' => 'nullable|string',
            ...$this->reglasDetalles(),
        ]);
        $empresa = $this->almacenes->resolverEmpresa($request);
        $this->exigirAlmacen($request, $empresa, (int) $validated['almacen_id']);
        $detalles = $this->detallesDelCatalogo($empresa, $validated['detalles']);

        // Reintentos por si dos usuarios toman el mismo número a la vez
        $intentos = 0;
        while (true) {
            try {
                $requisicion = DB::transaction(function () use ($validated, $detalles, $empresa) {
                    $requisicion = RequisicionCampo::create([
                        ...collect($validated)->except('detalles')->all(),
                        'enterprise_id' => $empresa->id,
                        'numero_requisicion' => RequisicionCampo::generateNumero(),
                        'solicitante_user_id' => Auth::id(),
                        'status' => RequisicionCampo::STATUS_BORRADOR,
                    ]);
                    foreach ($detalles as $detalle) {
                        $requisicion->detalles()->create($detalle);
                    }

                    return $requisicion;
                });

                return response()->json([
                    'success' => true,
                    'message' => 'Requisición creada exitosamente',
                    'data' => $requisicion->load(self::RELACIONES),
                ], 201);
            } catch (\Illuminate\Database\QueryException $e) {
                // 23000: número de requisición duplicado, se vuelve a intentar
                if ((string) $e->getCode() === '23000' && ++$intentos < 3) {
                    continue;
                }
                throw $e;
            }
        }
    }

    public function show(Request $request, RequisicionCampo $requisicion): JsonResponse
    {
        $this->empresaConAcceso($request, $requisicion);

        return response()->json([
            'success' => true,
            'data' => $requisicion->load([...self::RELACIONES, 'temporada:id,nombre', 'detalles.lote:id,nombre'])
                ->loadCount('cotizaciones'),
        ]);
    }

    public function update(Request $request, RequisicionCampo $requisicion): JsonResponse
    {
        $empresa = $this->empresaConAcceso($request, $requisicion);
        if (! $requisicion->is_editable) {
            return response()->json(['success' => false, 'message' => 'La requisición no puede ser modificada en su estado actual'], 422);
        }

        $validated = $request->validate([
            'almacen_id' => 'sometimes|required|integer|exists:entities,id',
            'fecha_solicitud' => 'sometimes|required|date',
            'prioridad' => 'sometimes|required|in:baja,media,alta,urgente',
            'justificacion' => 'nullable|string',
            'observaciones' => 'nullable|string',
            ...$this->reglasDetalles('sometimes|required'),
        ]);
        if (isset($validated['almacen_id'])) {
            $this->exigirAlmacen($request, $empresa, (int) $validated['almacen_id']);
        }
        $detalles = isset($validated['detalles']) ? $this->detallesDelCatalogo($empresa, $validated['detalles']) : null;

        DB::transaction(function () use ($requisicion, $validated, $detalles) {
            $datos = collect($validated)->except('detalles')->all();
            // Si Compras la había regresado, vuelve a borrador
            if ($requisicion->status === RequisicionCampo::STATUS_RECHAZADA) {
                $datos['status'] = RequisicionCampo::STATUS_BORRADOR;
            }
            $requisicion->update($datos);

            if ($detalles !== null) {
                $requisicion->detalles()->delete();
                foreach ($detalles as $detalle) {
                    $requisicion->detalles()->create($detalle);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Requisición actualizada exitosamente',
            'data' => $requisicion->fresh(self::RELACIONES),
        ]);
    }

    public function destroy(Request $request, RequisicionCampo $requisicion): JsonResponse
    {
        $this->empresaConAcceso($request, $requisicion);
        if ($requisicion->status !== RequisicionCampo::STATUS_BORRADOR) {
            return response()->json(['success' => false, 'message' => 'Solo se pueden eliminar requisiciones en borrador'], 422);
        }

        // Los renglones no se borran: la cabecera se elimina en lógico
        $requisicion->delete();

        return response()->json(['success' => true, 'message' => 'Requisición eliminada']);
    }

    public function submit(Request $request, RequisicionCampo $requisicion): JsonResponse
    {
        $this->empresaConAcceso($request, $requisicion);
        if ($requisicion->status !== RequisicionCampo::STATUS_BORRADOR) {
            return response()->json(['success' => false, 'message' => 'Solo se pueden enviar requisiciones en borrador'], 422);
        }
        if ($requisicion->detalles()->count() === 0) {
            return response()->json(['success' => false, 'message' => 'La requisición debe tener al menos un producto'], 422);
        }
        if (! $requisicion->almacen_id) {
            return response()->json(['success' => false, 'message' => 'Elige el almacén destino antes de enviar'], 422);
        }

        $requisicion->update(['status' => RequisicionCampo::STATUS_ENVIADA, 'enviada_at' => now()]);
        $this->avisos->requisicionEnviada($requisicion);

        return response()->json(['success' => true, 'message' => 'Requisición enviada a Compras', 'data' => $requisicion->fresh(self::RELACIONES)]);
    }

    /** La requisición ya no se aprueba: va directo a Compras. */
    public function approve(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'La requisición pasa directo a Compras'], 410);
    }

    public function reject(Request $request, RequisicionCampo $requisicion): JsonResponse
    {
        $empresa = $this->empresaConAcceso($request, $requisicion);
        abort_unless($this->permisos->puedeCotizar($request->user(), $empresa), 403, 'Solo Compras puede regresar requisiciones');
        if (! in_array($requisicion->status, RequisicionCampo::STATUS_EN_COMPRAS, true)) {
            return response()->json(['success' => false, 'message' => 'La requisición no está en Compras'], 422);
        }
        $validated = $request->validate(['notas_rechazo' => 'required|string|max:500']);

        $requisicion->update([
            'status' => RequisicionCampo::STATUS_RECHAZADA,
            'notas_rechazo' => $validated['notas_rechazo'],
            'rechazada_por_user_id' => Auth::id(),
            'rechazada_at' => now(),
        ]);
        $this->avisos->requisicionRechazada($requisicion);

        return response()->json(['success' => true, 'message' => 'Requisición regresada al solicitante', 'data' => $requisicion->fresh(self::RELACIONES)]);
    }

    public function cancel(Request $request, RequisicionCampo $requisicion): JsonResponse
    {
        $empresa = $this->empresaConAcceso($request, $requisicion);
        $esSolicitante = (int) $requisicion->solicitante_user_id === (int) Auth::id();
        abort_unless($esSolicitante || $this->permisos->puedeCotizar($request->user(), $empresa), 403, 'No puedes cancelar esta requisición');

        if (in_array($requisicion->status, [
            RequisicionCampo::STATUS_ORDEN_GENERADA,
            RequisicionCampo::STATUS_COMPLETADA,
            RequisicionCampo::STATUS_CANCELADA,
        ], true)) {
            return response()->json(['success' => false, 'message' => 'La requisición no puede ser cancelada en su estado actual'], 422);
        }

        $requisicion->update(['status' => RequisicionCampo::STATUS_CANCELADA]);

        return response()->json(['success' => true, 'message' => 'Requisición cancelada', 'data' => $requisicion]);
    }

    public function seguimiento(Request $request, RequisicionCampo $requisicion): JsonResponse
    {
        $this->empresaConAcceso($request, $requisicion);
        $requisicion->load(['solicitante:id,name', 'cotizaciones', 'purchaseOrder.receipts', 'purchaseOrder.approvedByUser:id,name', 'purchaseOrder.rejectedByUser:id,name']);
        $oc = $requisicion->purchaseOrder;

        $eventos = collect([
            ['tipo' => 'creada', 'fecha' => $requisicion->created_at, 'titulo' => 'Creada', 'detalle' => $requisicion->solicitante?->name],
            ['tipo' => 'enviada', 'fecha' => $requisicion->enviada_at, 'titulo' => 'Enviada a Compras', 'detalle' => null],
            ['tipo' => 'cotizaciones', 'fecha' => $requisicion->cotizaciones->min('created_at'), 'titulo' => 'En cotización', 'detalle' => $requisicion->cotizaciones->count() . ' cotización(es)'],
            ['tipo' => 'rechazada', 'fecha' => $requisicion->rechazada_at, 'titulo' => 'Regresada por Compras', 'detalle' => $requisicion->notas_rechazo],
            ['tipo' => 'orden', 'fecha' => $oc?->created_at, 'titulo' => 'Orden de compra generada', 'detalle' => $oc?->order_number],
            ['tipo' => 'aprobada', 'fecha' => $oc?->approved_at, 'titulo' => 'OC aprobada', 'detalle' => $oc?->approvedByUser?->name],
            ['tipo' => 'oc_rechazada', 'fecha' => $oc?->rejected_at, 'titulo' => 'OC rechazada', 'detalle' => $oc?->rejection_reason],
        ]);
        foreach ($oc?->receipts ?? [] as $rec) {
            $eventos->push([
                'tipo' => 'recepcion',
                'fecha' => $rec->confirmada_at ?? $rec->created_at,
                'titulo' => $rec->status === 'completed' ? 'Entrada confirmada' : 'Recepción capturada',
                'detalle' => $rec->receipt_number,
            ]);
        }

        return response()->json(['success' => true, 'data' => [
            'eventos' => $eventos->filter(fn ($e) => $e['fecha'] !== null)->sortBy('fecha')->values(),
            'orden' => $oc ? $oc->only(['id', 'order_number', 'status', 'status_label', 'total_amount', 'approved_at', 'rejected_at', 'rejection_reason']) : null,
            'recepciones' => ($oc?->receipts ?? collect())->map->only(['id', 'receipt_number', 'status', 'status_label', 'confirmada_at'])->values(),
        ]]);
    }

    /**
     * Generar Orden de Compra a partir de la requisición aprobada.
     */
    public function generarOrden(Request $request, RequisicionCampo $requisicion): JsonResponse
    {
        $this->empresaConAcceso($request, $requisicion);

        if ($requisicion->status !== RequisicionCampo::STATUS_COTIZADA) {
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

            // Crear registros de costeo a partir de las líneas reales de la OC:
            // con los detalles de la requisición, un override de cantidades o
            // precios dejaba el costeo desfasado del total de la orden.
            $order->load(['details.product.category']);
            $detallesRequisicion = $requisicion->detalles()->with('product.category')->get();

            // Renglones de la requisición por producto, en orden: se van
            // consumiendo para que dos partidas del mismo producto en etapas
            // distintas no acaben atribuidas a la misma etapa.
            $pendientesPorProducto = $detallesRequisicion->groupBy('product_id')
                ->map(fn ($grupo) => $grupo->values()->all())
                ->all();

            foreach ($order->details as $linea) {
                $impuesto = 1 + ((float) ($linea->tax_rate ?? 0) / 100);
                $costoTotal = (float) $linea->quantity_ordered * (float) $linea->unit_price * $impuesto;
                if ($costoTotal <= 0) {
                    continue;
                }

                // Lote/etapa y descripción se toman del renglón equivalente de
                // la requisición, que es quien los conoce.
                $det = null;
                if (!empty($pendientesPorProducto[$linea->product_id])) {
                    $det = array_shift($pendientesPorProducto[$linea->product_id]);
                }

                CosteoAgricola::create([
                    'temporada_id' => $requisicion->temporada_id,
                    'lote_id' => $det?->lote_id,
                    'etapa_id' => $det?->etapa_id,
                    'tipo_fuente' => CosteoAgricola::TIPO_FUENTE_REQUISICION,
                    'fuente_id' => $requisicion->id,
                    'product_id' => $linea->product_id,
                    'descripcion' => $det?->nombre_producto ?? $linea->product?->name,
                    'categoria' => $this->mapearCategoria($linea->product?->category?->name),
                    'cantidad' => $linea->quantity_ordered,
                    'unit_id' => $linea->unit_id ?? $det?->unit_id,
                    'costo_unitario' => $linea->unit_price,
                    'costo_total' => $costoTotal,
                    'fecha' => $requisicion->fecha_solicitud,
                    'user_id' => Auth::id(),
                    'notas' => "Requisición {$requisicion->numero_requisicion} → OC {$order->order_number}",
                ]);
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
     * Traduce el nombre de la categoría del producto de inventario a una de
     * las claves de CosteoAgricola::CATEGORIAS. Antes se guardaba el nombre
     * tal cual ("Fertilizantes") y el dashboard de costeo no sabía etiquetarlo.
     */
    protected function mapearCategoria(?string $nombreCategoria): string
    {
        if (!$nombreCategoria) {
            return 'otro';
        }

        $normalizado = strtolower(trim($nombreCategoria));
        // Sin acentos, para que "Agroquímicos" también coincida
        $normalizado = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'n'],
            $normalizado
        );

        $equivalencias = [
            'fertilizante' => 'fertilizante',
            'agroquimico' => 'agroquimico',
            'semilla' => 'semilla',
            'mano de obra' => 'mano_de_obra',
            'maquinaria' => 'maquinaria',
            'riego' => 'riego',
            'transporte' => 'transporte',
            'empaque' => 'empaque',
        ];

        foreach ($equivalencias as $aguja => $clave) {
            if (str_contains($normalizado, $aguja)) {
                return $clave;
            }
        }

        return 'otro';
    }

    public function suppliers(): JsonResponse
    {
        $suppliers = Supplier::where('is_active', true)
            ->select('id', 'code', 'business_name', 'trade_name', 'tax_id', 'has_credit', 'payment_terms')
            ->orderBy('business_name')
            ->get();

        return response()->json(['success' => true, 'data' => $suppliers]);
    }
}
