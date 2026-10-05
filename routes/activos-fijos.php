<?php

use App\Http\Controllers\Api\ActivosFijos\ContextoActivosController;
use App\Http\Controllers\Api\SplendidFarms\Inventory\AssetCategoryController;
use App\Http\Controllers\Api\SplendidFarms\Inventory\FixedAssetController;
use Illuminate\Support\Facades\Route;

/*
 * Administración → Activos Fijos. Se incluye dentro del grupo
 * {empresa}/administration de Splendid Farms, Splendid by Porvenir y Grupo
 * Espléndido; la empresa la resuelve AlcanceActivos desde la URL.
 */
Route::prefix('activos-fijos')->group(function () {
    Route::get('mis-permisos', [ContextoActivosController::class, 'permisos']);
    Route::get('catalogos', [ContextoActivosController::class, 'catalogos']);

    // Tipos de activo (catálogo central; escritura solo desde GE)
    Route::get('tipos-activo/tree', [AssetCategoryController::class, 'tree']);
    Route::apiResource('tipos-activo', AssetCategoryController::class)
        ->parameters(['tipos-activo' => 'tipoActivo']);
    Route::get('tipos-activo/{tipoActivo}/caracteristicas', [AssetCategoryController::class, 'characteristics']);
    Route::post('tipos-activo/{tipoActivo}/caracteristicas', [AssetCategoryController::class, 'storeCharacteristic']);
    Route::delete('caracteristicas/{characteristic}', [AssetCategoryController::class, 'destroyCharacteristic']);

    // Activos
    Route::get('activos/next-code', [FixedAssetController::class, 'nextCodeEndpoint']);
    Route::apiResource('activos', FixedAssetController::class)
        ->parameters(['activos' => 'asset']);
});
