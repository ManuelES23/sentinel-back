# SENTINEL 3.0 Backend - Copilot Instructions

## Arquitectura General

API REST multi-tenant con Laravel 12 + Sanctum. Jerarquía: **Empresa → Aplicación → Módulo → Submódulo**.

| Componente | Tecnología              |
| ---------- | ----------------------- |
| Framework  | Laravel 12 (PHP ^8.2)   |
| Auth       | Sanctum (tokens Bearer) |
| DB         | MySQL                   |
| WebSockets | Laravel Reverb          |
| Logs       | Pail (tiempo real)      |

### Estadísticas del Proyecto

| Recurso     | Cantidad |
| ----------- | -------- |
| Controllers | 48       |
| Modelos     | 87       |
| Events      | 13       |
| Servicios   | 4        |

## Estructura de Controllers

```
app/Http/Controllers/Api/
├── AuthController.php                    # Login, logout, registro
├── ProfileController.php                 # Perfil usuario + vacaciones
├── PendingApprovalController.php         # Pendientes por aprobar del usuario
├── NotificationController.php            # Notificaciones del sistema
├── EnterpriseController.php              # CRUD empresas
├── ApplicationController.php             # CRUD aplicaciones
├── ModuleController.php                  # CRUD módulos
├── SubmoduleController.php               # CRUD submódulos
├── HierarchicalPermissionController.php  # Permisos jerárquicos
├── UserController.php                    # CRUD usuarios + asignaciones
├── UserPermissionController.php          # Permisos legacy
├── Admin/
│   ├── ActivityLogController.php         # Logs de actividad
│   ├── ScheduleController.php            # Horarios globales (CRUD + asignación)
│   └── ApprovalConfigController.php      # Configuración de procesos de aprobación
├── GrupoEsplendido/
│   └── RH/
│       ├── DepartmentController.php      # Departamentos (jerárquico)
│       ├── PositionController.php        # Puestos
│       ├── EmployeeController.php        # Empleados (QR, PIN, credencial)
│       ├── WorkScheduleController.php    # Horarios por empresa
│       ├── AttendanceController.php      # Asistencia + dashboard
│       ├── TimeClockController.php       # Checador público (QR/PIN)
│       ├── VacationController.php        # Vacaciones LFT México
│       ├── IncidentController.php        # Incidencias
│       └── IncidentTypeController.php    # Tipos de incidencia
└── SplendidFarms/
    ├── CropController.php                # Cultivos
    ├── AgricultureCycleController.php    # Ciclos agrícolas
    ├── TemporadaController.php           # Temporadas
    ├── VariedadController.php            # Variedades
    ├── TipoVariedadController.php        # Tipos de variedad
    ├── ProductorController.php           # Productores
    ├── ZonaCultivoController.php         # Zonas de cultivo
    ├── LoteController.php                # Lotes
    ├── Administration/
    │   ├── BranchController.php          # Sucursales
    │   ├── EntityTypeController.php      # Tipos de entidad
    │   ├── EntityController.php          # Entidades
    │   ├── AreaController.php            # Áreas
    │   └── SupplierController.php        # Proveedores
    ├── Inventory/
    │   ├── BrandController.php           # Catálogo de marcas (CRUD + list)
    │   ├── ProductCategoryController.php # Categorías (tree jerárquico)
    │   ├── UnitOfMeasureController.php   # Unidades de medida
    │   ├── ProductController.php         # Artículos (brand_id FK, FormData img)
    │   ├── MovementTypeController.php    # Tipos de movimiento
    │   ├── InventoryMovementController.php # Movimientos de inventario
    │   ├── PurchaseOrderController.php   # Órdenes de compra (flujo estados)
    │   ├── PurchaseReceiptController.php # Recepciones de compra
    │   ├── InventoryReportController.php # Reportes (stock, movimientos, valorizado)
    │   ├── RecipeController.php          # Recetas / BOM (Bill of Materials)
    │   └── TipoCargaController.php       # Tipos de carga por cultivo
    ├── Accounting/
    │   └── AccountPayableController.php  # Cuentas por pagar
    └── OperacionAgricola/
        ├── TemporadaOAController.php     # Temporadas OA
        ├── CatalogoOAController.php      # Catálogos OA
        ├── CosteoAgricolaController.php  # Costeo agrícola
        ├── DiagnosticoIAController.php   # Diagnóstico IA
        ├── EtapaController.php           # Etapas
        ├── EtapaFenologicaController.php # Etapas fenológicas
        ├── PlagaController.php           # Plagas
        ├── VisitaCampoController.php     # Visitas de campo (fotos, plagas, recom.)
        ├── RequisicionCampoController.php # Requisiciones de insumos
        ├── ProductorSimpleController.php # Productores (vista simplificada OA)
        ├── ZonaCultivoSimpleController.php
        ├── LoteSimpleController.php
        ├── Cosecha/
        │   ├── SalidaCampoCosechaController.php  # Salidas de cosecha
        │   ├── CierreCosechaController.php       # Cierres de cosecha
        │   ├── VentaCosechaController.php        # Ventas de cosecha
        │   └── CalidadCosechaController.php      # Calidad de cosecha
        └── Empaque/                               # ⭐ Módulo Empaque (7 fases)
            ├── RecepcionEmpaqueController.php     # Recepciones de cosecha
            ├── ProcesoEmpaqueController.php       # Proceso (piso)
            ├── ProduccionEmpaqueController.php    # Producción (cajas/pallets)
            ├── RezagaEmpaqueController.php        # Rezaga (mermas)
            ├── EmbarqueEmpaqueController.php      # Embarques + detalles
            ├── VentaRezagaEmpaqueController.php   # Venta de rezaga + detalles
            └── CalidadEmpaqueController.php       # Calidad
```

