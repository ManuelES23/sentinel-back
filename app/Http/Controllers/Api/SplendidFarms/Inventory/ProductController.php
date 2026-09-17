<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Enterprise;
use App\Models\InventoryStock;
use App\Services\Inventory\LoteCaducidadValidator;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['category:id,name,code', 'unit:id,name,abbreviation', 'brand:id,name,code']);

        // Filtrar por empresa si se envía el header
        $enterpriseSlug = $request->header('X-Enterprise-Slug');
        if ($enterpriseSlug) {
            $enterprise = Enterprise::where('slug', $enterpriseSlug)->first();
            if ($enterprise) {
                $query->forEnterprise($enterprise->id);
            }
        }

        // Filtrar solo activos
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Filtrar por categoría
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Filtrar por tipo
        if ($request->filled('product_type')) {
            $query->where('product_type', $request->product_type);
        }

        // Filtrar que controlan inventario
        if ($request->boolean('tracks_inventory')) {
            $query->tracksInventory();
        }

        // Filtrar artículos pendientes de revisión (p. ej. migrados de Aplicaciones)
        if ($request->boolean('requiere_revision')) {
            $query->where('requiere_revision', true);
        }

        // Búsqueda por texto
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%")
                  ->orWhereHas('brand', function ($bq) use ($search) {
                      $bq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Ordenamiento
        $sortBy = $request->input('sort_by', 'name');
        $sortDir = $request->input('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        // Paginación opcional
        if ($request->has('per_page')) {
            $products = $query->paginate($request->per_page);
        } else {
            $products = $query->get();
        }

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:50|unique:products,code',
            'sku' => 'nullable|string|max:100|unique:products,sku',
            'barcode' => 'nullable|string|max:100',
            'name' => 'required|string|max:255',
            'brand_id' => 'nullable|exists:brands,id',
            'slug' => 'nullable|string|max:255|unique:products,slug',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
            'unit_id' => 'nullable|exists:units_of_measure,id',
            'product_type' => 'nullable|in:product,service,raw_material,finished_good,consumable',
            'track_inventory' => 'boolean',
            'track_lots' => 'boolean',
            'track_serials' => 'boolean',
            'track_expiry' => 'boolean',
            'min_stock' => 'nullable|numeric|min:0',
            'max_stock' => 'nullable|numeric|min:0',
            'reorder_point' => 'nullable|numeric|min:0',
            'reorder_quantity' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'cost_method' => 'nullable|in:average,fifo,lifo,specific',
            'is_for_sale' => 'boolean',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
            'ingrediente_activo' => 'nullable|string|max:255',
            'dias_alerta_caducidad' => 'nullable|integer|min:0|max:3650',
        ]);

        if (filter_var($validated['track_expiry'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $validated['track_lots'] = true;
        }

        // Generar código automático si no se proporciona
        if (empty($validated['code'])) {
            $prefix = 'PROD';
            
            $lastProduct = Product::withTrashed()
                ->where('code', 'like', $prefix . '-%')
                ->orderByRaw('CAST(SUBSTRING(code, ' . (strlen($prefix) + 2) . ') AS UNSIGNED) DESC')
                ->first();
            
            if ($lastProduct) {
                $lastNumber = (int) substr($lastProduct->code, strlen($prefix) + 1);
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }
            
            $validated['code'] = $prefix . '-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        }

        // Manejar imagen
        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('products', 'public');
        }

        $product = Product::create($validated);

        // Vincular producto a la empresa actual
        $enterpriseSlug = $request->header('X-Enterprise-Slug');
        if ($enterpriseSlug) {
            $enterprise = Enterprise::where('slug', $enterpriseSlug)->first();
            if ($enterprise) {
                $product->enterprises()->syncWithoutDetaching([$enterprise->id]);
            }
        }

        $product->load(['category:id,name,code', 'unit:id,name,abbreviation', 'brand:id,name,code']);

        return response()->json([
            'success' => true,
            'message' => 'Artículo creado exitosamente',
            'data' => $product
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product): JsonResponse
    {
        $product->load(['category', 'unit', 'stock', 'brand']);

        return response()->json([
            'success' => true,
            'data' => $product
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'sku' => 'nullable|string|max:100|unique:products,sku,' . $product->id,
            'barcode' => 'nullable|string|max:100',
            'name' => 'sometimes|string|max:255',
            'brand_id' => 'nullable|exists:brands,id',
            'slug' => 'nullable|string|max:255|unique:products,slug,' . $product->id,
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
            'unit_id' => 'nullable|exists:units_of_measure,id',
            'product_type' => 'nullable|in:product,service,raw_material,finished_good,consumable',
            'track_inventory' => 'boolean',
            'track_lots' => 'boolean',
            'track_serials' => 'boolean',
            'track_expiry' => 'boolean',
            'min_stock' => 'nullable|numeric|min:0',
            'max_stock' => 'nullable|numeric|min:0',
            'reorder_point' => 'nullable|numeric|min:0',
            'reorder_quantity' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'cost_method' => 'nullable|in:average,fifo,lifo,specific',
            'is_for_sale' => 'boolean',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
            'ingrediente_activo' => 'nullable|string|max:255',
            'dias_alerta_caducidad' => 'nullable|integer|min:0|max:3650',
        ]);

        if (filter_var($validated['track_expiry'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $validated['track_lots'] = true;
        }

        $lotesAntes = (bool) $product->track_lots;
        $lotesDespues = array_key_exists('track_lots', $validated)
            ? filter_var($validated['track_lots'], FILTER_VALIDATE_BOOLEAN)
            : $lotesAntes;

        if ($lotesAntes && ! $lotesDespues) {
            $lotesConExistencia = $product->stock()->where('quantity', '>', 0)->distinct()->count('lot_number');
            if ($lotesConExistencia > 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede desactivar el control por lotes: el artículo tiene existencias en varios lotes.',
                    'errors' => [
                        'track_lots' => ['No se puede desactivar el control por lotes: el artículo tiene existencias en varios lotes.'],
                    ],
                ], 422);
            }
        }

        // Guardar desde el catálogo cuenta como revisión del artículo.
        $validated['requiere_revision'] = false;

        // Manejar imagen
        if ($request->hasFile('image')) {
            // Eliminar imagen anterior
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $validated['image'] = $request->file('image')->store('products', 'public');
        }

        DB::transaction(function () use ($product, $validated, $lotesAntes, $lotesDespues) {
            $product->update($validated);

            if (! $lotesAntes && $lotesDespues) {
                $this->marcarExistenciasSinLote($product);
            }
        });

        $product = $product->fresh(['category:id,name,code', 'unit:id,name,abbreviation', 'brand:id,name,code']);

        return response()->json([
            'success' => true,
            'message' => 'Artículo actualizado exitosamente',
            'data' => $product
        ]);
    }

    /**
     * Al activar lotes, las existencias previas sin lote pasan a SIN-LOTE
     * (fusionándose si ya existe esa fila en el mismo almacén/área).
     */
    private function marcarExistenciasSinLote(Product $product): void
    {
        $sinLote = InventoryStock::where('product_id', $product->id)->whereNull('lot_number')->get();

        foreach ($sinLote as $fila) {
            $destino = InventoryStock::where('product_id', $product->id)
                ->where('entity_id', $fila->entity_id)
                ->where('area_id', $fila->area_id)
                ->where('lot_number', LoteCaducidadValidator::SIN_LOTE)
                ->first();

            if (! $destino) {
                $fila->update(['lot_number' => LoteCaducidadValidator::SIN_LOTE]);
                continue;
            }

            $cantidad = (float) $destino->quantity + (float) $fila->quantity;
            $valor = (float) $destino->total_cost + (float) $fila->total_cost;
            $destino->update([
                'quantity' => $cantidad,
                'unit_cost' => $cantidad > 0 ? $valor / $cantidad : $destino->unit_cost,
                'total_cost' => $valor,
            ]);
            $fila->delete();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product): JsonResponse
    {
        // Verificar si tiene stock
        if ($product->stock()->where('quantity', '>', 0)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el artículo porque tiene stock'
            ], 422);
        }

        // Verificar si tiene movimientos
        if ($product->movementDetails()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el artículo porque tiene movimientos registrados'
            ], 422);
        }

        // Eliminar imagen
        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Artículo eliminado exitosamente'
        ]);
    }

    /**
     * Get stock for a specific product.
     */
    public function stock(Product $product, Request $request): JsonResponse
    {
        $query = $product->stock()->with(['entity:id,name,code', 'lastMovement:id,document_number']);

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        $stock = $query->get();

        return response()->json([
            'success' => true,
            'data' => [
                'product' => $product->only(['id', 'code', 'name', 'unit_id']),
                'total_stock' => $stock->sum('quantity'),
                'available_stock' => $stock->sum('available_quantity'),
                'details' => $stock
            ]
        ]);
    }

    /**
     * Recetas que usan este artículo como ingrediente.
     *
     * Product es many-to-many con Enterprise (un artículo SÍ puede
     * compartirse legítimamente entre empresas, ver importProducts()), pero
     * Recipe es estrictamente mono-empresa (enterprise_id obligatorio). Si
     * el mismo artículo lo usan recetas de dos empresas distintas, hay que
     * filtrar por la empresa resuelta del header para no filtrar recetas
     * ajenas — mismo patrón "lenient-if-absent" que
     * RecipeController::resolveEnterprise()/assertRecipeBelongsToResolvedEnterprise().
     */
    public function usadoEnRecetas(Product $product, Request $request): JsonResponse
    {
        $query = \App\Models\Recipe::whereHas('items', fn ($q) => $q->where('product_id', $product->id));

        $enterpriseSlug = $request->header('X-Enterprise-Slug');
        if ($enterpriseSlug) {
            $enterprise = Enterprise::where('slug', $enterpriseSlug)->first();
            if ($enterprise) {
                $query->forEnterprise($enterprise->id);
            }
        }

        $recetas = $query->get(['id', 'code', 'name', 'status']);

        return response()->json(['success' => true, 'data' => ['recetas' => $recetas]]);
    }

    /**
     * Productos disponibles para importar desde otras empresas.
     */
    public function availableForImport(Request $request): JsonResponse
    {
        $enterpriseSlug = $request->header('X-Enterprise-Slug');
        if (!$enterpriseSlug) {
            return response()->json(['status' => 'error', 'message' => 'Enterprise header requerido'], 400);
        }

        $enterprise = Enterprise::where('slug', $enterpriseSlug)->first();
        if (!$enterprise) {
            return response()->json(['status' => 'error', 'message' => 'Empresa no encontrada'], 404);
        }

        // Productos que NO están vinculados a esta empresa
        $query = Product::with(['category:id,name,code', 'unit:id,name,abbreviation', 'brand:id,name,code'])
            ->whereDoesntHave('enterprises', function ($q) use ($enterprise) {
                $q->where('enterprises.id', $enterprise->id);
            })
            ->active();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $products = $query->orderBy('name')->get();

        // Agregar info de qué empresas lo usan
        $products->each(function ($product) {
            $product->used_by = $product->enterprises()->pluck('enterprises.name');
        });

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Importar (vincular) producto(s) de otra empresa a la empresa actual.
     */
    public function importProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'exists:products,id',
        ]);

        $enterpriseSlug = $request->header('X-Enterprise-Slug');
        if (!$enterpriseSlug) {
            return response()->json(['status' => 'error', 'message' => 'Enterprise header requerido'], 400);
        }

        $enterprise = Enterprise::where('slug', $enterpriseSlug)->first();
        if (!$enterprise) {
            return response()->json(['status' => 'error', 'message' => 'Empresa no encontrada'], 404);
        }

        $enterprise->products()->syncWithoutDetaching($validated['product_ids']);

        $imported = Product::with(['category:id,name,code', 'unit:id,name,abbreviation', 'brand:id,name,code'])
            ->whereIn('id', $validated['product_ids'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => count($validated['product_ids']) . ' artículo(s) importado(s) exitosamente',
            'data' => $imported
        ]);
    }

    /**
     * Desvincular un producto de la empresa actual.
     */
    public function unlinkProduct(Request $request, Product $product): JsonResponse
    {
        $enterpriseSlug = $request->header('X-Enterprise-Slug');
        if (!$enterpriseSlug) {
            return response()->json(['status' => 'error', 'message' => 'Enterprise header requerido'], 400);
        }

        $enterprise = Enterprise::where('slug', $enterpriseSlug)->first();
        if (!$enterprise) {
            return response()->json(['status' => 'error', 'message' => 'Empresa no encontrada'], 404);
        }

        // Verificar que el producto no tenga stock en entidades de esta empresa
        $hasStock = $product->stock()
            ->whereHas('entity', function ($q) use ($enterprise) {
                $q->whereHas('branch', function ($bq) use ($enterprise) {
                    $bq->where('enterprise_id', $enterprise->id);
                });
            })
            ->where('quantity', '>', 0)
            ->exists();

        if ($hasStock) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede desvincular: el artículo tiene stock en esta empresa'
            ], 422);
        }

        $enterprise->products()->detach($product->id);

        return response()->json([
            'success' => true,
            'message' => 'Artículo desvinculado exitosamente'
        ]);
    }
}
