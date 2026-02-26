<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\InventoryKardex;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InventoryReportController extends Controller
{
    /**
     * Get current stock report.
     */
    public function stock(Request $request): JsonResponse
    {
        $query = InventoryStock::with([
            'product:id,code,name,sku,min_stock,max_stock,reorder_point,cost_price,sale_price,image',
            'product.category:id,name,code',
            'product.unit:id,name,abbreviation',
        ]);

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
                return [
                    'product' => $product,
                    'total_quantity' => $items->sum('quantity'),
                    'total_reserved' => $items->sum('reserved_quantity'),
                    'total_available' => $items->sum('available_quantity'),
                    'total_value' => $items->sum('quantity') * ($product->cost_price ?? 0),
                    'locations' => $items->map(function ($item) {
                        return [
                            'entity_id' => $item->entity_id,
                            'entity_type' => $item->entity_type,
                            'quantity' => $item->quantity,
                            'lot_number' => $item->lot_number,
                            'expiry_date' => $item->expiry_date,
                        ];
                    }),
                ];
            })->values();
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
     * Get movements report (kardex style).
     */
    public function movements(Request $request): JsonResponse
    {
        $query = InventoryKardex::with([
            'product:id,code,name,sku',
            'movement:id,document_number,movement_type_id,movement_date',
            'movement.movementType:id,code,name,direction,color,icon',
        ]);

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
        $query = Product::with(['category:id,name,code', 'unit:id,name,abbreviation'])
            ->withSum('stock as total_stock', 'quantity')
            ->where('track_inventory', true);

        // Filtrar por categoría
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Solo con stock
        if ($request->boolean('with_stock_only')) {
            $query->having('total_stock', '>', 0);
        }

        $products = $query->get()->map(function ($product) {
            $stock = $product->total_stock ?? 0;
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

        // Productos con stock bajo
        $lowStock = Product::with(['category:id,name', 'unit:id,abbreviation'])
            ->withSum('stock as total_stock', 'quantity')
            ->where('track_inventory', true)
            ->whereNotNull('min_stock')
            ->having('total_stock', '<=', DB::raw('min_stock'))
            ->get()
            ->map(fn($p) => [
                'type' => 'low_stock',
                'severity' => 'warning',
                'product' => $p->only(['id', 'code', 'name', 'min_stock']),
                'current_stock' => $p->total_stock,
                'message' => "Stock bajo: {$p->name} ({$p->total_stock} de mínimo {$p->min_stock})",
            ]);
        $alerts = array_merge($alerts, $lowStock->toArray());

        // Productos sin stock
        $noStock = Product::with(['category:id,name', 'unit:id,abbreviation'])
            ->withSum('stock as total_stock', 'quantity')
            ->where('track_inventory', true)
            ->where('is_active', true)
            ->having('total_stock', '<=', 0)
            ->get()
            ->map(fn($p) => [
                'type' => 'no_stock',
                'severity' => 'danger',
                'product' => $p->only(['id', 'code', 'name']),
                'current_stock' => 0,
                'message' => "Sin stock: {$p->name}",
            ]);
        $alerts = array_merge($alerts, $noStock->toArray());

        // Lotes vencidos
        $expired = InventoryStock::with(['product:id,code,name'])
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

        // Lotes por vencer (próximos 30 días)
        $expiringSoon = InventoryStock::with(['product:id,code,name'])
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now(), now()->addDays(30)])
            ->where('quantity', '>', 0)
            ->get()
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
        $query = InventoryKardex::with([
            'movement:id,document_number,movement_type_id,movement_date,reference_number',
            'movement.movementType:id,code,name,direction,color,icon',
        ])->where('product_id', $product->id);

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
