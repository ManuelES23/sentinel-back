<?php

use App\Http\Controllers\Api\Abastecimiento\AbonoController;
use App\Http\Controllers\Api\Abastecimiento\ConvenioController;
use App\Http\Controllers\Api\Abastecimiento\CultivoController;
use App\Http\Controllers\Api\Abastecimiento\LiquidacionController;
use App\Http\Controllers\Api\Abastecimiento\PermisosController;
use App\Http\Controllers\Api\Abastecimiento\ProductorController;
use App\Http\Controllers\Api\Abastecimiento\RecepcionController;
use App\Http\Controllers\Api\Abastecimiento\TableroController;
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

    // Tablero (submódulo tablero).
    Route::get('tablero', [TableroController::class, 'index'])->name('tablero');
    Route::get('tablero/exportar', [TableroController::class, 'exportar'])->name('tablero.exportar');

    // Cultivos y variedades (submódulo variedades).
    Route::get('cultivos', [CultivoController::class, 'index'])->name('cultivos.index');
    Route::post('cultivos', [CultivoController::class, 'store'])->name('cultivos.store');
    Route::put('cultivos/{id}', [CultivoController::class, 'update'])->whereNumber('id')->name('cultivos.update');
    Route::delete('cultivos/{id}', [CultivoController::class, 'destroy'])->whereNumber('id')->name('cultivos.destroy');
    Route::post('cultivos/{cultivoId}/variedades', [CultivoController::class, 'storeVariedad'])->whereNumber('cultivoId')->name('cultivos.variedades.store');
    Route::put('variedades/{id}', [CultivoController::class, 'updateVariedad'])->whereNumber('id')->name('variedades.update');
    Route::delete('variedades/{id}', [CultivoController::class, 'destroyVariedad'])->whereNumber('id')->name('variedades.destroy');
    Route::post('variedades/{variedadId}/calibres', [CultivoController::class, 'storeCalibre'])->whereNumber('variedadId')->name('cultivos.variedades.calibres.store');
    Route::put('calibres/{id}', [CultivoController::class, 'updateCalibre'])->whereNumber('id')->name('calibres.update');
    Route::delete('calibres/{id}', [CultivoController::class, 'destroyCalibre'])->whereNumber('id')->name('calibres.destroy');

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
    // Importación desde Excel (permiso `recepciones.importar`). Los segmentos fijos van antes de las rutas con {id}.
    Route::get('recepciones/plantilla', [RecepcionController::class, 'plantilla'])->name('recepciones.plantilla');
    Route::post('recepciones/importar/vista-previa', [RecepcionController::class, 'vistaPreviaImportacion'])->name('recepciones.importar.vista-previa');
    Route::post('recepciones/importar', [RecepcionController::class, 'importar'])->name('recepciones.importar');
    Route::get('recepciones', [RecepcionController::class, 'index'])->name('recepciones.index');
    Route::post('recepciones', [RecepcionController::class, 'store'])->name('recepciones.store');
    Route::put('recepciones/{id}', [RecepcionController::class, 'update'])->whereNumber('id')->name('recepciones.update');
    Route::delete('recepciones/{id}', [RecepcionController::class, 'destroy'])->whereNumber('id')->name('recepciones.destroy');

    // Liquidaciones (submódulo liquidaciones). `recepciones-disponibles` y `previa` van antes de las rutas con {id}.
    Route::get('liquidaciones/recepciones-disponibles', [LiquidacionController::class, 'recepcionesDisponibles'])->name('liquidaciones.recepciones-disponibles');
    Route::post('liquidaciones/previa', [LiquidacionController::class, 'previa'])->name('liquidaciones.previa');
    // Carga inicial (permiso `liquidaciones.carga-inicial`): nace aprobada, sin cadena. Fija, antes de las rutas con {id}.
    Route::post('liquidaciones/carga-inicial', [LiquidacionController::class, 'cargaInicial'])->name('liquidaciones.carga-inicial');
    Route::get('liquidaciones', [LiquidacionController::class, 'index'])->name('liquidaciones.index');
    Route::get('liquidaciones/{id}', [LiquidacionController::class, 'show'])->whereNumber('id')->name('liquidaciones.show');
    Route::post('liquidaciones', [LiquidacionController::class, 'store'])->name('liquidaciones.store');
    Route::put('liquidaciones/{id}', [LiquidacionController::class, 'update'])->whereNumber('id')->name('liquidaciones.update');
    Route::delete('liquidaciones/{id}', [LiquidacionController::class, 'destroy'])->whereNumber('id')->name('liquidaciones.destroy');
    Route::post('liquidaciones/{id}/enviar', [LiquidacionController::class, 'enviar'])->whereNumber('id')->name('liquidaciones.enviar');
    Route::post('liquidaciones/{id}/aprobar', [LiquidacionController::class, 'aprobar'])->whereNumber('id')->name('liquidaciones.aprobar');
    Route::post('liquidaciones/{id}/rechazar', [LiquidacionController::class, 'rechazar'])->whereNumber('id')->name('liquidaciones.rechazar');
    Route::post('liquidaciones/{id}/cancelar', [LiquidacionController::class, 'cancelar'])->whereNumber('id')->name('liquidaciones.cancelar');

    // Abonos y anticipos (submódulo abonos). Los segmentos fijos van antes de las rutas con {id}.
    Route::get('abonos/productores', [AbonoController::class, 'productores'])->name('abonos.productores');
    Route::get('abonos/productores/{id}/estado-cuenta', [AbonoController::class, 'estadoCuenta'])->whereNumber('id')->name('abonos.estado-cuenta');
    Route::get('abonos/liquidaciones-pendientes', [AbonoController::class, 'liquidacionesPendientes'])->name('abonos.liquidaciones-pendientes');
    Route::get('abonos', [AbonoController::class, 'index'])->name('abonos.index');
    Route::post('abonos', [AbonoController::class, 'store'])->name('abonos.store');
    Route::post('abonos/{id}/cancelar', [AbonoController::class, 'cancelar'])->whereNumber('id')->name('abonos.cancelar');
});
