<?php

use App\Http\Controllers\Api\ActivosFijos\AltaPendienteController;
use App\Http\Controllers\Api\ActivosFijos\AsignacionActivoController;
use App\Http\Controllers\Api\ActivosFijos\BandejaAsignacionesController;
use App\Http\Controllers\Api\ActivosFijos\ContextoActivosController;
use App\Http\Controllers\Api\ActivosFijos\DepreciacionController;
use App\Http\Controllers\Api\ActivosFijos\SalidaActivoController;
use App\Http\Controllers\Api\SplendidFarms\Inventory\AssetCategoryController;
use App\Http\Controllers\Api\SplendidFarms\Inventory\FixedAssetController;
use Illuminate\Support\Facades\Route;

/*
 * Administración → Activos Fijos. Se incluye UNA vez en el grupo genérico
 * {empresa}/administration de routes/api.php (middleware EmpresaDeLaRuta), así
 * que sirve a cualquier empresa existente; la empresa la resuelve AlcanceActivos
 * desde la URL (segmento 2). Grupo Espléndido es la vista corporativa.
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
    Route::get('activos/resumen', [FixedAssetController::class, 'resumen']);
    Route::apiResource('activos', FixedAssetController::class)
        ->parameters(['activos' => 'asset']);

    // Asignaciones (resguardo) y carta responsiva
    Route::get('asignaciones', [AsignacionActivoController::class, 'index']);
    // Bandeja (antes de las rutas con {asignacion} para que no se tomen como id)
    Route::get('asignaciones/resumen', [BandejaAsignacionesController::class, 'resumen']);
    Route::get('asignaciones/cartas-pendientes', [BandejaAsignacionesController::class, 'cartasPendientes']);
    Route::get('asignaciones/movimientos', [BandejaAsignacionesController::class, 'movimientos']);
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

    // Depreciación (fase 4)
    Route::get('depreciacion', [DepreciacionController::class, 'index']);
    Route::get('depreciacion/cierres', [DepreciacionController::class, 'cierres']);
    Route::post('depreciacion/cierres', [DepreciacionController::class, 'cerrar']);
    Route::delete('depreciacion/cierres/{cierre}', [DepreciacionController::class, 'reabrir']);

    // Bajas, ventas y traspasos (fase 5). `previa` va antes de `{salida}`.
    Route::get('salidas', [SalidaActivoController::class, 'index']);
    Route::get('salidas/previa', [SalidaActivoController::class, 'previa']);
    Route::post('salidas', [SalidaActivoController::class, 'store']);
    Route::get('salidas/{salida}', [SalidaActivoController::class, 'show']);
    Route::delete('salidas/{salida}', [SalidaActivoController::class, 'destroy']);
    Route::get('salidas/{salida}/carta', [SalidaActivoController::class, 'carta']);
    Route::post('salidas/{salida}/carta-firmada', [SalidaActivoController::class, 'subirCartaFirmada']);
    Route::get('salidas/{salida}/carta-firmada', [SalidaActivoController::class, 'descargarCartaFirmada']);
});
