# SENTINEL 3.0 — Backend (Claude Code Instructions)

> Este archivo se carga automáticamente al iniciar una sesión de Claude Code en este repo.
> Complementa (no reemplaza) las skills del proyecto en `.claude/skills/` — Claude debe
> invocarlas cuando apliquen: `laravel-crud-workflow`, `backend-performance`, `code-audit-security`.

## 🛑 NUNCA correr `php artisan test` (ni `migrate`) con la opción `--env=...`

Incidente real (agosto 2026): `php artisan test --env=testing` hizo que las variables
de `.env` real (MySQL de desarrollo) se filtraran al proceso hijo de PHPUnit y ganaran
sobre el `sqlite`/`:memory:` de `phpunit.xml` (porque los `<env>` no tenían
`force="true"`). El resultado fue que `RefreshDatabase` borró y volvió a migrar
parcialmente la base de datos MySQL real de desarrollo — pérdida de datos real, no un
test aislado. Ya se restauró desde un dump y se cerró el hueco con dos capas
(`force="true"` en `phpunit.xml` + un guard duro en `tests/TestCase.php` que aborta el
proceso si la conexión resuelta no es sqlite en memoria), pero **la regla sigue siendo
no usar `--env=` en comandos de test/migrate — es redundante y es justo lo que causó
el incidente.** Correr siempre `php artisan test` a secas (o con `--filter=`).

## Regla obligatoria: verificación post-cambio

**Después de CADA cambio de código, verificar antes de dar por terminado:**

```bash
php artisan route:clear && php artisan config:clear && php artisan view:clear
php -l <archivo_modificado>.php   # Lint del archivo modificado
```

- Si hay errores, corregirlos antes de responder.
- Si se modificó una migración, verificar con `php artisan migrate --pretend`.
- No dar por terminado un cambio sin validar que compila sin errores.

## Arquitectura general

API REST multi-tenant con Laravel 12 + Sanctum. Jerarquía: **Empresa → Aplicación → Módulo → Submódulo**.

| Componente | Tecnología |
|---|---|
| Framework | Laravel 12 (PHP ^8.2) |
| Auth | Sanctum (tokens Bearer) |
| DB | MySQL |
| WebSockets | Laravel Reverb |
| Logs | Pail (tiempo real) |

Empresas activas: **Splendid Farms** (agrícola, la más desarrollada), **Grupo Espléndido** (corporativo/RH), **Splendid by Porvenir** (comercio).

## Estructura de Controllers

```
app/Http/Controllers/Api/
├── AuthController, ProfileController, PendingApprovalController, NotificationController
├── EnterpriseController, ApplicationController, ModuleController, SubmoduleController
├── HierarchicalPermissionController, UserController, UserPermissionController
├── Admin/                    # ActivityLog, Schedule, ApprovalConfig, EntityAccess
├── GrupoEsplendido/RH/       # Department, Position, Employee, WorkSchedule,
│                             # Attendance, TimeClock (checador), Vacation, Incident, IncidentType
└── SplendidFarms/
    ├── Crop/AgricultureCycle/Calibre/Temporada/Variedad/TipoVariedad/
    │   Productor/ZonaCultivo/Lote Controller
    ├── Administration/       # Branch, EntityType, Entity, Area, Supplier,
    │                         # ConvenioCompra, LiquidacionConsignacion, TableroProductores
    ├── Inventory/            # Brand, ProductCategory, UnitOfMeasure, Product, MovementType,
    │                         # InventoryMovement, PurchaseOrder, PurchaseReceipt,
    │                         # InventoryReport, Recipe, TipoCarga
    │                         # AssetCategory, FixedAsset (Activos Fijos)
    ├── Accounting/           # AccountPayable (+ pagos + aplicaciones)
    └── OperacionAgricola/    # Temporada, Catalogo, CosteoAgricola, DiagnosticoIA, Etapa,
        ├── Cosecha/          # SalidaCampo, Cierre, Venta, Calidad
        └── Empaque/          # ⭐ 7 fases: Recepcion→Proceso→Produccion→Rezaga→Embarque→VentaRezaga→Calidad
```

## Servicios

