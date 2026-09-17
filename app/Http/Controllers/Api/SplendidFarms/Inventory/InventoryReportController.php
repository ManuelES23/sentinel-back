<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\InventoryStock;
use App\Models\InventoryKardex;
use App\Models\Product;
use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueDetalle;
use App\Services\Inventory\AlmacenAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InventoryReportController extends Controller
{
    public function __construct(private AlmacenAccessService $almacenes)
    {
    }

    /**
     * Entidades propias de la empresa del header que el usuario puede ver.
     *
     * @return array<int>
     */
    private function visibles(Request $request): array
    {
        $empresa = $this->almacenes->resolverEmpresa($request);

        $propias = Entity::whereHas('branch', fn ($q) => $q->where('enterprise_id', $empresa->id))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_intersect($propias, $this->almacenes->idsVisibles($request->user(), $empresa)));
    }

    /**
     * Obtiene la empresa actual desde el header X-Enterprise-Slug.
     *
     * Usado solo por productionConsumption(), que no forma parte de este cambio.
     */
    private function getEnterprise(Request $request): ?Enterprise
    {
        $slug = $request->header('X-Enterprise-Slug');
        if (!$slug) {
            return null;
        }

        return Enterprise::where('slug', $slug)->first();
    }

    /**
     * IDs de entidades propias de la empresa en sesión.
     *
     * Usado solo por productionConsumption(), que no forma parte de este cambio.
     */
    private function getOwnEntityIds(Request $request): array
    {
        $enterprise = $this->getEnterprise($request);
        if (!$enterprise) {
            return [];
        }

        return Entity::whereHas('branch', function ($q) use ($enterprise) {
            $q->where('enterprise_id', $enterprise->id);
        })->pluck('id')->toArray();
    }

    /**
     * Kardex por productor: todos los movimientos de inventario asociados a un productor.
     */
    public function kardexProductor($productorId, Request $request): JsonResponse
    {
        $query = InventoryKardex::with([
            'product:id,code,name,sku',
            'movement:id,document_number,movement_type_id,movement_date',
            'movement.movementType:id,code,name,direction,color,icon',
        ])->where('productor_id', $productorId)
            ->whereIn('entity_id', $this->visibles($request));

        // Filtros opcionales
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('movement_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('movement_date', '<=', $request->date_to);
        }

        $query->orderBy('movement_date', 'asc')->orderBy('id', 'asc');

        $perPage = $request->input('per_page', 100);
        $kardex = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $kardex
        ]);
    }
    /**
     * Get current stock report.
     */
    public function stock(Request $request): JsonResponse
    {
        $ownEntityIds = $this->visibles($request);

        if (empty($ownEntityIds)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [],
                    'totals' => [
                        'total_items' => 0,
                        'total_products' => 0,
                        'total_quantity' => 0,
                        'total_reserved' => 0,
                        'total_available' => 0,
                    ],
                ],
            ]);
        }

        $consumptionDateFrom = $request->input('consumption_date_from');
        $consumptionDateTo = $request->input('consumption_date_to');
        $productionConsumption = $this->getProductionConsumptionData(
            $ownEntityIds,
            $consumptionDateFrom,
            $consumptionDateTo,
        );
        $consumptionMap = $productionConsumption['map_by_entity_product'];

        $query = InventoryStock::with([
            'product:id,code,name,sku,min_stock,max_stock,reorder_point,cost_price,sale_price,image,brand_id,category_id,unit_id,dias_alerta_caducidad',
            'product.brand:id,name,code',
            'product.category:id,name,code',
            'product.unit:id,name,abbreviation',
            'entity:id,name,code,branch_id',
            'entity.branch:id,name,enterprise_id',
            'entity.branch.enterprise:id,name,slug',
        ])->whereIn('entity_id', $ownEntityIds);

        // Filtrar por producto
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filtrar por categoría
        if ($request->filled('category_id')) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        // Filtrar por marca
        if ($request->filled('brand_id')) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('brand_id', $request->brand_id);
            });
        }

        // Filtrar por entidad
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        // Filtrar con stock bajo
        if ($request->boolean('low_stock')) {
            $query->whereHas('product', function ($q) {
                $q->whereColumn('inventory_stock.quantity', '<=', 'products.min_stock');
            });
        }

        // Filtrar sin stock
        if ($request->boolean('no_stock')) {
            $query->where('quantity', '<=', 0);
        }

        // Filtrar por lote
        if ($request->filled('lot_number')) {
            $query->where('lot_number', $request->lot_number);
        }

        // Filtrar vencidos o por vencer
        if ($request->boolean('expired')) {
            $query->whereNotNull('expiry_date')->where('expiry_date', '<', now());
        }
        if ($request->filled('expiring_days')) {
            $days = (int) $request->expiring_days;
            $query->whereNotNull('expiry_date')
                  ->whereBetween('expiry_date', [now(), now()->addDays($days)]);
        }

        $stock = $query->get();

        // Agrupar por producto si se solicita
        if ($request->boolean('grouped_by_product')) {
            $stock = $stock->groupBy('product_id')->map(function ($items) {
                $product = $items->first()->product;
                $totalConsumedQuantity = $items->sum('production_consumed_quantity');
                $totalConsumedCost = $items->sum('production_consumed_cost');

                return [
                    'product' => $product,
                    'total_quantity' => $items->sum('quantity'),
                    'total_reserved' => $items->sum('reserved_quantity'),
                    'total_available' => $items->sum('available_quantity'),
                    'total_value' => $items->sum('quantity') * ($product->cost_price ?? 0),
                    'production_consumed_quantity' => round((float) $totalConsumedQuantity, 4),
                    'production_consumed_cost' => round((float) $totalConsumedCost, 4),
                    'locations' => $items->map(function ($item) {
                        return [
                            'entity_id' => $item->entity_id,
                            'entity_type' => $item->entity_type,
                            'entity' => $item->entity,
                            'quantity' => $item->quantity,
                            'lot_number' => $item->lot_number,
                            'expiry_date' => $item->expiry_date,
                        ];
                    }),
                ];
            })->values();
        } else {
            $stock->each(function ($item) use ($consumptionMap) {
                $key = $item->entity_id . ':' . $item->product_id;
                $consumed = $consumptionMap[$key] ?? ['quantity' => 0, 'cost' => 0];

                $item->setAttribute('production_consumed_quantity', round((float) ($consumed['quantity'] ?? 0), 4));
                $item->setAttribute('production_consumed_cost', round((float) ($consumed['cost'] ?? 0), 4));

                $hoy = now()->startOfDay();
                $dias = (int) ($item->product?->dias_alerta_caducidad ?? 30);
                $vence = $item->expiry_date;
                $item->setAttribute('vencido', (bool) ($vence && $vence->lt($hoy)));
                $item->setAttribute('por_caducar', (bool) ($vence && ! $vence->lt($hoy) && $vence->lte($hoy->copy()->addDays($dias))));
            });
        }

        // Calcular totales
        $totals = [
            'total_items' => $stock->count(),
            'total_products' => $stock->unique('product_id')->count(),
            'total_quantity' => $stock->sum('quantity'),
            'total_reserved' => $stock->sum('reserved_quantity'),
            'total_available' => $stock->sum('available_quantity'),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'stock' => $stock,
                'totals' => $totals,
            ]
        ]);
    }

    /**
     * Get production consumption report.
     */
    public function productionConsumption(Request $request): JsonResponse
    {
        $ownEntityIds = $this->getOwnEntityIds($request);

        if (empty($ownEntityIds)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'rows' => [],
                    'totals' => [
                        'total_rows' => 0,
                        'total_products' => 0,
                        'total_entities' => 0,
                        'total_consumed_quantity' => 0,
                        'total_consumed_cost' => 0,
                    ],
                ],
            ]);
        }

        $entityIds = $ownEntityIds;
        if ($request->filled('entity_id')) {
            $requestedEntityId = (int) $request->entity_id;
            $entityIds = in_array($requestedEntityId, $ownEntityIds, true) ? [$requestedEntityId] : [];
        }

        if (empty($entityIds)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'rows' => [],
                    'totals' => [
                        'total_rows' => 0,
                        'total_products' => 0,
                        'total_entities' => 0,
                        'total_consumed_quantity' => 0,
                        'total_consumed_cost' => 0,
                    ],
                ],
            ]);
        }

        $data = $this->getProductionConsumptionData(
            $entityIds,
            $request->input('date_from'),
            $request->input('date_to'),
            $request->filled('product_id') ? (int) $request->product_id : null,
            $request->filled('category_id') ? (int) $request->category_id : null,
            $request->filled('brand_id') ? (int) $request->brand_id : null,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'rows' => $data['rows'],
                'totals' => $data['totals'],
            ],
        ]);
    }

    /**
     * Calcula consumo de insumos por producción de empaque.
     */
    private function getProductionConsumptionData(
        array $entityIds,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $productId = null,
        ?int $categoryId = null,
        ?int $brandId = null,
    ): array {
        if (empty($entityIds)) {
            return [
                'rows' => [],
                'totals' => [
                    'total_rows' => 0,
                    'total_products' => 0,
                    'total_entities' => 0,
                    'total_consumed_quantity' => 0,
                    'total_consumed_cost' => 0,
                ],
                'map_by_entity_product' => [],
            ];
        }

        $aggregated = [];

        $accumulate = function (
            int $entityId,
            int $productIdValue,
            string $productionBrand,
            float $quantity,
            float $cost,
        ) use (&$aggregated): void {
            if ($quantity <= 0) {
                return;
            }

            $normalizedBrand = trim($productionBrand) !== '' ? trim($productionBrand) : 'SIN MARCA';
            $key = $entityId . ':' . $productIdValue . ':' . $normalizedBrand;
            if (! isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'entity_id' => $entityId,
                    'product_id' => $productIdValue,
                    'production_brand' => $normalizedBrand,
                    'consumed_quantity' => 0.0,
                    'consumed_cost' => 0.0,
                ];
            }

            $aggregated[$key]['consumed_quantity'] += $quantity;
            $aggregated[$key]['consumed_cost'] += $cost;
        };

        $applyRecipeItems = function (
            int $entityId,
            string $productionBrand,
            $recipe,
            int $totalCajas,
            bool $isCola,
        ) use ($accumulate): void {
            if ($totalCajas <= 0 || ! $recipe) {
                return;
            }

            $cajaItem = $recipe->items->first(function ($item) {
                $groupKey = strtolower(trim((string) ($item->group_key ?? '')));
                return $groupKey === 'caja' && (float) ($item->quantity ?? 0) > 0;
            });

            // Priorizar grupo caja para evitar inflar consumo cuando output_quantity no representa cajas.
            $scaleBase = (float) ($cajaItem->quantity ?? 0);
            if ($scaleBase <= 0) {
                $scaleBase = (float) ($recipe->output_quantity ?? 0);
            }

            if ($scaleBase <= 0) {
                $scaleBase = 1;
            }

            $scale = (float) $totalCajas / $scaleBase;

            if ($scale <= 0) {
                return;
            }

            foreach ($recipe->items as $item) {
                if (! $item->product_id || (bool) $item->is_optional) {
                    continue;
                }

                if (filled($item->group_key) && $item->is_default === false) {
                    continue;
                }

                if ($isCola) {
                    $groupKey = strtolower(trim((string) ($item->group_key ?? '')));
                    if (in_array($groupKey, ['esquinero', 'esquineros'], true)) {
                        continue;
                    }
                }

                $baseQty = (float) ($item->quantity ?? 0);
                if ($baseQty <= 0) {
                    continue;
                }

                $wasteFactor = 1 + (((float) ($item->waste_percentage ?? 0)) / 100);
                $consumedQty = round($baseQty * $wasteFactor * $scale, 4);
                if ($consumedQty <= 0) {
                    continue;
                }

                $unitCost = (float) ($item->cost_per_unit ?? 0);
                $consumedCost = round($consumedQty * $unitCost, 4);

                $accumulate(
                    $entityId,
                    (int) $item->product_id,
                    $productionBrand,
                    $consumedQty,
                    $consumedCost,
                );
            }
        };

        $detallesQuery = ProduccionEmpaqueDetalle::query()
            ->with([
                'produccion:id,entity_id,fecha_produccion,is_cola,deleted_at',
                'recipe:id,output_quantity',
                'recipe.items:id,recipe_id,product_id,quantity,waste_percentage,cost_per_unit,is_optional,group_key,is_default',
            ])
            ->whereHas('produccion', function ($q) use ($entityIds) {
                $q->whereIn('entity_id', $entityIds);
            })
            ->whereNotNull('recipe_id')
            ->where('total_cajas', '>', 0);

        if ($dateFrom) {
            $detallesQuery->whereDate('fecha_produccion', '>=', $dateFrom);
        }
        if ($dateTo) {
            $detallesQuery->whereDate('fecha_produccion', '<=', $dateTo);
        }

        $detalles = $detallesQuery->get();
        foreach ($detalles as $detalle) {
            $entityId = (int) ($detalle->produccion?->entity_id ?? 0);
            $totalCajas = (int) ($detalle->total_cajas ?? 0);
            if ($entityId <= 0 || $totalCajas <= 0) {
                continue;
            }

            $productionBrand = trim((string) ($detalle->marca ?? $detalle->produccion?->marca ?? ''));

            $isCola = (bool) ($detalle->produccion?->is_cola ?? false);

            $applyRecipeItems($entityId, $productionBrand, $detalle->recipe, $totalCajas, $isCola);
        }

        $produccionesSinDetalleQuery = ProduccionEmpaque::query()
            ->with([
                'recipe:id,output_quantity',
                'recipe.items:id,recipe_id,product_id,quantity,waste_percentage,cost_per_unit,is_optional,group_key,is_default',
            ])
            ->whereIn('entity_id', $entityIds)
            ->whereNotNull('recipe_id')
            ->where('total_cajas', '>', 0)
            ->doesntHave('detalles');

        if ($dateFrom) {
            $produccionesSinDetalleQuery->whereDate('fecha_produccion', '>=', $dateFrom);
        }
        if ($dateTo) {
            $produccionesSinDetalleQuery->whereDate('fecha_produccion', '<=', $dateTo);
        }

        $produccionesSinDetalle = $produccionesSinDetalleQuery->get();
        foreach ($produccionesSinDetalle as $produccion) {
            $entityId = (int) ($produccion->entity_id ?? 0);
            $totalCajas = (int) ($produccion->total_cajas ?? 0);
            if ($entityId <= 0 || $totalCajas <= 0) {
                continue;
            }

            $productionBrand = trim((string) ($produccion->marca ?? ''));

            $isCola = (bool) ($produccion->is_cola ?? false);

            $applyRecipeItems($entityId, $productionBrand, $produccion->recipe, $totalCajas, $isCola);
        }

        if (empty($aggregated)) {
            return [
                'rows' => [],
                'totals' => [
                    'total_rows' => 0,
                    'total_products' => 0,
                    'total_entities' => 0,
                    'total_consumed_quantity' => 0,
                    'total_consumed_cost' => 0,
                ],
                'map_by_entity_product' => [],
            ];
        }

        $productIds = collect($aggregated)->pluck('product_id')->unique()->values();
        $entityIdsFound = collect($aggregated)->pluck('entity_id')->unique()->values();

        $productsQuery = Product::with([
            'brand:id,name,code',
            'category:id,name,code',
            'unit:id,name,abbreviation',
        ])->whereIn('id', $productIds);

        if ($productId) {
            $productsQuery->where('id', $productId);
        }
        if ($categoryId) {
            $productsQuery->where('category_id', $categoryId);
        }
        if ($brandId) {
            $productsQuery->where('brand_id', $brandId);
        }

        $products = $productsQuery->get()->keyBy('id');
        $entities = Entity::with(['branch:id,name,enterprise_id', 'branch.enterprise:id,name,slug'])
            ->whereIn('id', $entityIdsFound)
            ->get(['id', 'name', 'code', 'branch_id'])
            ->keyBy('id');

        $rows = [];
        $mapByEntityProduct = [];

        foreach ($aggregated as $item) {
            $product = $products->get($item['product_id']);
            if (! $product) {
                continue;
            }

            $entity = $entities->get($item['entity_id']);
            $quantity = round((float) $item['consumed_quantity'], 4);
            $cost = round((float) $item['consumed_cost'], 4);
            $key = $item['entity_id'] . ':' . $item['product_id'];

            $mapByEntityProduct[$key] = [
                'quantity' => $quantity,
                'cost' => $cost,
            ];

            $rows[] = [
                'entity_id' => $item['entity_id'],
                'entity' => $entity,
                'product_id' => $item['product_id'],
                'product' => $product,
                'production_brand' => $item['production_brand'] ?? 'SIN MARCA',
                'consumed_quantity' => $quantity,
                'consumed_cost' => $cost,
            ];

            if (! isset($mapByEntityProduct[$key])) {
                $mapByEntityProduct[$key] = [
                    'quantity' => 0,
                    'cost' => 0,
                ];
            }

            $mapByEntityProduct[$key]['quantity'] += $quantity;
            $mapByEntityProduct[$key]['cost'] += $cost;
        }

        usort($rows, fn ($a, $b) => ($b['consumed_quantity'] <=> $a['consumed_quantity']));

        $totals = [
            'total_rows' => count($rows),
            'total_products' => collect($rows)->pluck('product_id')->unique()->count(),
            'total_entities' => collect($rows)->pluck('entity_id')->unique()->count(),
            'total_consumed_quantity' => round((float) collect($rows)->sum('consumed_quantity'), 4),
            'total_consumed_cost' => round((float) collect($rows)->sum('consumed_cost'), 4),
        ];

        return [
            'rows' => $rows,
            'totals' => $totals,
            'map_by_entity_product' => $mapByEntityProduct,
        ];
    }

    /**
     * Get movements report (kardex style).
     */
    public function movements(Request $request): JsonResponse
    {
        $query = InventoryKardex::with([
            'product:id,code,name,sku',
            'movement:id,document_number,movement_type_id,movement_date',
            'movement.movementType:id,code,name,direction,color,icon',
        ]);
        $query->whereIn('entity_id', $this->visibles($request));

        // Filtrar por producto (requerido o todos)
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filtrar por entidad
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        // Filtrar por rango de fechas
        if ($request->filled('date_from')) {
            $query->whereHas('movement', function ($q) use ($request) {
                $q->whereDate('movement_date', '>=', $request->date_from);
            });
        }
        if ($request->filled('date_to')) {
            $query->whereHas('movement', function ($q) use ($request) {
                $q->whereDate('movement_date', '<=', $request->date_to);
            });
        }

        // Filtrar por tipo de transacción
        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        // Ordenamiento
        $query->orderBy('created_at', 'desc');

        // Paginación
        $perPage = $request->input('per_page', 50);
        $kardex = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $kardex
        ]);
    }

    /**
     * Get valued inventory report.
     */
    public function valued(Request $request): JsonResponse
    {
        $ids = $this->visibles($request);
        $empresa = $this->almacenes->resolverEmpresa($request);
        $query = Product::with(['category:id,name,code', 'unit:id,name,abbreviation'])
            ->forEnterprise($empresa->id)
            ->withSum(['stock as total_stock' => fn ($q) => $q->whereIn('entity_id', $ids)], 'quantity')
            ->where('track_inventory', true);

        // Filtrar por categoría
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Solo con stock
        if ($request->boolean('with_stock_only')) {
            $query->groupBy('products.id')->having('total_stock', '>', 0);
        }

        $products = $query->get()->map(function ($product) {
            // total_stock viene del withSum filtrado por almacenes visibles; Product
            // define un accessor getTotalStockAttribute() que recalcula sin filtrar,
            // así que se lee el atributo crudo para respetar el filtro por almacén.
            $stock = (float) ($product->getAttributes()['total_stock'] ?? 0);
            $costValue = $stock * ($product->cost_price ?? 0);
            $saleValue = $stock * ($product->sale_price ?? 0);
            
            return [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category,
                'unit' => $product->unit,
                'quantity' => $stock,
                'cost_price' => $product->cost_price,
                'sale_price' => $product->sale_price,
                'cost_value' => $costValue,
                'sale_value' => $saleValue,
                'potential_profit' => $saleValue - $costValue,
                'margin_percentage' => $costValue > 0 ? (($saleValue - $costValue) / $costValue) * 100 : 0,
            ];
        });

        // Agrupar por categoría si se solicita
        if ($request->boolean('grouped_by_category')) {
            $products = $products->groupBy(fn($p) => $p['category']['id'] ?? 'uncategorized')
                ->map(function ($items, $categoryId) {
                    $category = $items->first()['category'];
                    return [
                        'category' => $category,
                        'total_items' => $items->count(),
                        'total_quantity' => $items->sum('quantity'),
                        'total_cost_value' => $items->sum('cost_value'),
                        'total_sale_value' => $items->sum('sale_value'),
                        'total_potential_profit' => $items->sum('potential_profit'),
                        'products' => $items,
                    ];
                })->values();
        }

        // Calcular totales generales
        $totals = [
            'total_products' => $products->count(),
            'total_quantity' => $products->sum('quantity'),
            'total_cost_value' => $products->sum('cost_value'),
            'total_sale_value' => $products->sum('sale_value'),
            'total_potential_profit' => $products->sum('potential_profit'),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $products,
                'totals' => $totals,
            ]
        ]);
    }

    /**
     * Get stock alerts (low stock, expired, etc.).
     */
    public function alerts(Request $request): JsonResponse
    {
        $alerts = [];
        $ids = $this->visibles($request);
        $empresaId = $this->almacenes->resolverEmpresa($request)->id;

        // Productos con stock bajo
        $lowStock = Product::with(['category:id,name', 'unit:id,abbreviation'])
            ->forEnterprise($empresaId)
            ->withSum(['stock as total_stock' => fn ($q) => $q->whereIn('entity_id', $ids)], 'quantity')
            ->where('track_inventory', true)
            ->whereNotNull('min_stock')
            ->groupBy('products.id')
            ->having('total_stock', '<=', DB::raw('min_stock'))
            ->get()
            ->map(function ($p) {
                // Leer el atributo crudo: Product::getTotalStockAttribute() recalcula
                // sin filtrar por almacén y pisaría el total_stock del withSum.
                $totalStock = (float) ($p->getAttributes()['total_stock'] ?? 0);

                return [
                    'type' => 'low_stock',
                    'severity' => 'warning',
                    'product' => [
                        'id' => $p->id,
                        'code' => $p->code,
                        'name' => $p->name,
                        'min_stock' => $p->min_stock,
                    ],
                    'current_stock' => $totalStock,
                    'message' => "Stock bajo: {$p->name} ({$totalStock} de mínimo {$p->min_stock})",
                ];
            });
        $alerts = array_merge($alerts, $lowStock->toArray());

        // Productos sin stock
        $noStock = Product::with(['category:id,name', 'unit:id,abbreviation'])
            ->forEnterprise($empresaId)
            ->withSum(['stock as total_stock' => fn ($q) => $q->whereIn('entity_id', $ids)], 'quantity')
            ->where('track_inventory', true)
            ->where('is_active', true)
            ->groupBy('products.id')
            ->having('total_stock', '<=', 0)
            ->get()
            ->map(fn($p) => [
                'type' => 'no_stock',
                'severity' => 'danger',
                'product' => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                ],
                'current_stock' => 0,
                'message' => "Sin stock: {$p->name}",
            ]);
        $alerts = array_merge($alerts, $noStock->toArray());

        // Lotes vencidos
        $expired = InventoryStock::with(['product:id,code,name'])
            ->whereIn('entity_id', $ids)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', now())
            ->where('quantity', '>', 0)
            ->get()
            ->map(fn($s) => [
                'type' => 'expired',
                'severity' => 'danger',
                'product' => $s->product->only(['id', 'code', 'name']),
                'lot_number' => $s->lot_number,
                'expiry_date' => $s->expiry_date,
                'quantity' => $s->quantity,
                'message' => "Lote vencido: {$s->product->name} - Lote {$s->lot_number}",
            ]);
        $alerts = array_merge($alerts, $expired->toArray());

        // Lotes por vencer (según días de alerta del artículo)
        $expiringSoon = InventoryStock::with(['product:id,code,name,dias_alerta_caducidad'])
            ->whereIn('entity_id', $ids)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '>=', now()->startOfDay())
            ->where('quantity', '>', 0)
            ->get()
            ->filter(fn ($s) => $s->expiry_date->lte(now()->startOfDay()->addDays((int) ($s->product->dias_alerta_caducidad ?? 30))))
            ->map(fn($s) => [
                'type' => 'expiring_soon',
                'severity' => 'warning',
                'product' => $s->product->only(['id', 'code', 'name']),
                'lot_number' => $s->lot_number,
                'expiry_date' => $s->expiry_date,
                'days_until_expiry' => now()->diffInDays($s->expiry_date),
                'quantity' => $s->quantity,
                'message' => "Por vencer: {$s->product->name} - Lote {$s->lot_number} ({$s->expiry_date->format('d/m/Y')})",
            ]);
        $alerts = array_merge($alerts, $expiringSoon->toArray());

        // Ordenar por severidad
        $severityOrder = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, fn($a, $b) => $severityOrder[$a['severity']] <=> $severityOrder[$b['severity']]);

        return response()->json([
            'success' => true,
            'data' => [
                'alerts' => $alerts,
                'summary' => [
                    'total' => count($alerts),
                    'danger' => count(array_filter($alerts, fn($a) => $a['severity'] === 'danger')),
                    'warning' => count(array_filter($alerts, fn($a) => $a['severity'] === 'warning')),
                ],
            ]
        ]);
    }

    /**
     * Get product kardex (detailed movement history).
     */
    public function productKardex(Product $product, Request $request): JsonResponse
    {
        $ids = $this->visibles($request);

        $query = InventoryKardex::with([
            'movement:id,document_number,movement_type_id,movement_date,reference_number',
            'movement.movementType:id,code,name,direction,color,icon',
        ])->where('product_id', $product->id)
            ->whereIn('entity_id', $ids);

        // Filtrar por entidad
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        // Filtrar por rango de fechas
        if ($request->filled('date_from')) {
            $query->whereHas('movement', function ($q) use ($request) {
                $q->whereDate('movement_date', '>=', $request->date_from);
            });
        }
        if ($request->filled('date_to')) {
            $query->whereHas('movement', function ($q) use ($request) {
                $q->whereDate('movement_date', '<=', $request->date_to);
            });
        }

        $query->orderBy('created_at', 'asc');

        $kardex = $query->get();

        // Calcular saldo inicial si hay filtro de fecha
        $initialBalance = 0;
        if ($request->filled('date_from')) {
            $initialBalance = InventoryKardex::where('product_id', $product->id)
                ->whereIn('entity_id', $ids)
                ->whereHas('movement', function ($q) use ($request) {
                    $q->whereDate('movement_date', '<', $request->date_from);
                })
                ->sum(DB::raw('CASE WHEN transaction_type = "increase" THEN quantity ELSE -quantity END'));
        }

        // Calcular balance acumulado
        $balance = $initialBalance;
        $kardexWithBalance = $kardex->map(function ($entry) use (&$balance) {
            if ($entry->transaction_type === 'increase') {
                $balance += $entry->quantity;
            } else {
                $balance -= $entry->quantity;
            }
            
            return [
                'id' => $entry->id,
                'movement' => $entry->movement,
                'transaction_type' => $entry->transaction_type,
                'quantity' => $entry->quantity,
                'unit_cost' => $entry->unit_cost,
                'total_cost' => $entry->total_cost,
                'lot_number' => $entry->lot_number,
                'serial_number' => $entry->serial_number,
                'balance_quantity' => $balance,
                'balance_value' => $entry->balance_value,
                'created_at' => $entry->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'product' => $product->only(['id', 'code', 'name', 'sku']),
                'initial_balance' => $initialBalance,
                'final_balance' => $balance,
                'entries' => $kardexWithBalance,
            ]
        ]);
    }
}
