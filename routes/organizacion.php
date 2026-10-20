<?php

use App\Http\Controllers\Api\SplendidFarms\Administration\AreaController;
use App\Http\Controllers\Api\SplendidFarms\Administration\BranchController;
use App\Http\Controllers\Api\SplendidFarms\Administration\EntityController;
use App\Http\Controllers\Api\SplendidFarms\Administration\EntityTypeController;
use Illuminate\Support\Facades\Route;

/*
 * Administración → Organización (sucursales, tipos de entidades, entidades y
 * áreas). Se incluye UNA vez en el grupo genérico {empresa}/administration de
 * routes/api.php: responde para cualquier empresa existente (el menú lo da la
 * BD; Grupo Espléndido no tiene el módulo). Los controladores filtran por el
 * header X-Enterprise-Slug, que el middleware EmpresaDeLaRuta fija siempre al
 * slug de la URL.
 */
Route::prefix('organizacion')->group(function () {
    // Sucursales
    Route::apiResource('sucursales', BranchController::class)
        ->parameters(['sucursales' => 'branch']);
    // Tipos de Entidades
    Route::apiResource('tipos-entidades', EntityTypeController::class)
        ->parameters(['tipos-entidades' => 'entityType']);
    // Entidades externas disponibles para vincular
    Route::get('entidades/disponibles-externas', [EntityController::class, 'externalCandidates']);
    // Vincular / desvincular entidad de otra empresa (sin crear copia)
    Route::post('entidades/vincular', [EntityController::class, 'vincular']);
    Route::delete('entidades/{entity}/desvincular', [EntityController::class, 'desvincular']);
    // Entidades
    Route::apiResource('entidades', EntityController::class)
        ->parameters(['entidades' => 'entity']);
    // Áreas (catálogo)
    Route::apiResource('areas', AreaController::class)
        ->parameters(['areas' => 'area']);
    // Asignación de áreas a entidades
    Route::post('areas/{area}/assign', [AreaController::class, 'assignToEntity']);
    Route::delete('areas/{area}/unassign/{entity}', [AreaController::class, 'unassignFromEntity']);
    Route::put('areas/{area}/assignment/{entity}', [AreaController::class, 'updateAssignment']);
    Route::get('entidades/{entity}/areas', [AreaController::class, 'getByEntity']);
});