```php
// NotificationService - fluent API
NotificationService::create()
    ->toUser($user) // o ->toEnterprise($id) / ->toRole('admin') / ->toAll()
    ->title('Título')->message('Mensaje')->type('info|success|warning|error')
    ->withAction('Ver detalles', '/ruta')->urgent()->expiresIn(7)->send();

// Otros: ApprovalNotificationService, VacationCalculatorService (LFT México), DiagnosticoIAService
```

## Patrones críticos

### 1. Rutas por empresa/aplicación
Agrupadas bajo `Route::prefix('{empresa}')->group(...)`, luego por app (`inventario`, `administration`, `contabilidad`, `operacion-agricola`, `rh`), luego por módulo. Ver `routes/api.php`.

### 2. Headers de contexto (para auditoría, no autorización)
```php
$request->header('X-Enterprise-Slug');  // 'splendidfarms'
$request->header('X-Application-Slug'); // 'inventario'
$request->header('X-Module-Slug');      // 'activos-fijos'
$request->header('X-Submodule-Slug');   // 'activos'
```
⚠️ Estos headers son para logging. La autorización real siempre se valida server-side (nunca confiar solo en el header).

### 3. Trait `Loggable` para auditoría — todos los modelos de negocio lo usan
```php
use HasFactory, Loggable, SoftDeletes;
```

### 4. Formato de respuesta JSON estándar
```php
// Éxito
return response()->json(['success' => true, 'message' => '...', 'data' => $resource], 200); // 201 en store
// Error
return response()->json(['status' => 'error', 'message' => '...'], 404|403|422);
```

### 5. Auto-generación de códigos (patrón usado en casi todo)
```php
$lastCode = Model::withTrashed()->where('code', 'like', 'PREFIJO-%')
    ->orderByRaw('CAST(SUBSTRING(code, N) AS UNSIGNED) DESC')->value('code');
$nextNumber = $lastCode ? (int)substr($lastCode, strlen('PREFIJO-')) + 1 : 1;
$code = 'PREFIJO-' . str_pad($nextNumber, 3|5, '0', STR_PAD_LEFT);
// Prefijos: PROD- artículos, MRC- marcas, CAT-/TAC- categorías, AF- activos fijos, OC-/REC- compras
```

### 6. Endpoint `list` para selects (sin paginar, solo campos mínimos)
```php
public function list() {
    return response()->json(['success' => true, 'data' =>
        Brand::where('is_active', true)->select('id', 'code', 'name')->orderBy('name')->get()
    ]);
}
```

## Eventos de broadcast y canales

```php
'App.Models.User.{id}'                      // Notificaciones personales
'vacation.{enterpriseSlug}.updated'
'enterprise.{id}'
'module.{enterprise}.{app}.{module}'
'presence.enterprise.{id}'
```

## Acceso cruzado de entidades (cross-enterprise)
Tabla pivot `enterprise_entity` (`enterprise_id`, `entity_id`, `access_level: read|write`) — permite que una empresa acceda a bodegas/plantas de otra. Ver `EntityAccessController` (Admin).

## Vacaciones LFT México
Tabla acumulativa por antigüedad: año 1 = 12 días, +2 días por año hasta el 4to, luego +2 cada 5 años. Ver `VacationCalculatorService`.

## Comandos de desarrollo

```bash
composer dev                      # Server + queue + logs + reverb (concurrente)
composer test                     # PHPUnit
php artisan serve                 # Solo servidor (:8000)
php artisan migrate               # Ejecutar migraciones
php artisan migrate:fresh --seed  # Reset DB con seeders
php artisan tinker                # REPL interactivo
php artisan pail                  # Logs en tiempo real
php artisan reverb:start          # WebSocket server
```

## Skills del proyecto (`.claude/skills/`)

| Skill | Cuándo se activa |
|---|---|
| `laravel-crud-workflow` | Crear un recurso/submódulo CRUD completo desde cero |
| `backend-performance` | Tocar queries, relaciones, endpoints con datos agregados |
| `code-audit-security` | Endpoints con input de usuario, uploads, auth, code review de seguridad |

## Estándares obligatorios (resumen — detalle en las skills de arriba)

