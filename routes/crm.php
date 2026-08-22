<?php

use Illuminate\Support\Facades\Route;

// =====================================================
// RUTAS CRM — CRM Comercial
// Prefijo: /api/crm
// Middleware: auth:sanctum
// =====================================================
Route::middleware('auth:sanctum')->prefix('crm')->group(function () {

    // -------------------------------------------------
    // CATÁLOGOS
    // vendedores, regiones, zonas, bodegas, productos
    // -------------------------------------------------
    // Vendedores (+ usuarios disponibles + toggle activo)
    Route::get('vendedores/usuarios-disponibles', [
        App\Http\Controllers\Api\CRM\VendedorController::class, 'usuariosDisponibles'
    ]);
    Route::patch('vendedores/{vendedor}/toggle-activo', [
        App\Http\Controllers\Api\CRM\VendedorController::class, 'toggleActivo'
    ]);
    Route::apiResource('vendedores', App\Http\Controllers\Api\CRM\VendedorController::class)
        ->parameters(['vendedores' => 'vendedor']);

    // Regiones → Zonas → Bodegas (jerarquía geográfica)
    Route::apiResource('regiones', App\Http\Controllers\Api\CRM\RegionController::class)
        ->parameters(['regiones' => 'region']);
    Route::apiResource('zonas', App\Http\Controllers\Api\CRM\ZonaController::class)
        ->parameters(['zonas' => 'zona']);
    Route::apiResource('bodegas', App\Http\Controllers\Api\CRM\BodegaController::class)
        ->parameters(['bodegas' => 'bodega']);

    // Productos (+ typeahead + toggle activo)
    Route::get('productos/buscar', [
        App\Http\Controllers\Api\CRM\ProductoController::class, 'buscar'
    ]);
    Route::patch('productos/{producto}/toggle-activo', [
        App\Http\Controllers\Api\CRM\ProductoController::class, 'toggleActivo'
    ]);
    Route::apiResource('productos', App\Http\Controllers\Api\CRM\ProductoController::class)
        ->parameters(['productos' => 'producto']);

    // Configuración comercial (descuento global + impuestos), un registro por empresa
    Route::get('configuracion-comercial', [
        App\Http\Controllers\Api\CRM\ConfiguracionComercialController::class, 'show'
    ]);
    Route::put('configuracion-comercial', [
        App\Http\Controllers\Api\CRM\ConfiguracionComercialController::class, 'update'
    ]);
    Route::post('configuracion-comercial/impuestos', [
        App\Http\Controllers\Api\CRM\ConfiguracionComercialController::class, 'storeImpuesto'
    ]);
    Route::put('configuracion-comercial/impuestos/{impuesto}', [
        App\Http\Controllers\Api\CRM\ConfiguracionComercialController::class, 'updateImpuesto'
    ]);
    Route::delete('configuracion-comercial/impuestos/{impuesto}', [
        App\Http\Controllers\Api\CRM\ConfiguracionComercialController::class, 'destroyImpuesto'
    ]);

    // -------------------------------------------------
    // PROSPECTOS
    // CRUD + convertir-cliente + asignar-vendedor
    // -------------------------------------------------
    Route::post('prospectos/{prospecto}/convertir-cliente', [
        App\Http\Controllers\Api\CRM\ProspectoController::class, 'convertirCliente'
    ]);
    Route::patch('prospectos/{prospecto}/asignar-vendedor', [
        App\Http\Controllers\Api\CRM\ProspectoController::class, 'asignarVendedor'
    ]);
    Route::apiResource('prospectos', App\Http\Controllers\Api\CRM\ProspectoController::class)
        ->parameters(['prospectos' => 'prospecto']);

    // -------------------------------------------------
    // CLIENTES
    // CRUD + resumen + asignar-vendedor
    // -------------------------------------------------
    Route::get('clientes/{cliente}/resumen', [
        App\Http\Controllers\Api\CRM\ClienteController::class, 'resumen'
    ]);
    Route::patch('clientes/{cliente}/asignar-vendedor', [
        App\Http\Controllers\Api\CRM\ClienteController::class, 'asignarVendedor'
    ]);
    Route::apiResource('clientes', App\Http\Controllers\Api\CRM\ClienteController::class)
        ->parameters(['clientes' => 'cliente']);

    // -------------------------------------------------
    // EMPRESAS EXTERNAS Y CONTACTOS
    // Contactos polimórficos: prospecto / cliente / empresa_externa
    // -------------------------------------------------
    Route::apiResource('empresas-externas', App\Http\Controllers\Api\CRM\EmpresaExternaController::class)
        ->parameters(['empresas-externas' => 'empresaExterna']);
    Route::apiResource('contactos', App\Http\Controllers\Api\CRM\ContactoController::class)
        ->parameters(['contactos' => 'contacto']);

    // -------------------------------------------------
    // ACTIVIDADES
    // CRUD polimórfico + timeline por entidad
    // (prospecto / cliente / oportunidad / empresa_externa)
    // -------------------------------------------------
    Route::apiResource('actividades', App\Http\Controllers\Api\CRM\ActividadController::class)
        ->parameters(['actividades' => 'actividad']);

    // Historial de actividades de UNA entidad puntual (paginado a 15, desc).
    // entidad_type: alias corto (prospecto|cliente|oportunidad|empresa_externa).
    Route::get('{entidad_type}/{entidad_id}/historial', [
        App\Http\Controllers\Api\CRM\ActividadController::class, 'historial'
    ])->whereNumber('entidad_id');

    // -------------------------------------------------
    // OPORTUNIDADES
    // CRUD (cambio de etapa se agrega en la Tarea 6)
    // -------------------------------------------------
    Route::apiResource('oportunidades', App\Http\Controllers\Api\CRM\OportunidadController::class)
        ->parameters(['oportunidades' => 'oportunidad']);

    // -------------------------------------------------
    // COTIZACIONES
    // Anidadas a una Oportunidad — CRUD + enviar/aprobar/rechazar
    // -------------------------------------------------
    Route::get('oportunidades/{oportunidad}/cotizaciones', [
        App\Http\Controllers\Api\CRM\CotizacionController::class, 'index'
    ]);
    Route::post('oportunidades/{oportunidad}/cotizaciones', [
        App\Http\Controllers\Api\CRM\CotizacionController::class, 'store'
    ]);
    Route::get('cotizaciones/{cotizacion}', [
        App\Http\Controllers\Api\CRM\CotizacionController::class, 'show'
    ]);
    Route::put('cotizaciones/{cotizacion}', [
        App\Http\Controllers\Api\CRM\CotizacionController::class, 'update'
    ]);
    Route::patch('cotizaciones/{cotizacion}/enviar', [
        App\Http\Controllers\Api\CRM\CotizacionController::class, 'enviar'
    ]);
    Route::patch('cotizaciones/{cotizacion}/aprobar', [
        App\Http\Controllers\Api\CRM\CotizacionController::class, 'aprobar'
    ]);
    Route::patch('cotizaciones/{cotizacion}/rechazar', [
        App\Http\Controllers\Api\CRM\CotizacionController::class, 'rechazar'
    ]);

    // -------------------------------------------------
    // PRESUPUESTOS
    // CRUD + resumen meta vs real + comparativo anual
    // -------------------------------------------------


    // -------------------------------------------------
    // AGENDA
    // CRUD
    // -------------------------------------------------


    // -------------------------------------------------
    // DASHBOARD
    // 7 endpoints de métricas ejecutivas
    // -------------------------------------------------


    // -------------------------------------------------
    // INTEGRACIONES
    // Dialpad sync manual
    // -------------------------------------------------

});
