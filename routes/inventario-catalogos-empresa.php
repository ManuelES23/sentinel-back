<?php

use App\Http\Controllers\Api\SplendidFarms\Inventory\BrandController;
use App\Http\Controllers\Api\SplendidFarms\Inventory\ProductCategoryController;
use App\Http\Controllers\Api\SplendidFarms\Inventory\ProductController;
use App\Http\Controllers\Api\SplendidFarms\Inventory\RecipeController;
use App\Http\Controllers\Api\SplendidFarms\Inventory\UnitOfMeasureController;
use Illuminate\Support\Facades\Route;

/*
 * Inventario → Catálogos (categorías, unidades, artículos, marcas y recetas) para
 * cualquier empresa que no tenga su propio bloque (Splendid Farms y Splendid by
 * Porvenir tienen rutas propias, más completas, y siguen ganando). Se incluye UNA
 * vez en el grupo genérico {empresa}/inventario de routes/api.php (middleware
 * EmpresaDeLaRuta): sin lista de slugs fijos, así que un cambio de nombre/slug de
 * la empresa (como le pasó a Canes Agro) o una empresa nueva no lo rompe.
 *
 * Reutiliza los controladores de Splendid Farms, ya aislados por enterprise_id /
 * enterprise_product* vía el header X-Enterprise-Slug, que EmpresaDeLaRuta fija
 * siempre al slug de la URL (ver docs/superpowers/specs/2026-09-09-recetas-generalization-design.md).
 */
Route::prefix('catalogos')->group(function () {
    Route::get('categorias/tree', [ProductCategoryController::class, 'tree']);
    Route::apiResource('categorias', ProductCategoryController::class)
        ->parameters(['categorias' => 'category']);

    Route::get('unidades/convert', [UnitOfMeasureController::class, 'convert']);
    Route::apiResource('unidades', UnitOfMeasureController::class)
        ->parameters(['unidades' => 'unit']);

    Route::get('articulos/available-import', [ProductController::class, 'availableForImport']);
    Route::get('articulos/{product}/stock', [ProductController::class, 'stock']);
    Route::get('articulos/{product}/usado-en-recetas', [ProductController::class, 'usadoEnRecetas']);
    Route::apiResource('articulos', ProductController::class)
        ->parameters(['articulos' => 'product']);

    Route::get('marcas/list', [BrandController::class, 'list']);
    Route::apiResource('marcas', BrandController::class)
        ->parameters(['marcas' => 'brand']);

    Route::post('recetas/{recipe}/items', [RecipeController::class, 'addItem']);
    Route::put('recetas/{recipe}/items/{item}', [RecipeController::class, 'updateItem']);
    Route::delete('recetas/{recipe}/items/{item}', [RecipeController::class, 'deleteItem']);
    Route::post('recetas/{recipe}/recalculate-cost', [RecipeController::class, 'recalculateCost']);
    Route::get('recetas/{recipe}/uso', [RecipeController::class, 'uso']);
    Route::get('recetas/{recipe}/versions', [RecipeController::class, 'versions']);
    Route::post('recetas/{recipe}/versions/{versionNumber}/restore', [RecipeController::class, 'restoreVersion']);
    Route::post('recetas/{recipe}/submit-for-approval', [RecipeController::class, 'submitForApproval']);
    Route::post('recetas/{recipe}/approve', [RecipeController::class, 'approve']);
    Route::post('recetas/{recipe}/reject', [RecipeController::class, 'reject']);
    Route::apiResource('recetas', RecipeController::class)
        ->parameters(['recetas' => 'recipe']);
});
