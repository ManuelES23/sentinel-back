<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['category:id,name,code', 'unit:id,name,abbreviation']);

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

        // Búsqueda por texto
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
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
            'image' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        // Generar código automático si no se proporciona
        if (empty($validated['code'])) {
            $prefix = 'PROD';
            
            $lastProduct = Product::where('code', 'like', $prefix . '-%')
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
        $product->load(['category:id,name,code', 'unit:id,name,abbreviation']);

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
        $product->load(['category', 'unit', 'stock']);

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
            'image' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        // Manejar imagen
        if ($request->hasFile('image')) {
            // Eliminar imagen anterior
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $validated['image'] = $request->file('image')->store('products', 'public');
        }

        $product->update($validated);
        
        $product = $product->fresh(['category:id,name,code', 'unit:id,name,abbreviation']);

        return response()->json([
            'success' => true,
            'message' => 'Artículo actualizado exitosamente',
            'data' => $product
        ]);
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
}