## Servicios

### NotificationService - Sistema de Notificaciones

```php
use App\Services\NotificationService;

// Crear notificación con fluent API
NotificationService::create()
    ->toUser($user)                    // A usuario específico
    ->toEnterprise($enterpriseId)      // A usuarios de empresa
    ->toRole('admin')                  // A usuarios con rol
    ->toAll()                          // A todos
    ->title('Título')
    ->message('Mensaje')
    ->type('info|success|warning|error')
    ->withAction('Ver detalles', '/ruta')
    ->urgent()                         // Prioridad alta
    ->expiresIn(7)                     // Expira en 7 días
    ->send();

// Helpers rápidos
NotificationService::vacation($user, 'Tu solicitud fue aprobada', '/profile');
NotificationService::alert($user, 'Alerta importante');
```

### Otros Servicios

```php
// ApprovalNotificationService - Notificaciones de procesos de aprobación
// VacationCalculatorService - Cálculo vacaciones LFT México
// DiagnosticoIAService - Servicio de diagnóstico con IA
```

## Modelos Principales

### Modelos del Sistema Base (7)

```
User, Enterprise, Application, Module, Submodule, SystemNotification, ActivityLog
```

### Permisos Jerárquicos (10)

```
UserEnterprise, UserEnterpriseAccess, UserApplication, UserApplicationAccess,
UserModuleAccess, UserSubmoduleAccess, UserSubmodulePermission,
SubmodulePermissionType, ApprovalProcess, ApprovalFlowStep
```

### Módulo RH - Grupo Espléndido (10)

```
Department, Position, Employee, AttendanceRecord, VacationRequest,
VacationBalance, WorkSchedule, EmployeeIncident, IncidentType
```

### Administración Splendid Farms (6)

```
Branch, EntityType, Entity, Area, Supplier, SupplierContact
```

### Agrícola Splendid Farms (15+)

```
Cultivo, CicloAgricola, Temporada, Variedad, TipoVariedad,
Productor, ZonaCultivo, Lote, Etapa, EtapaFenologica, Plaga,
VisitaCampo, VisitaCampoDetalle, VisitaCampoFoto, VisitaCampoPlaga,
VisitaCampoRecomendacion, RequisicionCampo, RequisicionCampoDetalle,
CosteoAgricola, DiagnosticoIA
```

