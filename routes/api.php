<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Ruta de autenticación para broadcasting (WebSockets)
// Logging para debug de autenticación de broadcasting
Route::post('broadcasting/auth', function (Request $request) {
    Log::info('Broadcasting Auth Request', [
        'user' => $request->user() ? $request->user()->id : 'No user',
        'channel' => $request->input('channel_name'),
        'socket_id' => $request->input('socket_id'),
        'headers' => $request->headers->all(),
    ]);
    
    return Broadcast::auth($request);
})->middleware('auth:sanctum');

// Rutas de autenticación
Route::prefix('auth')->group(function () {
    Route::post('login', [App\Http\Controllers\Api\AuthController::class, 'login']);
    Route::post('register', [App\Http\Controllers\Api\AuthController::class, 'register']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [App\Http\Controllers\Api\AuthController::class, 'logout']);
        Route::get('user', [App\Http\Controllers\Api\AuthController::class, 'user']);
    });
});

// Rutas protegidas
Route::middleware('auth:sanctum')->group(function () {
    
    // Rutas de perfil del usuario autenticado
    Route::prefix('profile')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\ProfileController::class, 'show']);
        Route::put('/', [App\Http\Controllers\Api\ProfileController::class, 'update']);
        Route::post('/', [App\Http\Controllers\Api\ProfileController::class, 'update']); // Para FormData
        Route::put('/password', [App\Http\Controllers\Api\ProfileController::class, 'changePassword']);
        Route::post('/vacation-request', [App\Http\Controllers\Api\ProfileController::class, 'requestVacation']);
        Route::delete('/vacation-request/{vacationRequest}', [App\Http\Controllers\Api\ProfileController::class, 'cancelVacationRequest']);
        Route::get('/vacation-history', [App\Http\Controllers\Api\ProfileController::class, 'vacationHistory']);
    });
    
    // Rutas de notificaciones del sistema
    Route::prefix('notifications')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\NotificationController::class, 'index']);
        Route::get('/count', [App\Http\Controllers\Api\NotificationController::class, 'count']);
        Route::post('/read-all', [App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead']);
        Route::post('/dismiss-read', [App\Http\Controllers\Api\NotificationController::class, 'dismissAllRead']);
        Route::post('/{id}/read', [App\Http\Controllers\Api\NotificationController::class, 'markAsRead']);
        Route::post('/{id}/dismiss', [App\Http\Controllers\Api\NotificationController::class, 'dismiss']);
    });
    
    // Rutas de administración de usuarios
    Route::apiResource('users', App\Http\Controllers\Api\UserController::class);
    Route::post('users/{user}/enterprises', [App\Http\Controllers\Api\UserController::class, 'assignEnterprises']);
    Route::post('users/{user}/enterprises/{enterprise}/applications', [App\Http\Controllers\Api\UserController::class, 'assignApplications']);
    Route::get('users-employees-available', [App\Http\Controllers\Api\UserController::class, 'employeesWithoutUser']);
    
    // Rutas de empresas
    Route::apiResource('enterprises', App\Http\Controllers\Api\EnterpriseController::class);
    Route::get('enterprises/{enterprise}/applications', [App\Http\Controllers\Api\EnterpriseController::class, 'applications']);
    
    // Rutas de aplicaciones
    Route::prefix('applications')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\ApplicationController::class, 'index']);
        Route::post('/', [App\Http\Controllers\Api\ApplicationController::class, 'store']);
        Route::get('/{application}', [App\Http\Controllers\Api\ApplicationController::class, 'show']);
        Route::put('/{application}', [App\Http\Controllers\Api\ApplicationController::class, 'update']);
        Route::delete('/{application}', [App\Http\Controllers\Api\ApplicationController::class, 'destroy']);
        Route::get('/{application}/modules', [App\Http\Controllers\Api\ModuleController::class, 'byApplication']);
    });

    // Rutas de módulos
    Route::prefix('modules')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\ModuleController::class, 'index']);
        Route::post('/', [App\Http\Controllers\Api\ModuleController::class, 'store']);
        Route::get('/{module}', [App\Http\Controllers\Api\ModuleController::class, 'show']);
        Route::put('/{module}', [App\Http\Controllers\Api\ModuleController::class, 'update']);
        Route::delete('/{module}', [App\Http\Controllers\Api\ModuleController::class, 'destroy']);
        Route::post('/reorder', [App\Http\Controllers\Api\ModuleController::class, 'reorder']);
        Route::get('/{module}/submodules', [App\Http\Controllers\Api\SubmoduleController::class, 'byModule']);
    });

    // Rutas de submódulos
    Route::prefix('submodules')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\SubmoduleController::class, 'index']);
        Route::post('/', [App\Http\Controllers\Api\SubmoduleController::class, 'store']);
        Route::get('/{submodule}', [App\Http\Controllers\Api\SubmoduleController::class, 'show']);
        Route::put('/{submodule}', [App\Http\Controllers\Api\SubmoduleController::class, 'update']);
        Route::delete('/{submodule}', [App\Http\Controllers\Api\SubmoduleController::class, 'destroy']);
        Route::post('/reorder', [App\Http\Controllers\Api\SubmoduleController::class, 'reorder']);
    });

    // Rutas de permisos de usuario (legacy)
    Route::prefix('users/{user}/permissions')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\UserPermissionController::class, 'index']);
        Route::get('/available', [App\Http\Controllers\Api\UserPermissionController::class, 'getAvailablePermissions']);
        Route::post('/bulk', [App\Http\Controllers\Api\UserPermissionController::class, 'assignBulkPermissions']);
        Route::post('/module', [App\Http\Controllers\Api\UserPermissionController::class, 'assignModulePermission']);
        Route::post('/submodule', [App\Http\Controllers\Api\UserPermissionController::class, 'assignSubmodulePermission']);
        Route::delete('/module/{module}', [App\Http\Controllers\Api\UserPermissionController::class, 'revokeModulePermission']);
        Route::delete('/submodule/{submodule}', [App\Http\Controllers\Api\UserPermissionController::class, 'revokeSubmodulePermission']);
    });

    // =====================================================
    // RUTAS DE PERMISOS JERÁRQUICOS
    // Sistema de permisos: Usuario → Empresa → Aplicación → Módulo → Submódulo → Permisos
    // =====================================================
    
    // Obtener jerarquía completa de una empresa
    Route::get('/enterprises/{enterprise}/hierarchy', [App\Http\Controllers\Api\HierarchicalPermissionController::class, 'getEnterpriseHierarchy']);
    
    // Gestión de tipos de permisos de submódulos
    Route::prefix('submodules/{submodule}/permission-types')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\HierarchicalPermissionController::class, 'getSubmodulePermissionTypes']);
        Route::post('/', [App\Http\Controllers\Api\HierarchicalPermissionController::class, 'addPermissionType']);
        Route::post('/defaults', [App\Http\Controllers\Api\HierarchicalPermissionController::class, 'createDefaultPermissions']);
        Route::delete('/{permissionType}', [App\Http\Controllers\Api\HierarchicalPermissionController::class, 'removePermissionType']);
    });

    // Permisos jerárquicos por usuario
    Route::prefix('users/{user}/hierarchical-permissions')->group(function () {
        // Obtener todos los permisos del usuario
        Route::get('/', [App\Http\Controllers\Api\HierarchicalPermissionController::class, 'getUserPermissions']);
        
        // Asignación masiva de permisos
        Route::post('/bulk', [App\Http\Controllers\Api\HierarchicalPermissionController::class, 'bulkAssignPermissions']);
        
        // Acceso a empresas
        Route::post('/enterprise', [App\Http\Controllers\Api\HierarchicalPermissionController::class, 'assignEnterpriseAccess']);
        
        // Acceso a aplicaciones
        Route::post('/application', [App\Http\Controllers\Api\HierarchicalPermissionController::class, 'assignApplicationAccess']);
        
        // Acceso a módulos
        Route::post('/module', [App\Http\Controllers\Api\HierarchicalPermissionController::class, 'assignModuleAccess']);
        
        // Acceso a submódulos
        Route::post('/submodule', [App\Http\Controllers\Api\HierarchicalPermissionController::class, 'assignSubmoduleAccess']);
        
        // Permisos específicos de submódulo
        Route::post('/submodule-permission', [App\Http\Controllers\Api\HierarchicalPermissionController::class, 'assignSubmodulePermission']);
    });
    
    // Rutas específicas de Splendid Farms
    Route::prefix('splendidfarms')->group(function () {
        
        // Gestión Agrícola
        Route::prefix('agricultural')->group(function () {
            Route::get('/crops', [App\Http\Controllers\Api\SplendidFarms\CropController::class, 'index']);
            Route::post('/crops', [App\Http\Controllers\Api\SplendidFarms\CropController::class, 'store']);
            // Más rutas de gestión agrícola...
        });
        
        // Administración (placeholder para rutas futuras)
        Route::prefix('administration-legacy')->group(function () {
            // Rutas de administración legacy...
        });
        
        // Contabilidad
        Route::prefix('accounting')->group(function () {
            // Rutas de contabilidad...
        });
        
        // =====================================================
        // APLICACIÓN ADMINISTRACIÓN - Rutas específicas
        // =====================================================
        Route::prefix('administration')->group(function () {
            
            // Módulo Organización
            Route::prefix('organizacion')->group(function () {
                // Sucursales
                Route::apiResource('sucursales', App\Http\Controllers\Api\SplendidFarms\Administration\BranchController::class)
                    ->parameters(['sucursales' => 'branch']);
                // Tipos de Entidades
                Route::apiResource('tipos-entidades', App\Http\Controllers\Api\SplendidFarms\Administration\EntityTypeController::class)
                    ->parameters(['tipos-entidades' => 'entityType']);
                // Entidades
                Route::apiResource('entidades', App\Http\Controllers\Api\SplendidFarms\Administration\EntityController::class)
                    ->parameters(['entidades' => 'entity']);
                // Áreas (catálogo)
                Route::apiResource('areas', App\Http\Controllers\Api\SplendidFarms\Administration\AreaController::class)
                    ->parameters(['areas' => 'area']);
                // Asignación de áreas a entidades
                Route::post('areas/{area}/assign', [App\Http\Controllers\Api\SplendidFarms\Administration\AreaController::class, 'assignToEntity']);
                Route::delete('areas/{area}/unassign/{entity}', [App\Http\Controllers\Api\SplendidFarms\Administration\AreaController::class, 'unassignFromEntity']);
                Route::put('areas/{area}/assignment/{entity}', [App\Http\Controllers\Api\SplendidFarms\Administration\AreaController::class, 'updateAssignment']);
                Route::get('entidades/{entity}/areas', [App\Http\Controllers\Api\SplendidFarms\Administration\AreaController::class, 'getByEntity']);
            });
            
            // Módulo Catálogos (catálogos generales de la empresa)
            Route::prefix('catalogos')->group(function () {
                // Proveedores
                Route::get('proveedores/list', [App\Http\Controllers\Api\SplendidFarms\Administration\SupplierController::class, 'list']);
                Route::get('proveedores/{supplier}/balance', [App\Http\Controllers\Api\SplendidFarms\Administration\SupplierController::class, 'balance']);
                Route::get('proveedores/{supplier}/contacts', [App\Http\Controllers\Api\SplendidFarms\Administration\SupplierController::class, 'contacts']);
                Route::post('proveedores/{supplier}/contacts', [App\Http\Controllers\Api\SplendidFarms\Administration\SupplierController::class, 'addContact']);
                Route::put('proveedores/{supplier}/contacts/{contact}', [App\Http\Controllers\Api\SplendidFarms\Administration\SupplierController::class, 'updateContact']);
                Route::delete('proveedores/{supplier}/contacts/{contact}', [App\Http\Controllers\Api\SplendidFarms\Administration\SupplierController::class, 'deleteContact']);
                Route::apiResource('proveedores', App\Http\Controllers\Api\SplendidFarms\Administration\SupplierController::class)
                    ->parameters(['proveedores' => 'supplier']);
            });
            
            // Módulo Agrícola
            Route::prefix('agricola')->group(function () {
                // Cultivos
                Route::apiResource('cultivos', App\Http\Controllers\Api\SplendidFarms\CropController::class);
                // Ciclos Agrícolas
                Route::apiResource('ciclos-agricolas', App\Http\Controllers\Api\SplendidFarms\AgricultureCycleController::class);
                // Temporadas
                Route::apiResource('temporadas', App\Http\Controllers\Api\SplendidFarms\TemporadaController::class);
                Route::post('temporadas/{id}/cerrar', [App\Http\Controllers\Api\SplendidFarms\TemporadaController::class, 'cerrar']);
                Route::get('temporadas/{id}/resumen', [App\Http\Controllers\Api\SplendidFarms\TemporadaController::class, 'resumen']);
                
                // Gestión de Productores en Temporadas
                Route::get('temporadas/{id}/productores', [App\Http\Controllers\Api\SplendidFarms\TemporadaController::class, 'getProductores']);
                Route::post('temporadas/{id}/productores', [App\Http\Controllers\Api\SplendidFarms\TemporadaController::class, 'asignarProductor']);
                Route::delete('temporadas/{id}/productores/{productorId}', [App\Http\Controllers\Api\SplendidFarms\TemporadaController::class, 'desasignarProductor']);
                Route::patch('temporadas/{id}/productores/{productorId}', [App\Http\Controllers\Api\SplendidFarms\TemporadaController::class, 'toggleProductor']);
                
                // Gestión de Zonas de Cultivo en Temporadas
                Route::get('temporadas/{id}/zonas-cultivo', [App\Http\Controllers\Api\SplendidFarms\TemporadaController::class, 'getZonasCultivo']);
                Route::post('temporadas/{id}/zonas-cultivo', [App\Http\Controllers\Api\SplendidFarms\TemporadaController::class, 'asignarZonaCultivo']);
                Route::delete('temporadas/{id}/zonas-cultivo/{zonaId}', [App\Http\Controllers\Api\SplendidFarms\TemporadaController::class, 'desasignarZonaCultivo']);
                
                // Gestión de Lotes en Temporadas
                Route::get('temporadas/{id}/lotes', [App\Http\Controllers\Api\SplendidFarms\TemporadaController::class, 'getLotes']);
                Route::post('temporadas/{id}/lotes', [App\Http\Controllers\Api\SplendidFarms\TemporadaController::class, 'asignarLote']);
                Route::delete('temporadas/{id}/lotes/{loteId}', [App\Http\Controllers\Api\SplendidFarms\TemporadaController::class, 'desasignarLote']);
                // Variedades
                Route::apiResource('variedades', App\Http\Controllers\Api\SplendidFarms\VariedadController::class);
                // Tipos de Variedad
                Route::apiResource('tipos-variedad', App\Http\Controllers\Api\SplendidFarms\TipoVariedadController::class);
                // Productores
                Route::apiResource('productores', App\Http\Controllers\Api\SplendidFarms\ProductorController::class)
                    ->parameters(['productores' => 'productor']);
                Route::get('productores-activos', [App\Http\Controllers\Api\SplendidFarms\ProductorController::class, 'activos']);
                Route::get('productores/{productor}/cultivos', [App\Http\Controllers\Api\SplendidFarms\ProductorController::class, 'getCultivos']);
                Route::post('productores/{productor}/cultivos', [App\Http\Controllers\Api\SplendidFarms\ProductorController::class, 'syncCultivos']);
                Route::get('productores/{productor}/lotes', [App\Http\Controllers\Api\SplendidFarms\LoteController::class, 'byProductor']);
                // Zonas de Cultivo
                Route::apiResource('zonas-cultivo', App\Http\Controllers\Api\SplendidFarms\ZonaCultivoController::class);
                // Lotes
                Route::apiResource('lotes', App\Http\Controllers\Api\SplendidFarms\LoteController::class);
                Route::get('lotes/siguiente-numero', [App\Http\Controllers\Api\SplendidFarms\LoteController::class, 'siguienteNumero']);
            });
        });
        
        // =====================================================
        // APLICACIÓN INVENTARIO
        // =====================================================
        Route::prefix('inventario')->group(function () {
            
            // Módulo Catálogos
            Route::prefix('catalogos')->group(function () {
                // Categorías de productos
                Route::get('categorias/tree', [App\Http\Controllers\Api\SplendidFarms\Inventory\ProductCategoryController::class, 'tree']);
                Route::apiResource('categorias', App\Http\Controllers\Api\SplendidFarms\Inventory\ProductCategoryController::class)
                    ->parameters(['categorias' => 'category']);
                
                // Unidades de medida
                Route::get('unidades/convert', [App\Http\Controllers\Api\SplendidFarms\Inventory\UnitOfMeasureController::class, 'convert']);
                Route::apiResource('unidades', App\Http\Controllers\Api\SplendidFarms\Inventory\UnitOfMeasureController::class)
                    ->parameters(['unidades' => 'unit']);
                
                // Artículos/Productos
                Route::get('articulos/{product}/stock', [App\Http\Controllers\Api\SplendidFarms\Inventory\ProductController::class, 'stock']);
                Route::apiResource('articulos', App\Http\Controllers\Api\SplendidFarms\Inventory\ProductController::class)
                    ->parameters(['articulos' => 'product']);
                
                // Tipos de movimiento
                Route::apiResource('tipos-movimiento', App\Http\Controllers\Api\SplendidFarms\Inventory\MovementTypeController::class)
                    ->parameters(['tipos-movimiento' => 'type']);
            });
            
            // Módulo Operaciones (Movimientos)
            Route::prefix('operaciones')->group(function () {
                // Movimientos generales
                Route::apiResource('movimientos', App\Http\Controllers\Api\SplendidFarms\Inventory\InventoryMovementController::class)
                    ->parameters(['movimientos' => 'movement']);
                Route::post('movimientos/{movement}/approve', [App\Http\Controllers\Api\SplendidFarms\Inventory\InventoryMovementController::class, 'approve']);
                Route::post('movimientos/{movement}/cancel', [App\Http\Controllers\Api\SplendidFarms\Inventory\InventoryMovementController::class, 'cancel']);
            });
            
            // Módulo Compras
            Route::prefix('compras')->group(function () {
                // Órdenes de Compra
                Route::post('ordenes/{order}/submit', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseOrderController::class, 'submit']);
                Route::post('ordenes/{order}/approve', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseOrderController::class, 'approve']);
                Route::post('ordenes/{order}/reject', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseOrderController::class, 'reject']);
                Route::post('ordenes/{order}/send', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseOrderController::class, 'send']);
                Route::post('ordenes/{order}/confirm', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseOrderController::class, 'confirm']);
                Route::post('ordenes/{order}/cancel', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseOrderController::class, 'cancel']);
                Route::post('ordenes/{order}/duplicate', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseOrderController::class, 'duplicate']);
                Route::get('ordenes/{order}/pending-items', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseOrderController::class, 'pendingItems']);
                // Detalles de orden
                Route::post('ordenes/{order}/details', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseOrderController::class, 'addDetail']);
                Route::put('ordenes/{order}/details/{detail}', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseOrderController::class, 'updateDetail']);
                Route::delete('ordenes/{order}/details/{detail}', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseOrderController::class, 'deleteDetail']);
                Route::apiResource('ordenes', App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseOrderController::class)
                    ->parameters(['ordenes' => 'order']);
                
                // Recepciones de Mercancía
                Route::post('recepciones/from-order/{order}', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseReceiptController::class, 'fromPurchaseOrder']);
                Route::post('recepciones/{receipt}/submit', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseReceiptController::class, 'submit']);
                Route::post('recepciones/{receipt}/complete', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseReceiptController::class, 'complete']);
                Route::post('recepciones/{receipt}/cancel', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseReceiptController::class, 'cancel']);
                // Detalles de recepción
                Route::post('recepciones/{receipt}/details', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseReceiptController::class, 'addDetail']);
                Route::put('recepciones/{receipt}/details/{detail}', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseReceiptController::class, 'updateDetail']);
                Route::delete('recepciones/{receipt}/details/{detail}', [App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseReceiptController::class, 'deleteDetail']);
                Route::apiResource('recepciones', App\Http\Controllers\Api\SplendidFarms\Inventory\PurchaseReceiptController::class)
                    ->parameters(['recepciones' => 'receipt']);
            });
            
            // Módulo Reportes
            Route::prefix('reportes')->group(function () {
                Route::get('stock', [App\Http\Controllers\Api\SplendidFarms\Inventory\InventoryReportController::class, 'stock']);
                Route::get('movimientos', [App\Http\Controllers\Api\SplendidFarms\Inventory\InventoryReportController::class, 'movements']);
                Route::get('valorizado', [App\Http\Controllers\Api\SplendidFarms\Inventory\InventoryReportController::class, 'valued']);
                Route::get('alertas', [App\Http\Controllers\Api\SplendidFarms\Inventory\InventoryReportController::class, 'alerts']);
                Route::get('kardex/{product}', [App\Http\Controllers\Api\SplendidFarms\Inventory\InventoryReportController::class, 'productKardex']);
            });
        });
        
        // =====================================================
        // APLICACIÓN CONTABILIDAD
        // =====================================================
        Route::prefix('contabilidad')->group(function () {
            
            // Módulo CXP (Cuentas por Pagar)
            Route::prefix('cxp')->group(function () {
                
                // Submódulo Documentos
                Route::prefix('documentos')->group(function () {
                    // Reportes y consultas
                    Route::get('summary', [App\Http\Controllers\Api\SplendidFarms\Accounting\AccountPayableController::class, 'summary']);
                    Route::get('aging', [App\Http\Controllers\Api\SplendidFarms\Accounting\AccountPayableController::class, 'aging']);
                    Route::get('balance-by-supplier', [App\Http\Controllers\Api\SplendidFarms\Accounting\AccountPayableController::class, 'balanceBySupplier']);
                    Route::get('overdue', [App\Http\Controllers\Api\SplendidFarms\Accounting\AccountPayableController::class, 'overdue']);
                    Route::get('due-soon', [App\Http\Controllers\Api\SplendidFarms\Accounting\AccountPayableController::class, 'dueSoon']);
                    
                    // Acciones sobre documentos específicos
                    Route::post('{accountPayable}/cancel', [App\Http\Controllers\Api\SplendidFarms\Accounting\AccountPayableController::class, 'cancel']);
                    
                    // Pagos
                    Route::get('{accountPayable}/payments', [App\Http\Controllers\Api\SplendidFarms\Accounting\AccountPayableController::class, 'payments']);
                    Route::post('{accountPayable}/payments', [App\Http\Controllers\Api\SplendidFarms\Accounting\AccountPayableController::class, 'registerPayment']);
                    Route::post('{accountPayable}/payments/{payment}/cancel', [App\Http\Controllers\Api\SplendidFarms\Accounting\AccountPayableController::class, 'cancelPayment']);
                });
                
                // CRUD de documentos (index, store, show, update, destroy)
                Route::apiResource('documentos', App\Http\Controllers\Api\SplendidFarms\Accounting\AccountPayableController::class)
                    ->parameters(['documentos' => 'accountPayable']);
            });
        });
    });

    // =====================================================
    // RUTAS DE ADMINISTRACIÓN GLOBAL
    // Accesibles solo para usuarios administradores
    // =====================================================
    Route::prefix('admin')->group(function () {
        // Logs de actividad
        Route::get('logs', [App\Http\Controllers\Api\Admin\ActivityLogController::class, 'index']);
        Route::get('logs/stats', [App\Http\Controllers\Api\Admin\ActivityLogController::class, 'stats']);
        Route::get('logs/{id}', [App\Http\Controllers\Api\Admin\ActivityLogController::class, 'show']);
        
        // Horarios de trabajo globales
        Route::apiResource('schedules', App\Http\Controllers\Api\Admin\ScheduleController::class)
            ->parameters(['schedules' => 'schedule']);
        
        // Asignación de horarios a empresas
        Route::post('schedules/{schedule}/assign', [App\Http\Controllers\Api\Admin\ScheduleController::class, 'assignToEnterprise']);
        Route::delete('schedules/{schedule}/unassign/{enterprise}', [App\Http\Controllers\Api\Admin\ScheduleController::class, 'unassignFromEnterprise']);
        Route::get('enterprises/{enterprise}/schedules', [App\Http\Controllers\Api\Admin\ScheduleController::class, 'forEnterprise']);
        Route::get('enterprises/{enterprise}/schedules/available', [App\Http\Controllers\Api\Admin\ScheduleController::class, 'availableForEnterprise']);

        // Configuración de procesos de aprobación
        Route::prefix('approval-processes')->group(function () {
            Route::get('form-data', [App\Http\Controllers\Api\Admin\ApprovalConfigController::class, 'formData']);
            Route::get('/', [App\Http\Controllers\Api\Admin\ApprovalConfigController::class, 'index']);
            Route::post('/', [App\Http\Controllers\Api\Admin\ApprovalConfigController::class, 'store']);
            Route::get('{process}', [App\Http\Controllers\Api\Admin\ApprovalConfigController::class, 'show']);
            Route::put('{process}', [App\Http\Controllers\Api\Admin\ApprovalConfigController::class, 'update']);
            Route::delete('{process}', [App\Http\Controllers\Api\Admin\ApprovalConfigController::class, 'destroy']);
            Route::post('{process}/toggle-approval', [App\Http\Controllers\Api\Admin\ApprovalConfigController::class, 'toggleApproval']);

            // Steps (reglas de aprobación)
            Route::get('{process}/steps', [App\Http\Controllers\Api\Admin\ApprovalConfigController::class, 'getSteps']);
            Route::post('{process}/steps', [App\Http\Controllers\Api\Admin\ApprovalConfigController::class, 'addStep']);
            Route::put('{process}/steps/{step}', [App\Http\Controllers\Api\Admin\ApprovalConfigController::class, 'updateStep']);
            Route::delete('{process}/steps/{step}', [App\Http\Controllers\Api\Admin\ApprovalConfigController::class, 'deleteStep']);
        });
    });
    
    // Rutas específicas de Splendid by Porvenir
    Route::prefix('splendidbyporvenir')->group(function () {
        
        // Ventas
        Route::prefix('sales')->group(function () {
            // Rutas de ventas...
        });
        
        // Exportaciones
        Route::prefix('exports')->group(function () {
            // Rutas de exportaciones...
        });
        
        // Compras
        Route::prefix('purchases')->group(function () {
            // Rutas de compras...
        });
    });

    // =====================================================
    // RUTAS DE GRUPO ESPLÉNDIDO
    // Corporativo central - acceso a todas las empresas
    // =====================================================
    Route::prefix('grupoesplendido')->group(function () {
        
        // =====================================================
        // APLICACIÓN RECURSOS HUMANOS
        // Gestión centralizada de empleados de todas las empresas
        // =====================================================
        Route::prefix('rh')->group(function () {
            
            // Submódulo Departamentos
            Route::get('departamentos/tree', [App\Http\Controllers\Api\GrupoEsplendido\RH\DepartmentController::class, 'tree']);
            Route::prefix('departamentos/{department}')->group(function () {
                Route::get('areas', [App\Http\Controllers\Api\GrupoEsplendido\RH\DepartmentController::class, 'getAreas']);
                Route::get('areas/available', [App\Http\Controllers\Api\GrupoEsplendido\RH\DepartmentController::class, 'availableAreas']);
                Route::post('areas', [App\Http\Controllers\Api\GrupoEsplendido\RH\DepartmentController::class, 'assignArea']);
                Route::put('areas/{area}', [App\Http\Controllers\Api\GrupoEsplendido\RH\DepartmentController::class, 'updateArea']);
                Route::delete('areas/{area}', [App\Http\Controllers\Api\GrupoEsplendido\RH\DepartmentController::class, 'unassignArea']);
            });
            Route::apiResource('departamentos', App\Http\Controllers\Api\GrupoEsplendido\RH\DepartmentController::class)
                ->parameters(['departamentos' => 'department']);
            
            // Submódulo Puestos
            Route::get('puestos/hierarchy-levels', [App\Http\Controllers\Api\GrupoEsplendido\RH\PositionController::class, 'hierarchyLevels']);
            Route::apiResource('puestos', App\Http\Controllers\Api\GrupoEsplendido\RH\PositionController::class)
                ->parameters(['puestos' => 'position']);
            
            // Submódulo Empleados
            Route::prefix('empleados')->group(function () {
                Route::post('{employee}/regenerate-qr', [App\Http\Controllers\Api\GrupoEsplendido\RH\EmployeeController::class, 'regenerateQR']);
                Route::post('{employee}/regenerate-pin', [App\Http\Controllers\Api\GrupoEsplendido\RH\EmployeeController::class, 'regeneratePIN']);
                Route::get('{employee}/credential', [App\Http\Controllers\Api\GrupoEsplendido\RH\EmployeeController::class, 'getCredential']);
                Route::post('{employee}/terminate', [App\Http\Controllers\Api\GrupoEsplendido\RH\EmployeeController::class, 'terminate']);
            });
            Route::apiResource('empleados', App\Http\Controllers\Api\GrupoEsplendido\RH\EmployeeController::class)
                ->parameters(['empleados' => 'employee']);
            
            // Submódulo Horarios
            Route::apiResource('horarios', App\Http\Controllers\Api\GrupoEsplendido\RH\WorkScheduleController::class)
                ->parameters(['horarios' => 'workSchedule']);
            
            // Submódulo Asistencia
            Route::prefix('asistencia')->group(function () {
                Route::get('dashboard', [App\Http\Controllers\Api\GrupoEsplendido\RH\AttendanceController::class, 'todayDashboard']);
                Route::get('reporte', [App\Http\Controllers\Api\GrupoEsplendido\RH\AttendanceController::class, 'report']);
            });
            Route::apiResource('asistencia', App\Http\Controllers\Api\GrupoEsplendido\RH\AttendanceController::class)
                ->parameters(['asistencia' => 'attendance']);

            // Submódulo Vacaciones
            Route::prefix('vacaciones')->group(function () {
                // Rutas de cálculo según Ley Federal del Trabajo México
                Route::get('tabla-lft', [App\Http\Controllers\Api\GrupoEsplendido\RH\VacationController::class, 'getVacationTable']);
                Route::post('calcular-dias', [App\Http\Controllers\Api\GrupoEsplendido\RH\VacationController::class, 'calculateDays']);
                Route::post('inicializar-balances', [App\Http\Controllers\Api\GrupoEsplendido\RH\VacationController::class, 'initializeBalances']);
                Route::get('empleado/{employee}/info', [App\Http\Controllers\Api\GrupoEsplendido\RH\VacationController::class, 'getEmployeeVacationInfo']);
                Route::post('empleado/{employee}/recalcular', [App\Http\Controllers\Api\GrupoEsplendido\RH\VacationController::class, 'recalculateBalance']);
                Route::post('empleado/{employee}/ajuste', [App\Http\Controllers\Api\GrupoEsplendido\RH\VacationController::class, 'applyAdjustment']);
                Route::get('empleado/{employee}/historial', [App\Http\Controllers\Api\GrupoEsplendido\RH\VacationController::class, 'getBalanceHistory']);
                
                // Rutas existentes
                Route::get('balance', [App\Http\Controllers\Api\GrupoEsplendido\RH\VacationController::class, 'getBalance']);
                Route::post('{vacation}/aprobar', [App\Http\Controllers\Api\GrupoEsplendido\RH\VacationController::class, 'approve']);
                Route::post('{vacation}/rechazar', [App\Http\Controllers\Api\GrupoEsplendido\RH\VacationController::class, 'reject']);
                Route::post('{vacation}/cancelar', [App\Http\Controllers\Api\GrupoEsplendido\RH\VacationController::class, 'cancel']);
            });
            Route::apiResource('vacaciones', App\Http\Controllers\Api\GrupoEsplendido\RH\VacationController::class)
                ->parameters(['vacaciones' => 'vacation']);

            // Submódulo Incidencias
            Route::prefix('incidencias')->group(function () {
                Route::get('resumen-empleado', [App\Http\Controllers\Api\GrupoEsplendido\RH\IncidentController::class, 'employeeSummary']);
                Route::post('{incident}/aprobar', [App\Http\Controllers\Api\GrupoEsplendido\RH\IncidentController::class, 'approve']);
                Route::post('{incident}/rechazar', [App\Http\Controllers\Api\GrupoEsplendido\RH\IncidentController::class, 'reject']);
            });
            Route::apiResource('incidencias', App\Http\Controllers\Api\GrupoEsplendido\RH\IncidentController::class)
                ->parameters(['incidencias' => 'incident']);

            // Catálogo de Tipos de Incidencia
            Route::prefix('tipos-incidencia')->group(function () {
                Route::get('categorias', [App\Http\Controllers\Api\GrupoEsplendido\RH\IncidentTypeController::class, 'categories']);
                Route::post('crear-defecto', [App\Http\Controllers\Api\GrupoEsplendido\RH\IncidentTypeController::class, 'createDefaults']);
            });
            Route::apiResource('tipos-incidencia', App\Http\Controllers\Api\GrupoEsplendido\RH\IncidentTypeController::class)
                ->parameters(['tipos-incidencia' => 'incidentType']);
        });
    });
});

// =====================================================
// RUTAS PÚBLICAS - CHECADOR DE ASISTENCIA
// Estas rutas NO requieren autenticación Sanctum
// Son para el kiosco/terminal de checado
// =====================================================
Route::prefix('checador')->group(function () {
    Route::post('qr', [App\Http\Controllers\Api\GrupoEsplendido\RH\TimeClockController::class, 'checkByQR']);
    Route::post('pin', [App\Http\Controllers\Api\GrupoEsplendido\RH\TimeClockController::class, 'checkByPIN']);
    Route::get('status', [App\Http\Controllers\Api\GrupoEsplendido\RH\TimeClockController::class, 'getStatus']);
    Route::get('server-time', [App\Http\Controllers\Api\GrupoEsplendido\RH\TimeClockController::class, 'serverTime']);
    Route::get('today-checks', [App\Http\Controllers\Api\GrupoEsplendido\RH\TimeClockController::class, 'todayChecks']);
});