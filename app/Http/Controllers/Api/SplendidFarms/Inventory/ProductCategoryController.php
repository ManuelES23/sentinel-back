<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProductCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProductCategory::with(['parent:id,name,code', 'children:id,parent_id,name,code,icon,is_active'])
            ->withCount('products');

        // Filtrar solo activas
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Filtrar solo raíz
        if ($request->boolean('root_only')) {
            $query->root();
        }

        // Filtrar por padre
        if ($request->has('parent_id')) {
            $parentId = $request->input('parent_id');
            if ($parentId === 'null' || $parentId === '') {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $parentId);
            }
        }

        $categories = $query->orderBy('order')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    /**
     * Get categories in tree structure.
     */
    public function tree(): JsonResponse
    {
        $categories = ProductCategory::with('allChildren')
            ->whereNull('parent_id')
            ->active()
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:50|unique:product_categories,code',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:product_categories,slug',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:product_categories,id',
            'icon' => 'nullable|string|max:100',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        // Generar código automático si no se proporciona
        if (empty($validated['code'])) {
            $prefix = 'CAT';
            
            $lastCategory = ProductCategory::where('code', 'like', $prefix . '-%')
                ->orderByRaw('CAST(SUBSTRING(code, ' . (strlen($prefix) + 2) . ') AS UNSIGNED) DESC')
                ->first();
            
            if ($lastCategory) {
                $lastNumber = (int) substr($lastCategory->code, strlen($prefix) + 1);
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }
            
            $validated['code'] = $prefix . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }

        $category = ProductCategory::create($validated);
        $category->load('parent:id,name,code');
        $category->loadCount('products');

        return response()->json([
            'success' => true,
            'message' => 'Categoría creada exitosamente',
            'data' => $category
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(ProductCategory $category): JsonResponse
    {
        $category->load(['parent', 'children', 'products']);
        $category->loadCount('products');

        return response()->json([
            'success' => true,
            'data' => $category
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProductCategory $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:product_categories,slug,' . $category->id,
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:product_categories,id',
            'icon' => 'nullable|string|max:100',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        // Evitar que una categoría sea su propio padre
        if (isset($validated['parent_id']) && $validated['parent_id'] == $category->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Una categoría no puede ser su propio padre'
            ], 422);
        }

        $category->update($validated);
        
        $category = $category->fresh(['parent:id,name,code', 'children:id,parent_id,name,code']);
        $category->loadCount('products');

        return response()->json([
            'success' => true,
            'message' => 'Categoría actualizada exitosamente',
            'data' => $category
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProductCategory $category): JsonResponse
    {
        // Verificar si tiene subcategorías
        if ($category->children()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar la categoría porque tiene subcategorías'
            ], 422);
        }

        // Verificar si tiene productos
        if ($category->products()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar la categoría porque tiene productos asociados'
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Categoría eliminada exitosamente'
        ]);
    }
}