### Cosecha Splendid Farms (4)

```
SalidaCampoCosecha, CierreCosecha, VentaCosecha, CalidadCosecha
```

### Empaque Splendid Farms (8+)

```
RecepcionEmpaque, ProcesoEmpaque, ProduccionEmpaque, RezagaEmpaque,
EmbarqueEmpaque, EmbarqueEmpaqueDetalle, VentaRezagaEmpaque,
VentaRezagaEmpaqueDetalle, CalidadEmpaque
```

### Inventario Splendid Farms (18)

```
Brand, ProductCategory, Product, UnitOfMeasure,
InventoryMovement, InventoryMovementDetail, InventoryMovementType,
InventoryItem, InventoryStock, InventoryKardex,
PurchaseOrder, PurchaseOrderDetail, PurchaseReceipt, PurchaseReceiptDetail,
Recipe, RecipeItem, MovementType, TipoCarga
```

### Contabilidad Splendid Farms (2)

```
AccountPayable, AccountPayablePayment
```

### Notificaciones

```php
// SystemNotification.php
SystemNotification::active()           // No expiradas
    ->forUser($userId)                 // Del usuario
    ->unreadBy($userId)               // No leídas por usuario
    ->notDismissedBy($userId)         // No descartadas
    ->ordered()                        // Por prioridad y fecha
    ->get();
```

### Vacaciones (LFT México)

```php
// Vacation.php - Solicitudes de vacaciones
Vacation::pending()->approved()->forEmployee($employeeId)->inDateRange($start, $end)->get();

// VacationBalance.php - Saldos acumulados
$balance->total_days;      // Días totales según antigüedad
$balance->used_days;       // Días usados
$balance->available_days;  // Calculado: total - used - pending
```

### Marcas (Catálogo)

```php
// Brand.php - Catálogo de marcas
Brand::active()->get();      // Marcas activas
$brand->products;            // Productos con esta marca
$brand->code;                // Auto-generado: MRC-001
// Usa: HasFactory, Loggable, SoftDeletes
// Fillable: code, name, is_active
```

### Productos

```php
// Product.php - Artículos
$product->category;     // BelongsTo ProductCategory
$product->unit;         // BelongsTo UnitOfMeasure
$product->brand;        // BelongsTo Brand (nullable)
$product->stock;        // HasMany InventoryStock
$product->kardex;       // HasMany InventoryKardex
// brand_id es FK nullable (nullOnDelete)
// Soporta: imagen (storage), is_for_sale, track_inventory/lots/serials/expiry
```

### Procesos de Aprobación

```php
// ApprovalProcess.php - Catálogo de procesos
ApprovalProcess::active()->requiresApproval()->byCode('vacation_requests')->get();
// Códigos: VACATION_REQUESTS, PURCHASE_ORDERS, INCIDENTS, INVENTORY_MOVEMENTS

$process->canBeApprovedBy($employee, $enterpriseId); // bool
$process->getApprovers($enterpriseId);               // position_ids
```

## Patrones Críticos

### 1. Rutas por Empresa/Aplicación

