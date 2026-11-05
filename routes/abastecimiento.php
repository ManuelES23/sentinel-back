<?php

use App\Http\Controllers\Api\Abastecimiento\CultivoController;
use App\Http\Controllers\Api\Abastecimiento\PermisosController;
use App\Http\Controllers\Api\Abastecimiento\TipoCargaController;
use Illuminate\Support\Facades\Route;

/*
 * Administración → Abastecimiento (Splendid by Porvenir). Se incluye UNA vez en
 * el grupo genérico {empresa}/administration de routes/api.php (middleware
 * EmpresaDeLaRuta); la empresa la resuelve ContextoAbastecimiento desde la URL.
 * Los nombres llevan el prefijo `abastecimiento.` para no chocar con otros módulos.
 */
Route::prefix('abastecimiento')->name('abastecimiento.')->group(function () {
    Route::get('mis-permisos', [PermisosController::class, 'index'])->name('mis-permisos');

    // Cultivos y variedades (submódulo variedades).
    Route::get('cultivos', [CultivoController::class, 'index'])->name('cultivos.index');
    Route::post('cultivos', [CultivoController::class, 'store'])->name('cultivos.store');
    Route::put('cultivos/{id}', [CultivoController::class, 'update'])->whereNumber('id')->name('cultivos.update');
    Route::delete('cultivos/{id}', [CultivoController::class, 'destroy'])->whereNumber('id')->name('cultivos.destroy');
    Route::post('cultivos/{cultivoId}/variedades', [CultivoController::class, 'storeVariedad'])->whereNumber('cultivoId')->name('cultivos.variedades.store');
    Route::put('variedades/{id}', [CultivoController::class, 'updateVariedad'])->whereNumber('id')->name('variedades.update');
    Route::delete('variedades/{id}', [CultivoController::class, 'destroyVariedad'])->whereNumber('id')->name('variedades.destroy');

    // Tipos de carga (submódulo tipos-carga).
    Route::get('tipos-carga', [TipoCargaController::class, 'index'])->name('tipos-carga.index');
    Route::post('tipos-carga', [TipoCargaController::class, 'store'])->name('tipos-carga.store');
    Route::put('tipos-carga/{id}', [TipoCargaController::class, 'update'])->whereNumber('id')->name('tipos-carga.update');
    Route::delete('tipos-carga/{id}', [TipoCargaController::class, 'destroy'])->whereNumber('id')->name('tipos-carga.destroy');
});
