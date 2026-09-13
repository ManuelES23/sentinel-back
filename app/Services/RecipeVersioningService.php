<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Support\Facades\DB;

class RecipeVersioningService
{
    /**
     * Snapshotea el estado ACTUAL de la receta (antes de aplicar la
     * edición) como la siguiente versión. Se llama antes de $recipe->update().
     */
    public function snapshotBeforeUpdate(Recipe $recipe, ?int $userId, ?string $changeNote = null): RecipeVersion
    {
        $hadItems = $recipe->relationLoaded('items');
        $hadAgroDetails = $recipe->relationLoaded('agroDetails');
        $hadRecipeCalibres = $recipe->relationLoaded('recipeCalibres');

        $recipe->loadMissing(['items', 'agroDetails', 'recipeCalibres.plus']);

        $nextVersion = (RecipeVersion::where('recipe_id', $recipe->id)->max('version_number') ?? 0) + 1;

        $version = RecipeVersion::create([
            'recipe_id' => $recipe->id,
            'version_number' => $nextVersion,
            'snapshot' => $recipe->toArray(),
            'created_by' => $userId,
            'change_note' => $changeNote,
        ]);

        // El caller (RecipeController::update()/restore()) sigue usando esta
        // misma instancia de $recipe después de llamar este método, y muta
        // items/agroDetails/recipeCalibres directamente en BD confiando en
        // que acceder a la relación después dispare un lazy-load fresco. Si
        // dejamos las relaciones que acabamos de cargar en caché (tal como
        // estaban ANTES de la edición), ese código de abajo termina leyendo
        // datos obsoletos (ej. recalculateCost() sumando 0 porque ve la
        // colección de items vieja). Solo des-cacheamos lo que nosotros
        // cargamos — si el caller ya las tenía cargadas de antes, respetamos
        // esa decisión y no le tocamos el estado.
        if (! $hadItems) {
            $recipe->unsetRelation('items');
        }
        if (! $hadAgroDetails) {
            $recipe->unsetRelation('agroDetails');
        }
        if (! $hadRecipeCalibres) {
            $recipe->unsetRelation('recipeCalibres');
        }

        return $version;
    }

    /**
     * Restaura una versión anterior aplicándola como una edición NUEVA
     * (nunca sobreescribe/borra historia — restaurar también snapshotea
     * el estado justo antes de restaurar).
     */
    public function restore(Recipe $recipe, int $versionNumber, ?int $userId): Recipe
    {
        $version = RecipeVersion::where('recipe_id', $recipe->id)
            ->where('version_number', $versionNumber)
            ->firstOrFail();

        return DB::transaction(function () use ($recipe, $version, $userId) {
            $this->snapshotBeforeUpdate($recipe, $userId, "Restaurado a la versión {$version->version_number}");

            $snapshot = $version->snapshot;

            // 'status' se omite deliberadamente: restaurar una versión que se
            // snapshoteó mientras la receta estaba 'active' NO debe saltarse
            // el flujo de aprobación devolviendo la receta actual a activa de
            // golpe. El status ACTUAL de la receta se preserva tal cual.
            $recipe->update([
                'name' => $snapshot['name'],
                'description' => $snapshot['description'] ?? null,
                'recipe_type' => $snapshot['recipe_type'] ?? null,
                'category_id' => $snapshot['category_id'] ?? null,
                'output_quantity' => $snapshot['output_quantity'] ?? 1,
                'output_unit_id' => $snapshot['output_unit_id'] ?? null,
                'notes' => $snapshot['notes'] ?? null,
            ]);

            $recipe->items()->delete();
            foreach ($snapshot['items'] ?? [] as $item) {
                $recipe->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_id' => $item['unit_id'] ?? null,
                    'waste_percentage' => $item['waste_percentage'] ?? 0,
                    'cost_per_unit' => $item['cost_per_unit'] ?? 0,
                    'is_optional' => $item['is_optional'] ?? false,
                    'sort_order' => $item['sort_order'] ?? 0,
                    'group_key' => $item['group_key'] ?? null,
                    'is_default' => $item['is_default'] ?? false,
                    'solo_interno' => $item['solo_interno'] ?? false,
                    'calibre_id' => $item['calibre_id'] ?? null,
                    'enterprise_id' => $recipe->enterprise_id,
                ]);
            }

            // El snapshot también captura agroDetails y recipeCalibres.plus
            // (ver loadMissing en snapshotBeforeUpdate) — restaurar debe
            // reflejarlos también, no solo los campos núcleo + items.
            $agroDetails = $snapshot['agro_details'] ?? null;
            if (! empty(array_filter($agroDetails ?? [], fn ($v) => $v !== null))) {
                $recipe->agroDetails()->updateOrCreate(
                    ['recipe_id' => $recipe->id],
                    [
                        'cultivo_id' => $agroDetails['cultivo_id'] ?? null,
                        'variedad_id' => $agroDetails['variedad_id'] ?? null,
                        'peso_pieza' => $agroDetails['peso_pieza'] ?? null,
                    ]
                );
            } else {
                $recipe->agroDetails()->delete();
            }

            // Mismo patrón que RecipeController::update() para sincronizar
            // calibres/plus: borrar los existentes (cascade borra plus) y
            // recrear desde el snapshot.
            $recipe->recipeCalibres()->delete();
            foreach ($snapshot['recipe_calibres'] ?? [] as $calibreData) {
                $rc = $recipe->recipeCalibres()->create([
                    'calibre_id' => $calibreData['calibre_id'],
                ]);
                foreach ($calibreData['plus'] ?? [] as $pluData) {
                    $rc->plus()->create([
                        'product_id' => $pluData['product_id'],
                        'is_organic' => $pluData['is_organic'] ?? false,
                        'notes' => $pluData['notes'] ?? null,
                    ]);
                }
            }

            $recipe->recalculateCost();

            return $recipe->fresh(['items', 'agroDetails', 'recipeCalibres.plus', 'category', 'outputProduct', 'outputUnit']);
        });
    }
}