```php
// Rutas globales (autenticadas)
Route::prefix('profile')->group(...);
Route::prefix('pending-approvals')->group(...);
Route::prefix('notifications')->group(...);

// Rutas RH - Grupo Espléndido
Route::prefix('grupoesplendido/rh')->group(function () {
    Route::apiResource('departamentos', DepartmentController::class);
    Route::apiResource('puestos', PositionController::class);
    Route::apiResource('empleados', EmployeeController::class);
    Route::apiResource('horarios', WorkScheduleController::class);
    Route::apiResource('asistencia', AttendanceController::class);
    Route::apiResource('vacaciones', VacationController::class);
    Route::apiResource('incidencias', IncidentController::class);
    Route::apiResource('tipos-incidencia', IncidentTypeController::class);
});

// Rutas Splendid Farms
Route::prefix('splendidfarms')->group(function () {
    // Administración
    Route::prefix('administration')->group(function () {
        Route::prefix('organizacion')->group(...);  // sucursales, entidades, áreas
        Route::prefix('agricola')->group(...);       // cultivos, temporadas, productores, lotes
        Route::prefix('catalogos')->group(...);      // proveedores
    });

    // Inventario
    Route::prefix('inventario')->group(function () {
        Route::prefix('catalogos')->group(function () {
            Route::apiResource('categorias', ProductCategoryController::class);
            Route::get('marcas/list', [BrandController::class, 'list']);
            Route::apiResource('marcas', BrandController::class);
            Route::apiResource('articulos', ProductController::class);
            Route::apiResource('recetas', RecipeController::class);
            Route::apiResource('tipos-carga', TipoCargaController::class);
            Route::apiResource('tipos-movimiento', MovementTypeController::class);
        });
        Route::prefix('operaciones')->group(...);   // movimientos
        Route::prefix('compras')->group(...);        // OC, recepciones
        Route::prefix('reportes')->group(...);       // stock, movimientos, valorizado
    });

    // Contabilidad
    Route::prefix('contabilidad')->group(...);       // cuentas-por-pagar

    // Operación Agrícola
    Route::prefix('operacion-agricola')->group(function () {
        Route::prefix('agricola')->group(...);       // productores, zonas, lotes, etapas, visitas, requisiciones
        Route::prefix('cosecha')->group(...);        // salidas, cierres, ventas, calidad
        Route::prefix('empaque')->group(function () { // ⭐ Módulo empaque
            Route::apiResource('recepciones', RecepcionEmpaqueController::class);
            Route::apiResource('proceso', ProcesoEmpaqueController::class);
            Route::apiResource('produccion', ProduccionEmpaqueController::class);
            Route::apiResource('rezaga', RezagaEmpaqueController::class);
            Route::apiResource('embarques', EmbarqueEmpaqueController::class);
            Route::apiResource('venta-rezaga', VentaRezagaEmpaqueController::class);
            Route::apiResource('calidad', CalidadEmpaqueController::class);
        });
    });
});

// Checador público (SIN AUTH)
Route::prefix('checador')->group(...);  // qr, pin, status, server-time
```

### 2. Headers de Contexto (Obligatorios para logging)

```php
$request->header('X-Enterprise-Slug');    // 'splendidfarms'
$request->header('X-Application-Slug');   // 'inventario'
$request->header('X-Module-Slug');        // 'catalogos'
$request->header('X-Submodule-Slug');     // 'articulos'
```

### 3. Trait Loggable para Auditoría

```php
use App\Traits\Loggable;

class Employee extends Model
{
    use HasFactory, Loggable;
}
// Todos los modelos importantes usan Loggable para auditoría automática
```

### 4. Formato de Respuesta JSON

```php
// Éxito
return response()->json([
    'success' => true,
    'message' => 'Operación exitosa',
    'data' => $resource
], 200);

// Error
return response()->json([
    'status' => 'error',
    'message' => 'Descripción del error'
], 404);
```

### 5. Auto-generación de Códigos

Patrón usado en BrandController, ProductController, etc.:

```php
// Generar código único incremental (incluyendo soft-deleted)
$lastCode = Brand::withTrashed()
    ->where('code', 'like', 'MRC-%')
    ->orderByDesc('code')
    ->value('code');
$nextNumber = $lastCode ? (int)substr($lastCode, 4) + 1 : 1;
$code = 'MRC-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

// Prefijos por entidad:
// PROD-XXXXX (productos), MRC-XXX (marcas), CAT-XXXX (categorías)
// OC-XXXXX (órdenes compra), REC-XXXXX (recepciones)
```

### 6. Productos con FormData (imagen)

```php
// ProductController usa FormData en lugar de JSON por upload de imagen
// Validación de brand_id como FK:
'brand_id' => 'nullable|exists:brands,id',
// Eager loading incluye brand:
Product::with(['category:id,name,code', 'unit:id,name,abbreviation', 'brand:id,name,code']);
```

