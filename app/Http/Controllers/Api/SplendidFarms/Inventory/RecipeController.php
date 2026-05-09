<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\UnitOfMeasure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RecipeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Recipe::with([
            'category:id,name,code',
            'cultivo:id,nombre',
            'variedad:id,nombre',
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

        // Filtrar por cultivo
        if ($request->filled('cultivo_id')) {
            $query->where('cultivo_id', $request->cultivo_id);
        }

        // Filtrar por tipo de receta
        if ($request->filled('recipe_type')) {
            $query->where('recipe_type', $request->recipe_type);
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
            'recipe_type' => 'nullable|string|max:50',
            'slug' => 'nullable|string|max:255|unique:recipes,slug',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
            'cultivo_id' => 'nullable|exists:cultivos,id',
            'output_quantity' => 'nullable|numeric|min:0.01',
            'output_unit_id' => 'nullable|exists:units_of_measure,id',
            'peso_pieza' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,active,inactive,archived',
            'version' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
            // Items opcionales al crear
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
            'items.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'items.*.waste_percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.cost_per_unit' => 'nullable|numeric|min:0',
            'items.*.is_optional' => 'nullable|boolean',
            'items.*.notes' => 'nullable|string',
            'items.*.sort_order' => 'nullable|integer|min:0',
            'items.*.group_key' => 'nullable|string|max:50',
            'items.*.is_default' => 'nullable|boolean',
            'items.*.solo_interno' => 'nullable|boolean',
            'items.*.calibre_id' => 'nullable|exists:calibres,id',
            // Variedad
            'variedad_id' => 'nullable|exists:variedades,id',
            // Calibres y PLUs
            'calibres' => 'nullable|array',
            'calibres.*.calibre_id' => 'required|exists:calibres,id',
            'calibres.*.plus' => 'nullable|array',
            'calibres.*.plus.*.product_id' => 'required|exists:products,id',
            'calibres.*.plus.*.is_organic' => 'nullable|boolean',
            'calibres.*.plus.*.notes' => 'nullable|string|max:500',
        ]);

        $recipe = Recipe::create($validated);
        $this->ensureCajaGroup($recipe);

        return response()->json([
            'success' => true,
            'data' => $recipe,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Recipe $recipe): JsonResponse
    {
        $recipe->load([
            'category:id,name,code',
            'cultivo:id,nombre',
            'variedad:id,nombre',
            'outputProduct:id,name,code,cost_price',
            'outputUnit:id,name,abbreviation',
            'items.product:id,name,code,cost_price,brand_id',
            'items.product.brand:id,name',
            'items.unit:id,name,abbreviation',
            'items.calibre:id,nombre,valor',
            'recipeCalibres.calibre:id,nombre,valor',
            'recipeCalibres.plus.product:id,code,name',
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
            'code' => 'sometimes|string|max:50|unique:recipes,code,'.$recipe->id,
            'name' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:recipes,slug,'.$recipe->id,
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
            'cultivo_id' => 'nullable|exists:cultivos,id',
            'recipe_type' => 'nullable|string|max:50',
            'output_quantity' => 'nullable|numeric|min:0.01',
            'output_unit_id' => 'nullable|exists:units_of_measure,id',
            'peso_pieza' => 'nullable|numeric|min:0',
            'estimated_cost' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,active,inactive,archived',
            'version' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
            // Items opcionales al actualizar
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
            'items.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'items.*.waste_percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.cost_per_unit' => 'nullable|numeric|min:0',
            'items.*.is_optional' => 'nullable|boolean',
            'items.*.notes' => 'nullable|string',
            'items.*.sort_order' => 'nullable|integer|min:0',
            'items.*.group_key' => 'nullable|string|max:50',
            'items.*.is_default' => 'nullable|boolean',
            'items.*.solo_interno' => 'nullable|boolean',
            'items.*.calibre_id' => 'nullable|exists:calibres,id',
            // Variedad
            'variedad_id' => 'nullable|exists:variedades,id',
            // Calibres y PLUs
            'calibres' => 'nullable|array',
            'calibres.*.calibre_id' => 'required|exists:calibres,id',
            'calibres.*.plus' => 'nullable|array',
            'calibres.*.plus.*.product_id' => 'required|exists:products,id',
            'calibres.*.plus.*.is_organic' => 'nullable|boolean',
            'calibres.*.plus.*.notes' => 'nullable|string|max:500',
        ]);

        // Extraer items y calibres antes de actualizar la receta
        $items = null;
        if (array_key_exists('items', $validated)) {
            $items = $validated['items'] ?? [];
            $items = $this->ensureCajaGroupForEmpaquePallet($items, $validated, $recipe);
            unset($validated['items']);
        }

        $calibres = null;
        if (array_key_exists('calibres', $validated)) {
            $calibres = $validated['calibres'] ?? [];
            unset($validated['calibres']);
        }

        $recipe->update($validated);

        // Sincronizar items si se enviaron
        if ($items !== null) {
            // Eliminar items existentes y recrear
            $recipe->items()->delete();

            foreach ($items as $index => $item) {
                $recipe->items()->create(array_merge($item, [
                    'sort_order' => $item['sort_order'] ?? $index,
                ]));
            }

            $recipe->recalculateCost();

            // Actualizar cost_price del producto enlazado
            if ($recipe->output_product_id) {
                Product::where('id', $recipe->output_product_id)
                    ->update(['cost_price' => $recipe->fresh()->estimated_cost ?? 0]);
            }
        }

        // Sincronizar calibres y PLUs si se enviaron
        if ($calibres !== null) {
            // Eliminar calibres existentes (cascade borra plus)
            $recipe->recipeCalibres()->delete();

            foreach ($calibres as $calibreData) {
                $rc = $recipe->recipeCalibres()->create([
                    'calibre_id' => $calibreData['calibre_id'],
                ]);
                if (! empty($calibreData['plus'])) {
                    foreach ($calibreData['plus'] as $pluData) {
                        $rc->plus()->create([
                            'product_id' => $pluData['product_id'],
                            'is_organic' => $pluData['is_organic'] ?? false,
                            'notes' => $pluData['notes'] ?? null,
                        ]);
                    }
                }
            }
        }

        // Sincronizar datos al producto enlazado
        if ($recipe->output_product_id) {
            $productUpdates = [];
            if (isset($validated['name'])) {
                $productUpdates['name'] = $validated['name'];
            }
            if (isset($validated['description'])) {
                $productUpdates['description'] = $validated['description'];
            }
            if (isset($validated['output_unit_id'])) {
                $productUpdates['unit_id'] = $validated['output_unit_id'];
            }
            if (isset($validated['is_active'])) {
                $productUpdates['is_active'] = $validated['is_active'];
            }
            if (! empty($productUpdates)) {
                Product::where('id', $recipe->output_product_id)->update($productUpdates);
            }
        }

        $recipe = $recipe->fresh([
            'category:id,name,code',
            'cultivo:id,nombre',
            'variedad:id,nombre',
            'outputProduct:id,name,code',
            'outputUnit:id,name,abbreviation',
            'items.product:id,name,code,brand_id',
            'items.product.brand:id,name',
            'items.unit:id,name,abbreviation',
            'items.calibre:id,nombre,valor',
            'recipeCalibres.calibre:id,nombre,valor',
            'recipeCalibres.plus.product:id,code,name',
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
            'quantity' => 'required|numeric|min:0.01',
            'unit_id' => 'nullable|exists:units_of_measure,id',
            'waste_percentage' => 'nullable|numeric|min:0|max:100',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'is_optional' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'group_key' => 'nullable|string|max:50',
            'is_default' => 'nullable|boolean',
            'solo_interno' => 'nullable|boolean',
            'calibre_id' => 'nullable|exists:calibres,id',
        ]);

        // Verificar duplicado solo para items sin grupo o dentro del mismo grupo
        $duplicateQuery = $recipe->items()->where('product_id', $validated['product_id']);
        if (! empty($validated['group_key'])) {
            $duplicateQuery->where('group_key', $validated['group_key']);
        } else {
            $duplicateQuery->whereNull('group_key');
        }
        if ($duplicateQuery->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Este producto ya está en la receta',
            ], 422);
        }

        // Si no se envía cost_per_unit, tomarlo del producto
        if (! isset($validated['cost_per_unit'])) {
            $product = \App\Models\Product::find($validated['product_id']);
            $validated['cost_per_unit'] = $product?->cost_price ?? 0;
        }

        if (! isset($validated['sort_order'])) {
            $validated['sort_order'] = $recipe->items()->max('sort_order') + 1;
        }

        $item = $recipe->items()->create($validated);
        $item->load(['product:id,name,code', 'unit:id,name,abbreviation', 'calibre:id,nombre,valor']);

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
            'quantity' => 'sometimes|numeric|min:0.01',
            'unit_id' => 'nullable|exists:units_of_measure,id',
            'waste_percentage' => 'nullable|numeric|min:0|max:100',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'is_optional' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'group_key' => 'nullable|string|max:50',
            'is_default' => 'nullable|boolean',
            'solo_interno' => 'nullable|boolean',
            'calibre_id' => 'nullable|exists:calibres,id',
        ]);

        $item->update($validated);
        $item->load(['product:id,name,code', 'unit:id,name,abbreviation', 'calibre:id,nombre,valor']);

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

    /**
     * Asegura el grupo "Caja" cuando la receta es de empaque para pallet.
     *
     * Regla:
     * - Si ya existe el grupo caja (en cualquier casing), solo normaliza a "Caja".
     * - Si no existe, mueve al grupo "Caja" los items sin grupo cuyo producto contiene "caja".
     */
    private function ensureCajaGroupForEmpaquePallet(array $items, array $payload, ?Recipe $recipe = null): array
    {
        if (empty($items) || ! $this->isEmpaquePalletRecipe($payload, $recipe)) {
            return $items;
        }

        $normalizedItems = $items;
        $hasCajaGroup = false;
        $hasCajaDefault = false;
        $firstCajaIndex = null;

        foreach ($normalizedItems as $index => $item) {
            $groupKey = trim((string) ($item['group_key'] ?? ''));
            if ($groupKey === '') {
                continue;
            }

            if (Str::lower($groupKey) === 'caja') {
                $normalizedItems[$index]['group_key'] = 'Caja';
                $hasCajaGroup = true;

                if ($firstCajaIndex === null) {
                    $firstCajaIndex = $index;
                }

                if (! empty($item['is_default'])) {
                    $hasCajaDefault = true;
                }
            }
        }

        if (! $hasCajaGroup) {
            $productIds = collect($normalizedItems)
                ->pluck('product_id')
                ->filter()
                ->unique()
                ->values();

            $productNames = Product::whereIn('id', $productIds)
                ->pluck('name', 'id');

            foreach ($normalizedItems as $index => $item) {
                $groupKey = trim((string) ($item['group_key'] ?? ''));
                if ($groupKey !== '') {
                    continue;
                }

                $productId = (int) ($item['product_id'] ?? 0);
                $productName = Str::lower((string) ($productNames[$productId] ?? ''));

                if ($productName !== '' && Str::contains($productName, 'caja')) {
                    $normalizedItems[$index]['group_key'] = 'Caja';
                    $hasCajaGroup = true;

                    if ($firstCajaIndex === null) {
                        $firstCajaIndex = $index;
                    }

                    if (! empty($item['is_default'])) {
                        $hasCajaDefault = true;
                    }
                }
            }
        }

        if ($hasCajaGroup && ! $hasCajaDefault && $firstCajaIndex !== null) {
            $normalizedItems[$firstCajaIndex]['is_default'] = true;
        }

        return $normalizedItems;
    }

    /**
     * Determina si la receta aplica a empaque para pallet.
     */
    private function isEmpaquePalletRecipe(array $payload, ?Recipe $recipe = null): bool
    {
        $recipeType = Str::lower((string) ($payload['recipe_type'] ?? $recipe?->recipe_type ?? ''));
        if ($recipeType !== 'empaque') {
            return false;
        }

        $metadata = $payload['metadata'] ?? ($recipe?->metadata ?? []);

        $packagingTarget = Str::lower((string) (
            data_get($metadata, 'packaging_target')
            ?? data_get($metadata, 'packagingTarget')
            ?? ''
        ));

        if ($packagingTarget === 'pallet') {
            return true;
        }

        if ((bool) (data_get($metadata, 'is_pallet') ?? data_get($metadata, 'isPallet') ?? false)) {
            return true;
        }

        $outputUnitId = (int) ($payload['output_unit_id'] ?? $recipe?->output_unit_id ?? 0);
        if ($outputUnitId > 0) {
            $unit = UnitOfMeasure::find($outputUnitId);
            if ($unit) {
                $unitText = Str::lower(trim(($unit->name ?? '').' '.($unit->abbreviation ?? '')));
                if (Str::contains($unitText, ['pallet', 'tarima'])) {
                    return true;
                }
            }
        }

        $name = Str::lower((string) ($payload['name'] ?? $recipe?->name ?? ''));

        return Str::contains($name, 'pallet');
    }

    /**
     * Ensure Caja group for Empaque + Pallet recipes.
     */
    private function ensureCajaGroup(Recipe $recipe): void
    {
        if ($recipe->recipe_type === 'empaque' && $recipe->category->name === 'Pallet') {
            $existingGroup = $recipe->items()->where('group_name', 'Caja')->exists();
            if (! $existingGroup) {
                $recipe->items()->create([
                    'group_name' => 'Caja',
                    'quantity' => 1,
                    'unit_id' => UnitOfMeasure::where('name', 'Caja')->value('id'),
                ]);
            }
        }
    }
}
