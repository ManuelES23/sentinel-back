<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RecipeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Recipe::with([
            'category:id,name,code',
            'outputProduct:id,name,code',
            'outputUnit:id,name,abbreviation',
        ])->withCount('items');

        // Filtrar solo activas
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Filtrar por status
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        // Filtrar por categoría
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Búsqueda por texto
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Ordenamiento
        $sortBy = $request->input('sort_by', 'name');
        $sortDir = $request->input('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        $recipes = $query->get();

        return response()->json([
            'success' => true,
            'data' => $recipes,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:50|unique:recipes,code',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:recipes,slug',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
            'output_product_id' => 'nullable|exists:products,id',
            'output_quantity' => 'nullable|numeric|min:0.0001',
            'output_unit_id' => 'nullable|exists:units_of_measure,id',
            'status' => 'nullable|in:draft,active,inactive,archived',
            'version' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
            // Items opcionales al crear
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'items.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'items.*.waste_percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.cost_per_unit' => 'nullable|numeric|min:0',
            'items.*.is_optional' => 'nullable|boolean',
            'items.*.notes' => 'nullable|string',
            'items.*.sort_order' => 'nullable|integer|min:0',
        ]);

        // Generar código automático si no se proporciona
        if (empty($validated['code'])) {
            $prefix = 'REC';
            $lastRecipe = Recipe::where('code', 'like', $prefix . '-%')
                ->orderByRaw('CAST(SUBSTRING(code, ' . (strlen($prefix) + 2) . ') AS UNSIGNED) DESC')
                ->first();

            $nextNumber = $lastRecipe
                ? ((int) substr($lastRecipe->code, strlen($prefix) + 1)) + 1
                : 1;

            $validated['code'] = $prefix . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }

        // Extraer items antes de crear la receta
        $items = $validated['items'] ?? [];
        unset($validated['items']);

        $recipe = Recipe::create($validated);

        // Crear items si se enviaron
        if (!empty($items)) {
            foreach ($items as $index => $item) {
                $recipe->items()->create(array_merge($item, [
                    'sort_order' => $item['sort_order'] ?? $index,
                ]));
            }
            $recipe->recalculateCost();
        }

        $recipe->load([
            'category:id,name,code',
            'outputProduct:id,name,code',
            'outputUnit:id,name,abbreviation',
            'items.product:id,name,code',
            'items.unit:id,name,abbreviation',
        ]);
        $recipe->loadCount('items');

        return response()->json([
            'success' => true,
            'message' => 'Receta creada exitosamente',
            'data' => $recipe,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Recipe $recipe): JsonResponse
    {
        $recipe->load([
            'category:id,name,code',
            'outputProduct:id,name,code,cost_price',
            'outputUnit:id,name,abbreviation',
            'items.product:id,name,code,cost_price',
            'items.unit:id,name,abbreviation',
        ]);
        $recipe->loadCount('items');

        return response()->json([
            'success' => true,
            'data' => $recipe,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'sometimes|string|max:50|unique:recipes,code,' . $recipe->id,
            'name' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:recipes,slug,' . $recipe->id,
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
            'output_product_id' => 'nullable|exists:products,id',
            'output_quantity' => 'nullable|numeric|min:0.0001',
            'output_unit_id' => 'nullable|exists:units_of_measure,id',
            'estimated_cost' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,active,inactive,archived',
            'version' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        $recipe->update($validated);

        $recipe = $recipe->fresh([
            'category:id,name,code',
            'outputProduct:id,name,code',
            'outputUnit:id,name,abbreviation',
            'items.product:id,name,code',
            'items.unit:id,name,abbreviation',
        ]);
        $recipe->loadCount('items');

        return response()->json([
            'success' => true,
            'message' => 'Receta actualizada exitosamente',
            'data' => $recipe,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Recipe $recipe): JsonResponse
    {
        // No eliminar si está activa y en uso
        if ($recipe->status === 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar una receta activa. Cámbiala a inactiva o archivada primero.',
            ], 422);
        }

        $recipe->delete();

        return response()->json([
            'success' => true,
            'message' => 'Receta eliminada exitosamente',
        ]);
    }

    // ── Items / Ingredientes ────────────────────────────────────

    /**
     * Agregar un item a la receta
     */
    public function addItem(Request $request, Recipe $recipe): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0.0001',
            'unit_id' => 'nullable|exists:units_of_measure,id',
            'waste_percentage' => 'nullable|numeric|min:0|max:100',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'is_optional' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        // Verificar que no exista ya
        $exists = $recipe->items()->where('product_id', $validated['product_id'])->exists();
        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Este producto ya está en la receta',
            ], 422);
        }

        // Si no se envía cost_per_unit, tomarlo del producto
        if (!isset($validated['cost_per_unit'])) {
            $product = \App\Models\Product::find($validated['product_id']);
            $validated['cost_per_unit'] = $product?->cost_price ?? 0;
        }

        if (!isset($validated['sort_order'])) {
            $validated['sort_order'] = $recipe->items()->max('sort_order') + 1;
        }

        $item = $recipe->items()->create($validated);
        $item->load(['product:id,name,code', 'unit:id,name,abbreviation']);

        // Recalcular costo
        $recipe->recalculateCost();

        return response()->json([
            'success' => true,
            'message' => 'Ingrediente agregado exitosamente',
            'data' => $item,
            'estimated_cost' => $recipe->fresh()->estimated_cost,
        ], 201);
    }

    /**
     * Actualizar un item de la receta
     */
    public function updateItem(Request $request, Recipe $recipe, RecipeItem $item): JsonResponse
    {
        // Verificar que el item pertenezca a la receta
        if ($item->recipe_id !== $recipe->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'El item no pertenece a esta receta',
            ], 404);
        }

        $validated = $request->validate([
            'quantity' => 'sometimes|numeric|min:0.0001',
            'unit_id' => 'nullable|exists:units_of_measure,id',
            'waste_percentage' => 'nullable|numeric|min:0|max:100',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'is_optional' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $item->update($validated);
        $item->load(['product:id,name,code', 'unit:id,name,abbreviation']);

        // Recalcular costo
        $recipe->recalculateCost();

        return response()->json([
            'success' => true,
            'message' => 'Ingrediente actualizado exitosamente',
            'data' => $item,
            'estimated_cost' => $recipe->fresh()->estimated_cost,
        ]);
    }

    /**
     * Eliminar un item de la receta
     */
    public function deleteItem(Recipe $recipe, RecipeItem $item): JsonResponse
    {
        if ($item->recipe_id !== $recipe->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'El item no pertenece a esta receta',
            ], 404);
        }

        $item->delete();

        // Recalcular costo
        $recipe->recalculateCost();

        return response()->json([
            'success' => true,
            'message' => 'Ingrediente eliminado exitosamente',
            'estimated_cost' => $recipe->fresh()->estimated_cost,
        ]);
    }

    /**
     * Recalcular el costo de la receta
     */
    public function recalculateCost(Recipe $recipe): JsonResponse
    {
        $recipe->load('items');
        $recipe->recalculateCost();

        return response()->json([
            'success' => true,
            'message' => 'Costo recalculado exitosamente',
            'data' => [
                'estimated_cost' => $recipe->estimated_cost,
            ],
        ]);
    }
}