### 7. Endpoint `list` para Selects

Patrón para catálogos que necesitan dropdown en frontend:

```php
// GET /marcas/list → Solo campos mínimos, activos, sin paginación
public function list() {
    $brands = Brand::where('is_active', true)
        ->select('id', 'code', 'name')
        ->orderBy('name')->get();
    return response()->json(['success' => true, 'data' => $brands]);
}
```

## Eventos de Broadcast

### Eventos Disponibles (13)

```php
// Modelos con broadcast automático
AreaUpdated, BranchUpdated, CultivoUpdated, EntityTypeUpdated,
EntityUpdated, LoteUpdated, ProductorUpdated, SalidaCampoUpdated,
ZonaCultivoUpdated

// Notificaciones y vacaciones
UserNotification, VacationRequestUpdated, NotificationCreated

// Evento genérico
ModelBroadcastEvent
```

### Canales

```php
'App.Models.User.{id}'                          // Notificaciones personales
'vacation.{enterpriseSlug}.updated'              // Cambios vacaciones
'enterprise.{id}'                                // Eventos de empresa
'module.{enterprise}.{app}.{module}'             // Eventos de módulo
'presence.enterprise.{id}'                       // Usuarios conectados
```

## Módulo Empaque (Operación Agrícola)

Flujo de 7 fases: **Recepciones → Proceso → Producción → Rezaga → Embarques → Venta Rezaga → Calidad**

- Cada fase tiene su propio controller y modelo
- EmbarqueEmpaque tiene detalle (EmbarqueEmpaqueDetalle)
- VentaRezagaEmpaque tiene detalle (VentaRezagaEmpaqueDetalle)
- Filtrado por `entity_id` (planta empacadora seleccionada en frontend)
- Requiere seleccionar entidad (planta) antes de operar

## Cálculo de Vacaciones LFT México

```php
// Tabla según Ley Federal del Trabajo:
$tabla = [
    1 => 12,   // Primer año: 12 días
    2 => 14, 3 => 16, 4 => 18, 5 => 20,
    // 6-10: 22 días, 11-15: 24 días, etc. (+2 días cada 5 años)
];
// Días acumulados = suma de todos los años trabajados
```

## Comandos de Desarrollo

```bash
composer dev              # Server + queue + logs + reverb (concurrente)
composer test             # PHPUnit
php artisan serve         # Solo servidor (:8000)
php artisan migrate       # Ejecutar migraciones
php artisan migrate:fresh --seed  # Reset DB con seeders
php artisan tinker        # REPL interactivo
php artisan pail          # Ver logs en tiempo real
php artisan reverb:start  # WebSocket server
```

## Credenciales BD (Desarrollo)

```
DB_HOST=localhost
DB_DATABASE=sentinel
DB_USERNAME=root
DB_PASSWORD=MasterKey
```

## Backup y Restauración de Base de Datos

```powershell
# Crear Backup
$timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe" -u root -pMasterKey sentinel > "backups/sentinel_backup_$timestamp.sql"

# Restaurar Backup
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u root -pMasterKey sentinel < "backups/sentinel_backup_YYYY-MM-DD_HH-mm-ss.sql"
```

## Checklist para Nuevos Recursos

1. [ ] Crear migración: `php artisan make:migration create_recursos_table`
2. [ ] Crear modelo con `Loggable` trait y `SoftDeletes` si aplica
3. [ ] Crear controller en carpeta de empresa correspondiente
4. [ ] Agregar rutas en `routes/api.php` bajo el prefijo correcto
5. [ ] Definir `$fillable` y `$casts` en el modelo
6. [ ] Auto-generar código con `withTrashed()` si tiene campo `code`
7. [ ] Endpoint `list` para selects si es catálogo
8. [ ] Crear evento de broadcast si requiere tiempo real
9. [ ] Usar `NotificationService` para notificar cambios importantes
10. [ ] Validar FK con `exists:tabla,id` en store/update
