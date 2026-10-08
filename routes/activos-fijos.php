<?php

use App\Http\Controllers\Api\ActivosFijos\AltaPendienteController;
use App\Http\Controllers\Api\ActivosFijos\AsignacionActivoController;
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

    // Asignaciones (resguardo) y carta responsiva
    Route::get('asignaciones', [AsignacionActivoController::class, 'index']);
    Route::get('activos/{asset}/asignaciones', [AsignacionActivoController::class, 'historial']);
    Route::get('activos/{asset}/responsables', [AsignacionActivoController::class, 'responsables']);
    Route::post('activos/{asset}/asignaciones', [AsignacionActivoController::class, 'store']);
    Route::post('activos/{asset}/reasignar', [AsignacionActivoController::class, 'reasignar']);
    Route::post('asignaciones/{asignacion}/devolver', [AsignacionActivoController::class, 'devolver']);
    Route::patch('asignaciones/{asignacion}', [AsignacionActivoController::class, 'update']);
    Route::post('asignaciones/{asignacion}/carta-firmada', [AsignacionActivoController::class, 'subirCartaFirmada']);
    Route::get('asignaciones/{asignacion}/carta-firmada', [AsignacionActivoController::class, 'descargarCartaFirmada']);
    Route::get('asignaciones/{asignacion}/carta', [AsignacionActivoController::class, 'carta']);

    // Altas desde recepciones de compra (fase 3)
    Route::get('altas-pendientes', [AltaPendienteController::class, 'index']);
    Route::post('altas-pendientes/alta', [AltaPendienteController::class, 'alta']);
    Route::post('altas-pendientes/descartar', [AltaPendienteController::class, 'descartar']);
});