- **Performance**: eager loading con columnas específicas (`with(['rel:id,name'])`), paginación en `index()` (excepto `list()`), filtros con `when()`, nunca `Model::all()` en listados grandes.
- **Seguridad**: siempre `$request->validate()`, `findOrFail()`, `$fillable` explícito (nunca `$guarded = []`), FKs con `exists:tabla,id`, uploads validados por mime/tamaño con nombre hasheado.
- **Auditoría**: `Loggable` + `SoftDeletes` en entidades principales, índices en FKs/`is_active`/`code`, decimales financieros con precisión (`decimal(12,2)`/`decimal(12,4)`).

## ⚠️ Huecos conocidos del proyecto (pendientes de mejora)

- **Testing real: en progreso.** Cobertura completa (83 tests) en dos módulos como referencia de patrón:
  - `tests/Feature/SplendidFarms/Inventory/` — Activos Fijos (`FixedAssetControllerTest`, `AssetCategoryControllerTest`, 31 tests), usa `tests/Concerns/CreatesAssetFixtures.php`.
  - `tests/Feature/CRM/` — CRM Comercial (8 archivos, 52 tests: Cliente, Prospecto, Vendedor, Región/Zona/Bodega, Producto, Contacto, EmpresaExterna, Actividad), usa `tests/Concerns/CreatesCrmFixtures.php`. A diferencia de Activos Fijos, el CRM resuelve la empresa por el header `X-Enterprise-Id` (no por la URL) — los tests lo mandan explícitamente vía `$this->crmHeaders()`.
  - El resto de módulos (RH, Agrícola, Empaque) todavía no tiene cobertura.
  - Escribir estos tests **encontró y corrigió 2 bugs reales** que llevaban tiempo sin detectarse por falta de cobertura: `ClienteController::index()` llamaba a un método de scope inexistente (`->empresa()` en vez de `->where('empresa_id', ...)`) y tronaba 500 en cada request; y `crm_vendedores.user_id` era `NOT NULL` en la BD aunque la validación del controller lo declara opcional, causando un 500 al crear un vendedor sin cuenta ligada (migración `2026_08_11_190000_make_user_id_nullable_on_crm_vendedores_table.php`).
- **Los tests corren en SQLite in-memory** (`phpunit.xml`), no contra MySQL. Varias migraciones antiguas tenían SQL específico de MySQL (`MODIFY COLUMN`, índices no liberados antes de `dropColumn`) que se fueron arreglando conforme se encontraron al escribir tests — si al escribir un test nuevo `RefreshDatabase` truena con un error de sintaxis SQLite en una migración que no tocaste, es este mismo patrón: revisa si falta `dropIndex()` antes de un `dropColumn()`, o si hay un `DB::statement()` con sintaxis de MySQL que necesita un guard `if (DB::getDriverName() === 'mysql')`.
- **CI de CRM ahora sí prueba algo**: `.github/workflows/crm-ci.yml` corre `--filter=CRM`, y antes de este trabajo eso coincidía con 0 tests (`INFO No tests found`, exit code 0 — el badge siempre salía verde sin validar nada). Ya hay 52 tests reales bajo ese filtro. Los demás módulos (incluyendo Activos Fijos) siguen sin estar en ningún workflow de CI — sería el siguiente paso natural.
- **Sin análisis estático**: no hay Larastan/PHPStan configurado, solo Pint (con la configuración por defecto, sin `pint.json` propio).
- **Límite de intentos de login y proxies:** `POST /api/auth/login` limita 5/min por correo+IP y 20/min por IP (`RateLimiter::for('login')` en `AppServiceProvider`). No hay `trustProxies` configurado: si `sentinelback.ddns.net` está detrás de un proxy inverso, todas las peticiones llegan con la IP del proxy y el límite por IP se compartiría. Antes de desplegar, comprobar en el servidor y, si hay proxy, añadir `$middleware->trustProxies(at: ['<ip-del-proxy>']);` en `bootstrap/app.php`.

## Credenciales BD (desarrollo local)

```
DB_HOST=localhost
DB_DATABASE=sentinel
DB_USERNAME=root
DB_PASSWORD=MasterKey
```
