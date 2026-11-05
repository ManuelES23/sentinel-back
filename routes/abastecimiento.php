<?php

use App\Http\Controllers\Api\Abastecimiento\PermisosController;
use Illuminate\Support\Facades\Route;

/*
 * Administración → Abastecimiento (Splendid by Porvenir). Se incluye UNA vez en
 * el grupo genérico {empresa}/administration de routes/api.php (middleware
 * EmpresaDeLaRuta); la empresa la resuelve ContextoAbastecimiento desde la URL.
 * Los nombres llevan el prefijo `abastecimiento.` para no chocar con otros módulos.
 */
Route::prefix('abastecimiento')->name('abastecimiento.')->group(function () {
    Route::get('mis-permisos', [PermisosController::class, 'index'])->name('mis-permisos');
});
