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

## Estructura de Controllers

```
app/Http/Controllers/Api/
├── AuthController.php                    # Login, logout, registro
├── ProfileController.php                 # Perfil usuario + vacaciones
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
│   └── ScheduleController.php            # Horarios globales (CRUD + asignación)
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
└── SplendidFarms/                        # Controllers Splendid Farms
    ├── CropController.php
    ├── AgricultureCycleController.php
    ├── TemporadaController.php
    ├── VariedadController.php
    ├── TipoVariedadController.php
    ├── ProductorController.php
    ├── ZonaCultivoController.php
    ├── LoteController.php
    ├── Administration/
    │   ├── BranchController.php
    │   ├── EntityTypeController.php
    │   ├── EntityController.php
    │   ├── AreaController.php
    │   └── SupplierController.php
    ├── Inventory/
    │   ├── ProductCategoryController.php
    │   ├── UnitOfMeasureController.php
    │   ├── ProductController.php
    │   ├── MovementTypeController.php
    │   ├── InventoryMovementController.php
    │   ├── PurchaseOrderController.php
    │   ├── PurchaseReceiptController.php
    │   └── InventoryReportController.php
    └── Accounting/
        └── AccountPayableController.php
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

## Modelos Principales

### Notificaciones

```php
// SystemNotification.php
SystemNotification::active()           // No expiradas
    ->forUser($userId)                 // Del usuario
    ->unreadBy($userId)               // No leídas por usuario
    ->notDismissedBy($userId)         // No descartadas
    ->ordered()                        // Por prioridad y fecha
    ->get();

// Relaciones
$notification->readers;               // Usuarios que leyeron
$notification->dismissers;            // Usuarios que descartaron
```

### Vacaciones (LFT México)

```php
// Vacation.php - Solicitudes de vacaciones
Vacation::pending()                    // status = pending
    ->approved()                       // status = approved
    ->forEmployee($employeeId)
    ->inDateRange($start, $end)
    ->get();

// VacationBalance.php - Saldos acumulados
$balance = VacationBalance::where('employee_id', $id)->first();
$balance->total_days;                  // Días totales según antigüedad
$balance->used_days;                   // Días usados
$balance->available_days;              // Calculado: total - used - pending

// VacationBalanceHistory.php - Historial de movimientos
$history = VacationBalanceHistory::forEmployee($id)->get();
// Tipos: accrual, used, adjustment, expired
```

### Horarios de Trabajo (Global)

Los horarios son **globales** y se asignan a empresas mediante tabla pivot:

```php
// WorkSchedule.php - Relación muchos a muchos
public function enterprises()
{
    return $this->belongsToMany(Enterprise::class, 'enterprise_work_schedule')
        ->withPivot('is_default')
        ->withTimestamps();
}
```

### Módulo RH - Modelos

```
Employee (employees)
├── department_id     → Department
├── position_id       → Position
├── work_schedule_id  → WorkSchedule
├── hire_date         → Fecha contratación (para cálculo vacaciones)
├── qr_code           → Código QR único
├── pin_code          → PIN 4 dígitos
└── enterprise_id     → Enterprise (via department)

Department (departments)
├── enterprise_id     → Enterprise
└── parent_id         → Department (jerárquico)

Position (positions)
└── department_id     → Department

Attendance (attendances)
├── employee_id       → Employee
├── check_in          → timestamp
├── check_out         → timestamp
└── check_type        → qr, pin, manual

Vacation (vacations)
├── employee_id       → Employee
├── start_date / end_date
├── days_requested
├── status            → pending, approved, rejected, cancelled
├── approved_by       → User (nullable)
└── notes / rejection_reason

Incident (incidents)
├── employee_id       → Employee
├── incident_type_id  → IncidentType
├── status            → pending, approved, rejected
└── start_date / end_date
```

## Patrones Críticos

### 1. Rutas por Empresa/Aplicación

```php
// Rutas globales (autenticadas)
Route::prefix('profile')->group(function () {
    Route::get('/', [ProfileController::class, 'show']);
    Route::put('/', [ProfileController::class, 'update']);
    Route::post('/', [ProfileController::class, 'update']); // FormData
    Route::put('/password', [ProfileController::class, 'changePassword']);
    Route::post('/vacation-request', [ProfileController::class, 'requestVacation']);
    Route::delete('/vacation-request/{id}', [ProfileController::class, 'cancelVacationRequest']);
    Route::get('/vacation-history', [ProfileController::class, 'vacationHistory']);
});

Route::prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/count', [NotificationController::class, 'count']);
    Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('/dismiss-read', [NotificationController::class, 'dismissAllRead']);
    Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/{id}/dismiss', [NotificationController::class, 'dismiss']);
});

