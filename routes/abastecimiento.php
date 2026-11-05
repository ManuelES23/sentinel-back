<?php

use App\Http\Controllers\Api\Abastecimiento\ConvenioController;
use App\Http\Controllers\Api\Abastecimiento\CultivoController;
use App\Http\Controllers\Api\Abastecimiento\PermisosController;
use App\Http\Controllers\Api\Abastecimiento\ProductorController;
use App\Http\Controllers\Api\Abastecimiento\RecepcionController;
use App\Http\Controllers\Api\Abastecimiento\TemporadaController;
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

    // Productores y empaques (submódulo productores).
    Route::get('productores', [ProductorController::class, 'index'])->name('productores.index');
    Route::get('productores/list', [ProductorController::class, 'list'])->name('productores.list');
    Route::get('productores/posibles-duplicados', [ProductorController::class, 'posiblesDuplicados'])->name('productores.posibles-duplicados');
    Route::post('productores', [ProductorController::class, 'store'])->name('productores.store');
    Route::put('productores/{id}', [ProductorController::class, 'update'])->whereNumber('id')->name('productores.update');
    Route::delete('productores/{id}', [ProductorController::class, 'destroy'])->whereNumber('id')->name('productores.destroy');

    // Temporadas (submódulo temporadas).
    Route::get('temporadas', [TemporadaController::class, 'index'])->name('temporadas.index');
    Route::post('temporadas', [TemporadaController::class, 'store'])->name('temporadas.store');
    Route::put('temporadas/{id}', [TemporadaController::class, 'update'])->whereNumber('id')->name('temporadas.update');
    Route::delete('temporadas/{id}', [TemporadaController::class, 'destroy'])->whereNumber('id')->name('temporadas.destroy');

    // Convenios y precios (submódulo convenios). `encimados` va antes de cualquier ruta con {id}.
    Route::get('convenios/encimados', [ConvenioController::class, 'encimados'])->name('convenios.encimados');
    Route::get('convenios', [ConvenioController::class, 'index'])->name('convenios.index');
    Route::get('convenios/{id}', [ConvenioController::class, 'show'])->whereNumber('id')->name('convenios.show');
    Route::post('convenios', [ConvenioController::class, 'store'])->name('convenios.store');
    Route::put('convenios/{id}', [ConvenioController::class, 'update'])->whereNumber('id')->name('convenios.update');
    Route::patch('convenios/{id}/estado', [ConvenioController::class, 'estado'])->whereNumber('id')->name('convenios.estado');

    // Recepciones (submódulo recepciones). `semana` va antes de cualquier ruta con {id}.
    Route::get('recepciones/semana', [RecepcionController::class, 'semana'])->name('recepciones.semana');
    Route::get('recepciones', [RecepcionController::class, 'index'])->name('recepciones.index');
    Route::post('recepciones', [RecepcionController::class, 'store'])->name('recepciones.store');
    Route::put('recepciones/{id}', [RecepcionController::class, 'update'])->whereNumber('id')->name('recepciones.update');
    Route::delete('recepciones/{id}', [RecepcionController::class, 'destroy'])->whereNumber('id')->name('recepciones.destroy');
});