// Rutas RH
Route::prefix('grupoesplendido/rh')->group(function () {
    Route::apiResource('departamentos', DepartmentController::class);
    Route::apiResource('puestos', PositionController::class);
    Route::apiResource('empleados', EmployeeController::class);
    Route::apiResource('horarios', WorkScheduleController::class);
    Route::apiResource('asistencia', AttendanceController::class);
    Route::apiResource('vacaciones', VacationController::class);
    Route::apiResource('incidencias', IncidentController::class);
    Route::apiResource('tipos-incidencia', IncidentTypeController::class);

    // Vacaciones específicas
    Route::get('vacaciones/tabla-lft', [VacationController::class, 'getVacationTable']);
    Route::get('vacaciones/empleado/{employee}/info', [VacationController::class, 'getEmployeeVacationInfo']);
    Route::post('vacaciones/{vacation}/aprobar', [VacationController::class, 'approve']);
    Route::post('vacaciones/{vacation}/rechazar', [VacationController::class, 'reject']);
});

// Checador público (SIN AUTH)
Route::prefix('checador')->group(function () {
    Route::post('qr', [TimeClockController::class, 'checkByQR']);
    Route::post('pin', [TimeClockController::class, 'checkByPIN']);
    Route::get('status', [TimeClockController::class, 'getStatus']);
    Route::get('server-time', [TimeClockController::class, 'serverTime']);
});
```

### 2. Headers de Contexto (Obligatorios para logging)

```php
$request->header('X-Enterprise-Slug');    // 'grupoesplendido'
$request->header('X-Application-Slug');   // 'rh'
$request->header('X-Module-Slug');        // 'gestion'
$request->header('X-Submodule-Slug');     // 'vacaciones'
```

### 3. Trait Loggable para Auditoría

```php
use App\Traits\Loggable;

class Employee extends Model
{
    use HasFactory, Loggable;
}
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

## Eventos de Broadcast

### VacationRequestUpdated

```php
use App\Events\VacationRequestUpdated;

// Después de aprobar/rechazar vacaciones
broadcast(new VacationRequestUpdated($vacation, 'approved'))->toOthers();

// El evento envía a:
// - Canal privado del usuario: App.Models.User.{userId}
// - Canal de empresa: vacation.{enterpriseSlug}.updated
```

### Notificaciones Personales

```php
use App\Events\NotificationCreated;

// Se dispara automáticamente desde NotificationService
// Canal: App.Models.User.{userId}
```

## Cálculo de Vacaciones LFT México

```php
// En VacationController::getVacationTable()
// Tabla según Ley Federal del Trabajo:
$tabla = [
    1 => 12,   // Primer año: 12 días
    2 => 14,   // Segundo año: 14 días
    3 => 16,   // Tercer año: 16 días
    4 => 18,   // Cuarto año: 18 días
    5 => 20,   // Quinto año: 20 días
    // 6-10: 22 días
    // 11-15: 24 días
    // etc. (+2 días cada 5 años)
];

// Días acumulados = suma de todos los años trabajados
// Ejemplo: 3 años = 12 + 14 + 16 = 42 días totales
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

## Tiempo Real con Laravel Reverb

### Canales Disponibles

- `App.Models.User.{id}` - Notificaciones y vacaciones personales
- `vacation.{enterpriseSlug}.updated` - Cambios en vacaciones (para RH)
- `enterprise.{id}` - Eventos de empresa
- `module.{enterprise}.{app}.{module}` - Eventos de módulo
- `presence.enterprise.{id}` - Usuarios conectados

### Crear Evento de Broadcast

```php
class MiEvento implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'mi-evento';
    }
}
```

## Checklist para Nuevos Recursos

1. [ ] Crear migración: `php artisan make:migration create_recursos_table`
2. [ ] Crear modelo con `Loggable` trait
3. [ ] Crear controller en carpeta de empresa correspondiente
4. [ ] Agregar rutas en `routes/api.php` bajo el prefijo correcto
5. [ ] Definir `$fillable` y `$casts` en el modelo
6. [ ] Crear evento de broadcast si requiere tiempo real
7. [ ] Usar `NotificationService` para notificar cambios importantes
8. [ ] Agregar `broadcast(new Evento(...))->toOthers()` en controller

## Backup y Restauración de Base de Datos

```powershell
# Crear Backup
$timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe" -u root -pMasterKey sentinel > "backups/sentinel_backup_$timestamp.sql"

# Restaurar Backup
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u root -pMasterKey sentinel < "backups/sentinel_backup_YYYY-MM-DD_HH-mm-ss.sql"
```

## Credenciales BD (Desarrollo)

```
DB_HOST=localhost
DB_DATABASE=sentinel
DB_USERNAME=root
DB_PASSWORD=MasterKey
```
