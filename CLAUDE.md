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
    │                         # AssetCategory, FixedAsset (Activos Fijos: carpeta heredada;
    │                         #   las rutas viven en Administración → Activos Fijos)
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
$request->header('X-Application-Slug'); // 'administration'
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

## Activos Fijos

Módulo de Administración → Activos Fijos en todas las empresas activas (`FixedAssetsModuleSeeder` recorre las activas; una empresa nueva se agrega volviendo a correrlo). La carpeta de controlador `Inventory/` es heredada; ver la estructura de controllers.

Despliegue: `php artisan db:seed --class=FixedAssetsModuleSeeder` (idempotente); dar permisos por empresa en el panel y capturar sucursales/entidades/áreas de cada empresa nueva (Grupo Espléndido las ve en el selector de alta cuando tienen el módulo).

### Asignaciones (fase 2)
- Tabla `fixed_asset_assignments`: historial inmutable de entregas. `assignee_type` ∈ `employee` (`employees`), `sf_employee` (`sf_employees`), `user` (`users`) sin llave foránea; el nombre, puesto y departamento del responsable y una copia del activo (`asset_snapshot`) se guardan al entregar para reimprimir la carta idéntica.
- Servicios en `App\Services\ActivosFijos`: `ResponsablesActivo` (búsqueda y validación de personas de la empresa del activo) y `AsignadorActivos` (asignar, devolver, reasignar y corregir, con `lockForUpdate`; una sola asignación activa por activo; asignar → `en_uso`, devolver → `disponible`; se bloquean `en_mantenimiento`, `fuera_de_servicio`, `baja` e inactivos).
- Controlador `Api/ActivosFijos/AsignacionActivoController`; rutas en `routes/activos-fijos.php`. Permisos del submódulo `asignaciones` (`view` lee y descarga/imprime, `create` asigna y reasigna, `edit` devuelve, corrige y sube la carta firmada); `GET activos/{asset}/responsables` (búsqueda de personas para el formulario) requiere `create`. Sin concesión automática para nadie: el seeder crea las filas de `user_submodule_permissions` de admin@ y demo@ con `is_granted` por defecto (`false`, ver la migración de esa tabla); admin@ opera por su rol, y demo@ ve la entrada de menú pero no puede operar hasta que se le concedan los `asignaciones.*` a mano.
- `entrega.entregado_por` de la carta sale de la copia `assigned_by_name` (los usuarios se borran de verdad y `assigned_by` queda nulo); solo si falta se usa el nombre vivo del usuario.
- Borrar un activo con asignación activa responde 422 (`asset`): primero se registra la devolución. Reasignar rechaza una entrega nueva anterior a la fecha de devolución (422 `assigned_at`).
- `AsignadorActivos::activaDe` NO usa `lockForUpdate` a propósito: en InnoDB un `SELECT … FOR UPDATE` sin filas bloquea el hueco del índice y dos asignaciones de activos distintos se bloquean entre sí (deadlock 1213). La serialización la da el bloqueo de la fila del activo.
- La carta firmada va al disco privado `local` (`fixed-asset-assignments/{id}/…`) y se sirve con `GET asignaciones/{a}/carta-firmada`; la ruta nunca sale en el JSON. La carta en PDF la dibuja el front con los datos de `GET asignaciones/{a}/carta`.
- El listado de activos incluye `asignacion_activa` (eager load) para mostrar «Asignado a …».

### Alta desde compras (fase 3)
- Un producto marcado `products.is_fixed_asset` genera, al confirmar una recepción (`ConfirmadorRecepcion`), una fila `pending` en `fixed_asset_receipt_units` por cada unidad entera aceptada (`GeneradorUnidadesCompra`; 2.5 → 2). Las recepciones confirmadas antes de marcar el producto no generan unidades.
- Servicios en `App\Services\ActivosFijos`: `AltaActivosDesdeCompra` (`alta` y `descartar`, en transacción con las filas bloqueadas por id ascendente), `CreadorActivo` (alta de un activo con código y características, compartido con `FixedAssetController@store`; debe llamarse dentro de una transacción), `ValidadorRelacionesActivo` (reglas tipo/subtipo/sucursal/entidad/área/marca) y `UnidadesVigentes`.
- «Vigente» = unidad `pending`, o `registered` cuyo activo existe y no está en `status = baja`. Descartar la unidad o dar de baja el activo libera los bloqueos.
- Bloqueos (422): `InventoryStock::updateStock` rechaza (clave `stock`) cualquier salida que deje el stock por debajo de las unidades vigentes del producto en ese almacén y lote (cubre salidas, traspasos, ajustes, consumo de aplicaciones y producción de empaque; el lote vacío, nulo y `SIN-LOTE` son la misma cubeta); `InventoryMovementController@cancel` rechaza (clave `movement`) anular el movimiento COMPRA de una recepción con unidades vigentes; `FixedAssetController@destroy` rechaza (clave `asset`) borrar un activo de compra que no está en baja. `InventoryMovementController` (`store`, `approve` y `cancel`) relanza `ValidationException` antes de su `catch (\Exception)` genérico para que el 422 no se vuelva 500.
- API bajo `/{empresa}/administration/activos-fijos`: `GET altas-pendientes` (filtros `status`, `search`, `enterprise_id`; `meta.conteos`), `POST altas-pendientes/alta` (`units[{id, serial_number}]`, `category_id`, `subcategory_id`, `area_id`, `brand_id`, `model`) y `POST altas-pendientes/descartar` (`unit_ids[]`, `reason`). Todo requiere `activos.create` (sin permisos nuevos) y respeta el alcance de `AlcanceActivos`: la unidad de otra empresa responde 404.
- Evento `fixed-asset-unit.updated` (`FixedAssetUnitUpdated`, acciones `created` al confirmar la recepción, `registered` y `discarded`) por los canales de la empresa dueña y de GE. Al dar de alta también se emite `FixedAssetUpdated` (`created`) para refrescar el listado de activos.
- Del activo creado se precargan: fecha de compra (fecha de la recepción), factura (documento del proveedor), `purchase_value` (costo unitario sin IVA), `supplier_id`, `purchase_receipt_id`, sucursal y entidad (almacén de la recepción). El listado y la ficha del activo traen `supplier` y `purchase_receipt`.
- Despliegue: respaldo, `php artisan migrate`; sin seeders ni permisos nuevos; marcar a mano en el catálogo los productos que son activo fijo.
- Huecos conocidos: (a) si un activo de compra vuelve de `baja` a otro estado después de haberse dado salida a su stock, su unidad vuelve a contar como vigente y retiene una pieza de la siguiente entrada (ver la fase 5); (b) si el almacén de la recepción pertenece a otra empresa (acceso cruzado) o está inactivo, el alta responde 422 (`branch_id`/`entity_id`) y la unidad solo se puede descartar; (c) `ConsumidorInventarioAplicacion::repartir` no descuenta las reservas al repartir FIFO entre lotes, así que puede elegir un lote respaldado y la aplicación responde 422 `stock` aunque otro lote tenga existencia libre; (d) las recepciones sin empresa no generan unidades de activo fijo (`GeneradorUnidadesCompra` devuelve 0 y la confirmación sigue normal).

### Depreciación (fase 4)
- Línea recta en meses completos y **centavos enteros**. `CalculadoraDepreciacion` es pura: el acumulado al mes k es `round(base × k ÷ vida)` y la cuota es la diferencia de acumulados, así la suma de cuotas es exactamente la base. Vida útil en meses = `round(1200 ÷ tasa anual %)`. Base = valor de compra − residual %.
- Columnas nuevas: `asset_categories.depreciation_rate|salvage_percent` (se editan en el catálogo de GE) y `fixed_assets.depreciation_start|depreciation_rate|salvage_percent`. `depreciation_start` se guarda siempre el día 1 del mes (mutador del modelo). `CreadorActivo` copia tasa y residual del subtipo (o del tipo) si el alta no los trae.
- **Semántica de copia:** tasa y residual se COPIAN al activo al crearlo. Reclasificar un activo a otro tipo no cambia su tasa propia, y corregir la tasa de un tipo no cambia la de los activos ya creados con tasa copiada. Los activos anteriores sin tasa propia siguen al tipo en vivo.
- `ResolutorDepreciacion` calcula los valores efectivos: tasa del activo → subtipo → tipo → `100 ÷ useful_life_years`; residual del activo → subtipo → tipo → 0; inicio = `depreciation_start` o el mes de `purchase_date`. Sin fecha, valor (> 0) o tasa, el activo queda «sin depreciar» (`sin_fecha` | `sin_valor` | `sin_tasa`). Requiere `category` y `subcategory` cargadas. `FixedAssetController` agrega `depreciacion` (estado, cuota_mensual, acumulado, valor_en_libros, tasa, residual_pct, inicio, motivo) al JSON del listado, la ficha, el alta y la edición.
- `CierreDepreciacion`: `estadoDelMes` (mes cerrado = foto guardada; abierto = cálculo vivo), `cerrar` y `reabrir`. Cada cierre se calcula por fórmula, sin depender de los anteriores. Entran los activos de la empresa que no están en `baja` ni borrados (salvo la excepción de la fase 5: un activo con salida vigente entra hasta el mes de la salida); «por iniciar» no genera línea y «sin depreciar» solo se cuenta (`excluded_count`; la lista completa solo se ve en la vista previa). Tablas `depreciation_closes` (única `enterprise_id + period`) y `depreciation_close_lines` (foto del activo: no cambia si luego se edita el activo o el tipo). `period` se maneja siempre como cadena `Y-m-d` (sin cast).
- Reabrir: solo el último cierre de la empresa, con motivo; el motivo y el autor quedan en `ActivityLog` (acción `reopen`). Si el cierre no es el último, responde 422 con clave `cierre`.
- Se permite cerrar el mes en curso: un activo comprado después del cierre no aparece en el `month_total` de ese mes (sí en el acumulado del siguiente), así que la suma de los `month_total` puede no cuadrar con el acumulado del último cierre.
- `month` se valida como `AAAA-MM` con mínimo `2000-01` (422 con clave `month`).
- API bajo `/{empresa}/administration/activos-fijos/depreciacion`: `GET ?month=YYYY-MM`, `GET cierres`, `POST cierres {month}`, `DELETE cierres/{cierre} {reason}`. Permisos del submódulo `depreciacion` (`view` lee, `create` cierra, `delete` reabre); el tipo de permiso `edit` también existe (lo crea el seeder estándar) aunque la fase solo use `view`/`create`/`delete`. Sin concesión automática: el seeder crea las filas de `user_submodule_permissions` de admin@ y demo@ con `is_granted = false`. En GE hay que mandar `enterprise_id` en `GET`, `GET cierres` y `POST cierres` (422 si falta); un cierre de empresa fuera del alcance responde 404.
- Un activo con salida vigente (fase 5) se deprecia hasta el mes de la salida inclusive (mes completo) y no entra a meses posteriores; un `baja` antiguo (sin salida) sigue fuera de los cierres nuevos, también cuando se le regulariza con una salida (R13).
- Despliegue: respaldo, `php artisan migrate`, `php artisan db:seed --class=FixedAssetsModuleSeeder` (idempotente; crea el submódulo `depreciacion`), conceder `depreciacion.*` a mano y capturar en GE la tasa y el % residual de los tipos (sin tasa, todo aparece «sin depreciar»). Back antes que el front.

### Bajas, ventas y traspasos (fase 5)
- Tabla `fixed_asset_disposals`: una fila por salida (`venta`, `desecho`, `siniestro`, `donacion`, `traspaso`), con historial: deshacer marca `reversed_at/by/reversal_reason` y conserva la fila. Guarda una copia del activo (`asset_snapshot`; la carta se imprime de ahí) y las cifras congeladas a la fecha (`purchase_value`, `accumulated_depreciation`, `book_value`, `result_amount`). Sin permiso de aprobación: basta `salidas.create`; «quién autorizó» es texto libre (`authorized_by_name`).
- Servicios en `App\Services\ActivosFijos`: `CalculadorSalida` (libro a la fecha con la calculadora de la fase 4, mes completo de la salida; resultado: venta = precio − libro, desecho/siniestro/donación = −libro, traspaso = 0; sin tasa o sin fecha el libro es el valor de compra, sin valor es 0, con advertencias) y `RegistradorSalidaActivo` (`previa`, `registrar`, `deshacer`, con el activo bloqueado en transacción).
- Reglas: una sola salida vigente por activo (422 `asset`); fecha no futura, no anterior a la compra ni a la entrega vigente, y en un mes **posterior** al último cierre de depreciación (422 `disposal_date`); la asignación activa se devuelve en la misma operación (motivo `otro`, nota «Devuelta por baja del activo (salida #id)»); el activo queda en `baja` y `previous_status` se restaura al deshacer. **R13:** un baja antiguo (sin salida) que se regulariza con una salida guarda `previous_status = baja`: sigue fuera de la previa y del cierre de depreciación (no se le carga un acumulado que nunca apareció en ningún cierre) y al deshacer la salida vuelve a `baja`; el valor en libros de la salida sí se calcula a su fecha. Los bloqueos de compras de la fase 3 se liberan solos porque miran `status = baja`. Deshacer exige un motivo (422 `reason`) y que no haya cierres de depreciación posteriores al mes de la salida (422 `salida`; el cierre del mismo mes sí vale), y no se puede deshacer una salida ya deshecha (422 `salida`).
- Validación de entrada: `sale_price` admite como máximo 2 decimales (`decimal:0,2`) y las fechas se validan como `Y-m-d` real.
- Depreciación: lo nuevo respecto a la fase 4 (ver arriba la regla de la salida vigente): `CierreDepreciacion::calcular` incluye o excluye al activo según el mes de la salida, su `depreciacion` en el JSON trae las cifras congeladas (`estado = baja`) y los cierres ya guardados no cambian.
- Traspaso: crea en la misma transacción un activo nuevo en la receptora (código propio, `purchase_value` = libro, `purchase_date` = fecha del traspaso, `depreciation_start` = mes siguiente, tasa y residual copiados, estado `disponible`, marca solo si existe en la receptora) con la sucursal y entidad que se elijan (`destination_branch_id`, `destination_entity_id`, obligatorias porque `fixed_assets` no admite nulos; `destination_area_id` opcional). La empresa destino debe estar entre las visibles del usuario (en la práctica, desde GE), ser distinta de la origen y tener `activos.create` para el usuario. **Deshacer un traspaso** retira (borrado lógico) el activo recibido solo si sigue `disponible` y no tiene salidas, asignaciones ni líneas de cierre, y si la empresa destino es visible para el usuario y este tiene `activos.delete` en ella (422 `salida` en caso contrario, con mensaje sin datos del activo recibido); un admin desde una URL no corporativa recibe ese 422 y debe hacerlo desde Grupo Espléndido.
- `FixedAssetController`: el estado no se puede poner en `baja` por la edición normal ni cambiar mientras haya salida vigente (422 `status`); un activo con historial de salidas (incluso revertida) o recibido por un traspaso vigente no se borra (422 `asset`), así que un activo de compra mal dado de alta solo se da de baja; el JSON trae `salida_vigente`. `store` rechaza `status = baja` (422 `status`): la baja se registra solo con una salida.
- **R12 (ediciones con salida vigente):** mientras un activo tiene una salida vigente, `FixedAssetController@update` rechaza con 422 (error en la clave del campo; mensaje «No se puede cambiar … mientras el activo tiene una salida registrada; deshaz la salida primero.») cualquier cambio REAL de `status`, `purchase_value`, `purchase_date`, `depreciation_start`, `depreciation_rate`, `salvage_percent`, `useful_life_years`, `category_id` y `subcategory_id`, para que el cierre del mes de la salida siga cuadrando con las cifras congeladas. Enviar el mismo valor (comparado por centavos, día, mes de inicio o entero) no es un cambio; los datos descriptivos (nombre, serie, modelo, factura, observaciones, imagen, ubicación) siguen editables. Riesgo residual: un activo antiguo SIN tasa propia sigue la tasa de su tipo en vivo, así que si GE cambia la tasa del tipo antes de cerrar el mes de la salida, ese cierre se descuadra.
- Al deshacer una salida de un activo que tenía asignación, el activo vuelve a `en_uso` sin asignación (no se recrea).
- API bajo `/{empresa}/administration/activos-fijos/salidas`: `GET` (filtros `type`, `from`, `to`, `reversed`, `enterprise_id`, `search`, `per_page`; `totals` solo suma vigentes), `GET previa` (exige `create`, no `view`), `POST`, `GET {id}`, `DELETE {id} {reason}`, `GET {id}/carta` (solo ventas; el front dibuja el PDF; folio `{código}-V{NN}`, con NN = número de venta del activo contando las revertidas), `POST` y `GET {id}/carta-firmada` (disco privado `local`, `fixed-asset-disposals/{id}/…`, PDF/JPG/PNG de hasta 10 MB, solo ventas vigentes). Permisos del submódulo `salidas` («Bajas y ventas»): `view` lee, `create` registra y sube la carta firmada, `delete` deshace; sin concesión automática (el seeder crea las filas de admin@ y demo@ con `is_granted = false`). Una salida o activo fuera del alcance responde 404.
- Despliegue: respaldo, `php artisan migrate`, `php artisan db:seed --class=FixedAssetsModuleSeeder` (idempotente; crea el submódulo `salidas`), conceder `salidas.*` a mano. Back antes que el front.
- `buyer_address` admite hasta 500 caracteres (la columna es `varchar(500)`; el resto de los `max:` de la validación coinciden con su columna).
- Huecos conocidos: deshacer una salida puede reproducir el hueco de la fase 3 (una pieza retenida de la siguiente entrada si ya se dio salida al stock); el texto de la carta de venta está pendiente de revisión legal; un traspaso copia la tasa y el residual sobre el valor en libros, así que el residual en pesos cambia un poco (se ajusta a mano en el activo nuevo). **Aviso de contabilidad:** un traspaso de un activo ya depreciado reinicia la vida útil COMPLETA en la receptora (el activo nuevo parte del libro con la tasa del origen), así que el grupo puede depreciar por encima de la base original; el spec lo permite, pero hay que confirmarlo con contabilidad. Pendientes menores sin resolver: la fecha de la salida no tiene límite inferior; un traspaso no valida los cierres de la receptora (si ya cerró el mes de inicio, el siguiente cierre absorbe de golpe el atraso); `tasaYResidual` devuelve nulos si el origen está «sin valor» o «sin fecha»; `GeneradorCodigoActivo::siguiente` puede dar deadlock 1213 con dos altas simultáneas en la misma empresa; `subirCartaFirmada` no se serializa con otra subida o con deshacer.
- **Riesgos conocidos de concurrencia:** ni `registrar` ni `deshacer` se serializan con un cierre de depreciación concurrente (`CierreDepreciacion::cerrar` no bloquea activos ni exige meses consecutivos); un cierre simultáneo de un mes igual o posterior a la salida podría incluir o excluir el activo mal (`registrar` también compite con un cierre del mismo mes, porque `cerrar` no bloquea el activo). No se agregaron bloqueos de hueco para no reabrir el deadlock 1213. Lo mismo aplica a deshacer contra un cierre concurrente del mes de la salida o posterior. Deshacer un traspaso desde una URL no corporativa siempre da 422 (`salida`), porque la empresa receptora no es visible: hay que hacerlo desde Grupo Espléndido.

### Listado de activos: filtros y resumen

- `GET activos` acepta `status`, `category_id` y `entity_id` con **varios valores separados por coma** (los `*_id` solo admiten enteros: lo demás se descarta), `assigned=si|no` (con/sin asignación activa), `has_value=si|no` (valor de compra: `si` = `purchase_value > 0`, `no` = nulo o 0, igual que la depreciación), `year=a,b,c` (a = 2020 o antes, b = 2021-2023, c = 2024 en adelante; sin fecha no entra a ningún rango), `sort` (`name` por defecto, `code`, `purchase_date`, `purchase_value`, `status`; lo desconocido cae a `name`) y `dir`. Siempre desempata por nombre e id. Al ordenar por `purchase_date` o `purchase_value` los nulos van siempre al final (asc y desc). Los parámetros que no son escalares (`status[a][]=x`, `search[]=x`, `sort[]=x`, `dir[]=x`) se ignoran en vez de dar 500. El valor en libros se calcula, por eso no se ordena por él.
- `search` busca en nombre, código, serie, modelo, marca, tipo, subtipo y responsable actual (`asignacionActiva.assignee_name`), con escape de `%` y `_`.
- `GET activos/resumen` (declarada **antes** del `apiResource`) devuelve `total` y los grupos `status`, `category`, `entity`, `assigned`, `has_value`, `year` como listas `[{value, count}]` (`category`/`entity` con `label`) más `without_date`. Cada grupo se cuenta con todos los filtros **menos los suyos**; las listas `category` y `entity` salen del **universo** (todo tipo o ubicación con al menos un activo en el alcance del usuario, y en la empresa elegida si viene `enterprise_id`; sin los demás filtros) con `count` = conteo bajo los filtros actuales, 0 cuando no aparecen (el front las deshabilita, no desaparecen). Los ids seleccionados que no están en el universo se ignoran: así `?entity_id=<id de otra empresa>` no revela nombres de ubicaciones ajenas. Las etiquetas se ordenan sin acentos (`Str::ascii`: «Área» con la A, «Ñ» con la N). Las listas (y no objetos con el valor como clave) existen porque el front convierte las claves a camelCase. El filtrado vive en `aplicarFiltros`, compartido por `index` y `resumen`.

### Asignaciones: bandeja

- Servicio `App\Services\ActivosFijos\BandejaAsignaciones` (solo lectura) y controlador `Api/ActivosFijos/BandejaAsignacionesController`. Las tres rutas piden `asignaciones.view`, van **antes** de las rutas con `{asignacion}` y aceptan `enterprise_id` (solo si es una empresa visible; si no, se ignora). Un parámetro no escalar o fuera de lista se ignora (no da 422), a diferencia del índice `GET asignaciones`, que no cambió.
- `GET asignaciones/resumen`: `activas`, `cartas_por_firmar` (activas sin `signed_document_path`), `cartas_atrasadas` (más de `ATRASO_CARTA_DIAS` = 30 días), `entregas_mes`, `devoluciones_mes`, `mes` (`Y-m`, zona `America/Mazatlan`) y `opciones` (`areas` y `tipos` que aparecen en alguna asignación del alcance, ordenados sin acentos; `empresas` visibles solo en GE).
- `GET asignaciones/cartas-pendientes`: activas sin carta, de la más antigua a la más nueva, con `dias_sin_firma`; `limit` (12 por defecto, máximo 50) y `total`.
- `GET asignaciones/movimientos`: `UNION ALL` de entregas (`assigned_at`) y devoluciones (`returned_at`) sobre `fixed_asset_assignments`, paginado (25, máximo 100). Orden (R11): fecha desc, luego id de la asignación desc y, dentro de la misma asignación, la devolución sobre su entrega. Así, con E1 → D1 → E2 → D2 el mismo día se lee D2, E2, D1, E1, y en una reasignación la entrega de la asignación nueva (id mayor) queda sobre la devolución de la anterior. Filtros `tipo` (`entrega`|`devolucion`), `tarjeta` (`activas` y `cartas` solo dan entregas de asignaciones activas; `entregas_mes`/`devoluciones_mes` el mes en curso), `area_id`, `category_id` (tipo del activo) y `search` (responsable, puesto, departamento, código, nombre, serie y marca del activo, área). `meses` trae, para cada mes de la página, las entregas y devoluciones del mes completo con los mismos filtros. Las asignaciones se cargan una vez por id con `BandejaAsignaciones::RELACIONES`.
- Fechas: SQLite guarda las columnas `date` como `Y-m-d 00:00:00`; por eso se comparan con rangos semiabiertos de cadenas `Y-m-d` (`>= día 1` y `< día 1 del mes siguiente`), nunca con `<=` al último día.
- Despliegue: sin migraciones, seeders ni permisos nuevos. Desplegar el back antes de que Netlify publique el front (con el back viejo las tres rutas darían 404); `php artisan optimize:clear` si hay rutas en caché.

## CRM · Dashboards y embudo de oportunidades

- **Historial de etapas** en `crm_oportunidad_etapas` (`CrmOportunidadEtapa`), llenado por `CrmOportunidadObserver` (`#[ObservedBy]` en `CrmOportunidad`): una fila al crear y otra por cada cambio de `etapa`, venga de `cambiarEtapa` o de aprobar una cotización.
  - Las filas `inferido = true` las crea una sola vez `php artisan crm:reconstruir-historial-etapas`, que es idempotente.
  - En esas filas inferidas, las perdidas no saben en qué etapa se cayeron (`etapa_desde = null`, «sin registro»).
  - Los días por etapa solo usan filas no inferidas.
- **`App\Support\CRM\RangoDashboard`**: periodo (`mes_actual`, `mes_anterior`, `trimestre`, `anio`, `personalizado` con `desde`/`hasta`, máximo 366 días) y rango anterior. Los periodos en curso se comparan recortados a los mismos días transcurridos.
- **`EmbudoService`**:
  - `pipeline`: abiertas hoy por etapa, con ponderado.
  - `conversion`: cohorte = creadas en el rango; «llegó» = etapa máxima alcanzada; la mayor fuga solo se marca con 5 o más llegadas.
  - `detalle`: máximo 50 filas, con el total real.
  - Lo usan el Dashboard, `GET crm/oportunidades/resumen` y el bloque `mi_mes` de `GET crm/mi-dia` (`MiMesService`).
- **`GET crm/dashboard/{kpis|embudo|embudo-conversion|tendencia|cumplimiento-metas|cotizaciones|actividad|ranking-vendedores|detalle}`**:
  - Exigen `dashboard.ver`; el ranking exige `ejecutivo`.
  - `vendedor_id` solo se respeta con `ejecutivo`; sin él se fuerza el vendedor propio.
  - `comparar=1` agrega el periodo anterior.
- **Presupuestos:** `montoEsperado` ya es lo ganado (Σ `monto_esperado` de `cerrado_ganado` por `fecha_cierre_real`), aunque su nombre diga «esperado».
- **Despliegue:** `php artisan migrate` y, una sola vez, `php artisan crm:reconstruir-historial-etapas`. El back va antes que el front.

## CRM · Tope de `per_page`

- `CrmBaseController::perPage(Request, int $default, int $max = 200)` acota `per_page` a 1..200: negativo o cero → 1, mayor que 200 → 200, ausente o no numérico → el valor por defecto del listado. Antes `per_page=-1` o uno enorme devolvía la tabla completa de la empresa.
- Lo usan los listados de actividades, bodegas, clientes, contactos, cotizaciones de la empresa (`indexEmpresa`), llamadas de Dialpad, empresas externas, oportunidades, productos, prospectos, regiones, vendedores y zonas. `PlantillaWhatsappController` conserva su propio tope (100). `CotizacionController::index` (por oportunidad) sigue devolviendo todo con `->get()`: está acotado por oportunidad.
- El front pide como máximo 200 (`OportunidadFormModal`), así que ninguna pantalla existente cambia.
- `SincronizarDialpadCommand` solo busca la entidad por teléfono (`resolverEntidad`, 3 consultas) al crear la actividad, no al re-sincronizar una llamada que ya existe.

## CRM · Visibilidad de oportunidades, agenda y cotizaciones

### Oportunidades
- `GET crm/oportunidades`, `GET crm/oportunidades/{id}` y `GET crm/oportunidades/resumen` exigen `oportunidades.ver` (403 sin él).
- Sin `oportunidades.equipo` (perfil vendedor) el usuario solo lee las de su propio `CrmVendedor` activo: el listado y el resumen se fuerzan a él, pedir `vendedor_id` de otro responde 403, `show` de una ajena responde 403 y, sin vendedor vinculado, todo responde 403. Con `equipo` (perfil gerencia) ve a todo el equipo y puede filtrar por vendedor.
- La regla vive en `App\Services\CRM\VisibilidadOportunidades`, con tres sabores:
  - Estricto: `exigirVer`, `vendedorVisible` y `exigirVisible`, que abortan con 403. Lo usan los endpoints de oportunidades y de cotizaciones.
  - `alcance()`: no aborta y devuelve `App\Support\CRM\AlcanceOportunidades` (`puedeVer`, `todas`, `vendedorId`, `aplicar($query)`, `incluye($oportunidad)`). Lo usan los datos incrustados y el listado de actividades.
  - `entidadVisible($entidad)`: devuelve null si la entidad es una oportunidad que el usuario no puede ver. Lo usan las escrituras que se cuelgan de una entidad (agenda, actividades, seguimientos) para responder igual que si no existiera.
  - No la usan, con reglas propias:
    - Dashboard: depende de `dashboard.ejecutivo` (`DashboardController::resolverVendedor`) y ahí no se exige que el vendedor propio esté activo.
    - Presupuestos: `resumenMensual` y `comparativoAnual` suman lo ganado por vendedor, y quien tiene `presupuestos.crear` o `editar` cuenta como gerencia (`PresupuestoController::resolverVendedorId`).
    - Mi día (`mi-dia.ver`) y Dashboard (`dashboard.ver`) muestran las oportunidades propias aunque el usuario no tenga `oportunidades.ver`.
- **Limitado por vendedor:**
  - Las 3 lecturas: `GET crm/oportunidades`, `/{id}` y `/resumen`.
  - Las fichas: `GET crm/clientes/{id}` (`oportunidades`), `GET crm/clientes/{id}/resumen` (`metricas.oportunidades`, `metricas.valor_pipeline` y las 10 últimas) y `GET crm/prospectos/{id}` (`oportunidades`). Sin `ver` las listas salen vacías y las cifras en 0, y siempre se filtran por la empresa. La ficha en sí sigue abierta. Las `actividades` del resumen del cliente son las del cliente (no las de sus oportunidades), así que no cambian.
  - Las escrituras `PUT`, `DELETE` y `PATCH cambiar-etapa`. En `PUT` y `DELETE` el orden es: tenant (404), permiso de la acción (403) y visibilidad (403 «No puedes modificar las oportunidades de otro vendedor.»; también exige `ver`). En `cambiar-etapa` la validación del payload (422) va antes del permiso, porque el permiso (`editar` o `cerrar`) depende de la etapa pedida. Es inofensivo: la validación no depende de la fila.
  - `PUT` y `POST crm/oportunidades`: sin `equipo`, `vendedor_id` solo puede ser el vendedor actual (en `PUT`) o el `CrmVendedor` activo propio (en `POST`). Otro responde 403 «No puedes asignar la oportunidad a otro vendedor.»; en `POST`, sin vendedor activo, 403 «Tu usuario no está vinculado a un vendedor activo.». Con `equipo`, cualquier vendedor de la empresa.
- Nota: los listados de clientes y prospectos (`GET crm/clientes`, `GET crm/prospectos`) no incrustan oportunidades.

### Agenda
- Permiso `agenda.equipo` (módulo `agenda`, submódulo `agenda`, «Ver la agenda de otros vendedores», orden 5). Lo da el perfil gerencia, lo siembra `CrmPermisosSeeder` y lo concede la migración `2026_10_11_100000_add_crm_agenda_equipo_permission`.
- La regla vive en `App\Services\CRM\VisibilidadAgenda` (`veAlEquipo`, `vendedorVisible`, `exigirEventoVisible`). `crear` y `editar` ya NO cuentan como gerencia.
  - Con `equipo`: consulta y asigna la agenda de cualquier vendedor de la empresa.
  - Sin `equipo`: solo la de su `CrmVendedor` ACTIVO. Pedir o tocar la de otro responde 403 «No puedes ver o modificar la agenda de otro vendedor.». Sin vendedor activo vinculado, todo responde 403 «Tu usuario no está vinculado a un vendedor activo.».
- Endpoints (no hay `GET agenda/{id}`):
  - `GET crm/agenda` (también con `vencidos=1`): `vendedor_id` sigue obligatorio; sin `equipo` solo se acepta el propio.
  - `POST crm/agenda`: igual para `vendedor_id`, que además debe ser un vendedor de la empresa (422 si no).
  - `PUT crm/agenda/{id}`, `PATCH crm/agenda/{id}/completar` y `DELETE crm/agenda/{id}`: tenant (404), permiso de la acción (403) y vendedor del evento (403), antes de validar o tocar nada.
- Eventos ligados a una oportunidad: ver «Escrituras que se cuelgan de una oportunidad».

### Cotizaciones
- Leer exige `cotizaciones.ver`; escribir, su propio permiso (`crear`, `editar`, `aprobar`, `rechazar`). En ambos casos además hay que poder VER la oportunidad (`VisibilidadOportunidades`, estricto, que también exige `oportunidades.ver`).
- Orden: tenant (404), permiso (403) y oportunidad visible (403), antes de validar el estado. Un 403 no cambia filas, no emite eventos, no escribe historial de etapas ni cierra la oportunidad.
- Mensajes del 403 por oportunidad ajena: lecturas «No puedes ver las cotizaciones de otro vendedor.»; escrituras «No puedes modificar las cotizaciones de otro vendedor.».
- `GET crm/cotizaciones`: mismo alcance que `GET crm/oportunidades`. Sin `oportunidades.ver`, 403. Sin `equipo`, se fuerza al vendedor propio: pedir otro `vendedor_id` o no tener vendedor activo responde 403. `meta.total` sale de la misma consulta.
- `GET crm/cotizaciones/{id}`, `PUT`, `PATCH enviar`, `PATCH aprobar`, `PATCH rechazar`, `GET` y `POST crm/oportunidades/{id}/cotizaciones`: 403 sobre una oportunidad ajena.
- Una cotización cuya oportunidad está eliminada (soft delete) o no existe solo la ve y la toca quien tiene `oportunidades.equipo`.
- Aprobar o rechazar la cotización de otro vendedor exige `oportunidades.equipo`. El perfil gerencia ya lo tiene y la migración nueva lo concede a quien tenga `cotizaciones.aprobar` o `rechazar`.
- **Decisión consciente y de riesgo:** sin esa concesión, los gerentes configurados a mano como aprobadores perderían la función.
  - Contrapartida: un vendedor configurado a mano con `aprobar` pasa a ver todo el equipo.
  - La migración no concede `oportunidades.ver`.
  - Antes de migrar se revisa la consulta del paso 6 del checklist y se quita `aprobar` o `equipo` caso por caso.

### Escrituras que se cuelgan de una oportunidad
- `POST crm/actividades`, `POST crm/agenda` y `POST crm/seguimientos` con `entidad_tipo=oportunidad` exigen poder ver esa oportunidad (`entidadVisible`; sin `oportunidades.ver` no se ve ninguna).
- Una oportunidad no visible responde EXACTAMENTE igual que una inexistente en cada endpoint, sin crear filas ni emitir eventos. No se unificó el código entre endpoints para no cambiar lo que el front ya maneja:
  - actividades y agenda: 404 «La entidad relacionada no existe o no pertenece a la empresa.».
  - seguimientos: 422 en `entidad_id` «El registro no existe o no pertenece a la empresa.».
- Lecturas de actividades: sin `oportunidades.equipo`, `GET crm/actividades` y `GET crm/{tipo}/{id}/historial` ocultan las actividades de oportunidades que el usuario no ve (de otro vendedor o eliminadas). El historial de una ajena sale vacío, igual que el de una inexistente. `GET`, `PUT` y `DELETE crm/actividades/{id}` de una de ellas responden 404. Las de clientes, prospectos y empresas externas no cambian.

### Sigue abierto dentro del tenant
- Presupuestos: su regla diverge de la de agenda (`presupuestos.crear` o `editar` = gerencia) y el perfil gerencia es el único con esos permisos. No se cambió.
- Dashboard: depende de `dashboard.ejecutivo`, no de `equipo`, y no exige vendedor activo. Su bloque de cotizaciones y de actividad sigue esa regla.
- Mi día con `mi-dia.equipo` ve el día (eventos, oportunidades y cotizaciones por vencer) de otro vendedor. Es un permiso propio de Mi día. Al completar desde Mi día un evento ajeno ya se exige `agenda.equipo`.
- `PATCH crm/agenda/{id}/completar` sobre un evento propio ligado a una oportunidad que después se reasignó a otro vendedor sigue registrando la actividad en esa oportunidad.
- **Evento cruzado creado por gerencia.** Un gerente con `agenda.equipo` y `oportunidades.equipo` puede crear, en la agenda del vendedor A, un evento ligado a una oportunidad del vendedor B (`POST crm/agenda` solo exige que el gerente vea la oportunidad).
  - Después, A ve la oportunidad completa de B en Mi día: `MiDiaService::resumen` carga `entidad` de sus eventos sin filtrar por visibilidad.
  - Al completar el evento (`AgendaController::completar`), A registra la actividad y programa el siguiente evento sobre esa oportunidad.
  - No se arregló en código.
- **Agenda de otro vendedor solo con `mi-dia.equipo`.** Estos endpoints programan eventos en la agenda de otro vendedor sin exigir `agenda.equipo`:
  - `POST crm/seguimientos` con `siguiente`.
  - `POST crm/prospectos/rapido` con `primer_contacto`.
  - Resuelven el vendedor con `VendedorActualService::resolver`, que solo mira `mi-dia.equipo`, y exigen `agenda.crear`.
  - En el despliegue la migración concede `agenda.equipo` a quien tiene `mi-dia.equipo`, así que quedan alineados. Pero si después se revoca `agenda.equipo` y se deja `mi-dia.equipo`, siguen funcionando.
  - No se cambió el código.
- `POST/PATCH crm/actividades` validan `vendedor_id` con `exists:crm_vendedores,id` sin filtrar por empresa.
- Riesgos previos:
  - Resuelto en el plan 1 de vacaciones: `VerificaPermisoSubmodulo::tienePermisoSubmodulo` delega en `App\Support\PermisoSubmodulo::tiene`, que exige que el `submodule_id` del tipo de permiso coincida con el del submódulo. Una concesión cruzada ya no abre el permiso. **Comprobación de despliegue:** las concesiones cruzadas existentes con `is_granted = 1` dejan de honrarse; revisarlas antes de desplegar (lo que antes funcionaba por una concesión cruzada ahora dará 403).
  - Resuelto: `per_page` ya tiene tope en todos los listados paginados del CRM (ver «Tope de `per_page`»).
  - `GET crm/actividades` (y el historial) no exigen `actividades.ver`. Solo se filtran por empresa y por la visibilidad de oportunidades.
- Canales en tiempo real: `module.{empresa}.crm.oportunidades`, `…crm.cotizaciones`, `…crm.agenda` y `…crm.actividades` difunden el registro completo a todos los que tienen acceso al módulo. Aunque el front lo ignore, sigue visible en la pestaña Red del navegador. Es un riesgo residual aceptado hasta tener canales por vendedor o un payload recortado.

### Despliegue
1. `php artisan migrate`. Corren, en este orden:
   - `2026_10_09_100000_add_crm_oportunidades_equipo_permission`: crea `oportunidades.equipo` y lo concede, por empresa, a quien en ese CRM ya tiene `oportunidades.cerrar`, `dashboard.ejecutivo` o `mi-dia.equipo`.
   - `2026_10_11_100000_add_crm_agenda_equipo_permission`, en dos pasos y por empresa:
     1. Crea `agenda.equipo` y lo concede a quien ya tiene `agenda.eliminar`, `mi-dia.equipo`, `oportunidades.cerrar` o `dashboard.ejecutivo`. Las tres últimas son las fuentes por las que la migración `2026_10_09` ya concedió `oportunidades.equipo`. Por eso, en el primer despliegue, el resultado equivale a «quien ya ve al equipo».
        - `oportunidades.equipo` NO cuenta como fuente, a propósito: el paso 2 se lo concede a los aprobadores. Si contara, una segunda corrida (o `down()` + `up()`) les daría también `agenda.equipo`.
        - Con estas fuentes, volver a correrla da siempre el mismo resultado.
        - Quien solo tiene `oportunidades.equipo` dado a mano no recibe el de agenda.
     2. Concede `oportunidades.equipo` a quien tiene `cotizaciones.aprobar` o `cotizaciones.rechazar` (ver el paso 6 del checklist).
   - Ninguna reactiva una fila `equipo` revocada (`is_granted = 0`).
2. Listar quién recibirá 403 en el tablero de oportunidades. No hay bypass de superadmin en `tienePermisoSubmodulo`, así que la cuenta dueña suele salir aquí. Usuarios con `ver` concedido, sin `equipo` y sin `crm_vendedores` activo en esa empresa:
   ```sql
   SELECT e.id AS empresa_id, e.name AS empresa, u.id AS user_id, u.name, u.email, u.role
   FROM user_submodule_permissions usp
   JOIN submodule_permission_types spt ON spt.id = usp.permission_type_id AND spt.slug = 'ver'
   JOIN submodules s   ON s.id = usp.submodule_id AND s.slug = 'oportunidades'
   JOIN modules m      ON m.id = s.module_id AND m.slug = 'oportunidades'
   JOIN applications a ON a.id = m.application_id AND a.slug = 'crm'
   JOIN enterprises e  ON e.id = a.enterprise_id
   JOIN users u        ON u.id = usp.user_id
   WHERE usp.is_granted = 1
     AND NOT EXISTS (
       SELECT 1
       FROM user_submodule_permissions eq
       JOIN submodule_permission_types eqt ON eqt.id = eq.permission_type_id AND eqt.slug = 'equipo'
       WHERE eq.user_id = usp.user_id AND eq.submodule_id = usp.submodule_id AND eq.is_granted = 1
     )
     AND NOT EXISTS (
       SELECT 1 FROM crm_vendedores v
       WHERE v.empresa_id = a.enterprise_id AND v.user_id = usp.user_id AND v.activo = 1
     )
   ORDER BY e.name, u.name;
   ```
   Estos mismos usuarios reciben 403 en `GET crm/cotizaciones`.
3. Usuarios con acceso activo al submódulo oportunidades (ven la entrada del menú) pero sin `ver` concedido:
   ```sql
   SELECT e.id AS empresa_id, e.name AS empresa, u.id AS user_id, u.name, u.email, u.role
   FROM user_submodule_access usa
   JOIN submodules s   ON s.id = usa.submodule_id AND s.slug = 'oportunidades'
   JOIN modules m      ON m.id = s.module_id AND m.slug = 'oportunidades'
   JOIN applications a ON a.id = m.application_id AND a.slug = 'crm'
   JOIN enterprises e  ON e.id = a.enterprise_id
   JOIN users u        ON u.id = usa.user_id
   WHERE usa.is_active = 1
     AND NOT EXISTS (
       SELECT 1
       FROM user_submodule_permissions usp
       JOIN submodule_permission_types spt ON spt.id = usp.permission_type_id AND spt.slug = 'ver'
       WHERE usp.user_id = usa.user_id AND usp.submodule_id = usa.submodule_id AND usp.is_granted = 1
     )
   ORDER BY e.name, u.name;
   ```
4. Usuarios con `ver` concedido, vendedor activo y sin `equipo`. Pasan de ver a todo el equipo a ver solo lo suyo, sin 403. Hay que confirmar que ninguno sea en realidad un gerente al que le falta el perfil gerencia:
   ```sql
   SELECT e.id AS empresa_id, e.name AS empresa, u.id AS user_id, u.name, u.email, u.role, v.id AS vendedor_id, v.nombre AS vendedor
   FROM user_submodule_permissions usp
   JOIN submodule_permission_types spt ON spt.id = usp.permission_type_id AND spt.slug = 'ver'
   JOIN submodules s   ON s.id = usp.submodule_id AND s.slug = 'oportunidades'
   JOIN modules m      ON m.id = s.module_id AND m.slug = 'oportunidades'
   JOIN applications a ON a.id = m.application_id AND a.slug = 'crm'
   JOIN enterprises e  ON e.id = a.enterprise_id
   JOIN users u        ON u.id = usp.user_id
   JOIN crm_vendedores v ON v.empresa_id = a.enterprise_id AND v.user_id = usp.user_id AND v.activo = 1
   WHERE usp.is_granted = 1
     AND NOT EXISTS (
       SELECT 1
       FROM user_submodule_permissions eq
       JOIN submodule_permission_types eqt ON eqt.id = eq.permission_type_id AND eqt.slug = 'equipo'
       WHERE eq.user_id = usp.user_id AND eq.submodule_id = usp.submodule_id AND eq.is_granted = 1
     )
   ORDER BY e.name, u.name;
   ```
5. Agenda. Después de `migrate`, usuarios con `agenda.crear` o `agenda.editar` concedido, sin `agenda.equipo` y sin `crm_vendedores` activo en esa empresa. Antes podían ver y escribir la agenda de cualquiera y ahora reciben 403 en toda la agenda. Antes de migrar, la misma consulta da de más: incluye a quien la migración le va a conceder `equipo`.
   ```sql
   SELECT DISTINCT e.id AS empresa_id, e.name AS empresa, u.id AS user_id, u.name, u.email, u.role
   FROM user_submodule_permissions usp
   JOIN submodule_permission_types spt ON spt.id = usp.permission_type_id AND spt.slug IN ('crear', 'editar')
   JOIN submodules s   ON s.id = usp.submodule_id AND s.slug = 'agenda'
   JOIN modules m      ON m.id = s.module_id AND m.slug = 'agenda'
   JOIN applications a ON a.id = m.application_id AND a.slug = 'crm'
   JOIN enterprises e  ON e.id = a.enterprise_id
   JOIN users u        ON u.id = usp.user_id
   WHERE usp.is_granted = 1
     AND NOT EXISTS (
       SELECT 1
       FROM user_submodule_permissions eq
       JOIN submodule_permission_types eqt ON eqt.id = eq.permission_type_id AND eqt.slug = 'equipo'
       WHERE eq.user_id = usp.user_id AND eq.submodule_id = usp.submodule_id AND eq.is_granted = 1
     )
     AND NOT EXISTS (
       SELECT 1 FROM crm_vendedores v
       WHERE v.empresa_id = a.enterprise_id AND v.user_id = usp.user_id AND v.activo = 1
     )
   ORDER BY e.name, u.name;
   ```
   En la BD local de desarrollo (sin ninguna de las dos migraciones aplicadas) da 1 fila.
6. Cotizaciones:
   - Usuarios con `cotizaciones.ver` y sin `oportunidades.ver` en el mismo CRM. Reciben 403 en todas las cotizaciones (local: 0).
     ```sql
     SELECT DISTINCT e.id AS empresa_id, e.name AS empresa, u.id AS user_id, u.name, u.email, u.role
     FROM user_submodule_permissions usp
     JOIN submodule_permission_types spt ON spt.id = usp.permission_type_id AND spt.slug = 'ver'
     JOIN submodules s   ON s.id = usp.submodule_id AND s.slug = 'cotizaciones'
     JOIN modules m      ON m.id = s.module_id AND m.slug = 'cotizaciones'
     JOIN applications a ON a.id = m.application_id AND a.slug = 'crm'
     JOIN enterprises e  ON e.id = a.enterprise_id
     JOIN users u        ON u.id = usp.user_id
     WHERE usp.is_granted = 1
       AND NOT EXISTS (
         SELECT 1
         FROM user_submodule_permissions ov
         JOIN submodule_permission_types ovt ON ovt.id = ov.permission_type_id AND ovt.slug = 'ver'
         JOIN submodules os ON os.id = ov.submodule_id AND os.slug = 'oportunidades'
         JOIN modules om    ON om.id = os.module_id AND om.slug = 'oportunidades' AND om.application_id = a.id
         WHERE ov.user_id = usp.user_id AND ov.is_granted = 1
       )
     ORDER BY e.name, u.name;
     ```
   - **Antes de migrar**, aprobadores que no son gerencia: tienen `aprobar` o `rechazar` concedido y no tienen `oportunidades.cerrar`, `dashboard.ejecutivo` ni `mi-dia.equipo`. La migración les va a conceder `oportunidades.equipo`, así que pasan a ver las oportunidades de todo el equipo. `tiene_oportunidades_ver = 0` marca a quien, además, no tiene `oportunidades.ver`: la migración no lo concede, y sin él sigue recibiendo 403 en cotizaciones y oportunidades.
     - Revisar cada fila y, caso por caso, quitarle `aprobar` o `rechazar` (si es un vendedor), o quitarle `oportunidades.equipo` después de migrar.
     - Local: 0.
     - Si después de migrar a un aprobador le queda una fila `equipo` revocada, la migración no lo toca, y solo aprueba las cotizaciones de sus propias oportunidades.
     ```sql
     SELECT DISTINCT e.id AS empresa_id, e.name AS empresa, u.id AS user_id, u.name, u.email, u.role,
       EXISTS (
         SELECT 1
         FROM user_submodule_permissions g
         JOIN submodule_permission_types gt ON gt.id = g.permission_type_id AND gt.slug = 'ver'
         JOIN submodules gs ON gs.id = g.submodule_id AND gs.slug = 'oportunidades'
         JOIN modules gm    ON gm.id = gs.module_id AND gm.slug = 'oportunidades' AND gm.application_id = a.id
         WHERE g.user_id = usp.user_id AND g.is_granted = 1
       ) AS tiene_oportunidades_ver
     FROM user_submodule_permissions usp
     JOIN submodule_permission_types spt ON spt.id = usp.permission_type_id AND spt.slug IN ('aprobar', 'rechazar')
     JOIN submodules s   ON s.id = usp.submodule_id AND s.slug = 'cotizaciones'
     JOIN modules m      ON m.id = s.module_id AND m.slug = 'cotizaciones'
     JOIN applications a ON a.id = m.application_id AND a.slug = 'crm'
     JOIN enterprises e  ON e.id = a.enterprise_id
     JOIN users u        ON u.id = usp.user_id
     WHERE usp.is_granted = 1
       AND NOT EXISTS (
         SELECT 1
         FROM user_submodule_permissions g
         JOIN submodule_permission_types gt ON gt.id = g.permission_type_id AND gt.submodule_id = g.submodule_id
         JOIN submodules gs ON gs.id = g.submodule_id
         JOIN modules gm    ON gm.id = gs.module_id AND gm.application_id = a.id
         WHERE g.user_id = usp.user_id AND g.is_granted = 1
           AND (   (gm.slug = 'oportunidades' AND gs.slug = 'oportunidades' AND gt.slug = 'cerrar')
                OR (gm.slug = 'dashboard'     AND gs.slug = 'dashboard'     AND gt.slug = 'ejecutivo')
                OR (gm.slug = 'mi-dia'        AND gs.slug = 'mi-dia'        AND gt.slug = 'equipo'))
       )
     ORDER BY e.name, u.name;
     ```
7. Para cada fila de los pasos 2, 3 y 5, una de tres: conceder `equipo` (o `ver`, en el paso 3), vincular o reactivar su `CrmVendedor`, o aceptar el 403. En el paso 4, a quien sea gerente se le concede `equipo` o se le aplica el perfil gerencia.
- **Orden:** `php artisan migrate` va en el mismo despliegue que el código. Mientras no corre, nadie tiene `equipo`: la gerencia queda limitada a su propio vendedor (oportunidades, cotizaciones, agenda) o recibe 403 si no tiene uno vinculado.
- **Front y back de versiones distintas:**

  | Front | Back | Resultado |
  |---|---|---|
  | viejo | nuevo | Los vendedores ven el tablero limitado o vacío (403). El tiempo real del front viejo sigue insertando tarjetas de otros vendedores hasta recargar. «Reasignar vendedor» y crear una oportunidad para otro vendedor responden 403. En la agenda, el vendedor ve la suya. El selector de vendedor del front viejo puede ofrecer a otros vendedores y esa consulta responde 403. La gerencia necesita `agenda.equipo`, que da la migración. Las cotizaciones de otros vendedores responden 403 o no aparecen en el listado. |
  | nuevo | viejo | Funciona con las reglas viejas del back. Como nadie tiene `equipo` (oportunidades ni agenda), todo lo que el front nuevo decida con esos permisos trata a todos como vendedores. Por ejemplo, si oculta el selector de vendedor de la agenda sin `agenda.equipo`, la gerencia no lo ve aunque el back viejo lo aceptaría. En oportunidades, cada evento en tiempo real recarga la lista completa (unos 800 ms de espera). |

  La ventana probable es front nuevo con back viejo: Netlify despliega al empujar y el back se despliega a mano.
- **Reversión:**
  - `down()` de `2026_10_09_100000` borra TODOS los tipos y concesiones `equipo` de oportunidades, incluidos los del seeder, los de un perfil o los dados a mano. No toca el `equipo` de mi-dia ni el de agenda.
  - `down()` de `2026_10_11_100000` borra TODOS los tipos y concesiones `equipo` de agenda. Las concesiones de `oportunidades.equipo` que hizo a los aprobadores se quedan, porque no se distinguen de las demás.
  - Si se revierte una migración sin revertir el código, la gerencia pierde la vista del equipo: hay que revertir primero el código, o no revertir la migración.

## CRM · WhatsApp Fase 0 (sin API)

- Botón «WhatsApp» en fichas, contactos, oportunidad, Mi día y cotizaciones. Abre el WhatsApp del propio vendedor con `https://wa.me/{numero}?text=…`. Si el vendedor confirma que envió el mensaje, el front registra una actividad `tipo = whatsapp`, `fuente = whatsapp_enlace` con `POST crm/seguimientos`. El CRM no envía nada ni sabe si el mensaje salió: el registro es declarado. Spec: `docs/superpowers/specs/2026-10-12-crm-whatsapp-fase0-design.md` (raíz de SENTINEL3.0).
- `App\Support\CRM\TelefonoWhatsApp::normalizar()`: texto libre → dígitos con país (`PAIS_DEFECTO = '52'`). 10 dígitos → `52` + número; con `+` o `00` es internacional (8 a 15 dígitos); 12 dígitos que empiezan con `52` quedan igual; 13 que empiezan con `521` → `52` + los últimos 10. Extensiones o letras → `null` (no se adivina). Antes de validar quita los caracteres invisibles de formato (`\p{Cf}`: marcas de dirección U+202A/U+202C, BOM) y trata U+00A0 como espacio y U+2010 a U+2015 como guion. Un código de país que empieza con `0` (`+0052…`, `000052…`) o UTF-8 inválido → `null`.
- Plantillas por empresa en `crm_plantillas_whatsapp` (`CrmPlantillaWhatsapp`, sin SoftDeletes).
  - Variables cerradas en `App\Support\CRM\PlantillaWhatsappVariables::VARIABLES`: `nombre`, `entidad`, `vendedor`, `empresa`, `oportunidad`, `monto`, `folio`, `total`. Una desconocida responde 422 en `cuerpo`.
  - `nombre` y `cuerpo` con UTF-8 inválido (posible en un formulario, no en JSON) → 422 con `PlantillaWhatsappVariables::MENSAJE_TEXTO_INVALIDO`; sin esa regla el `Loggable` daba 500. `usadas()`, `desconocidas()` y `sustituir()` lanzan `InvalidArgumentException` ante UTF-8 inválido y nunca devuelven `mensaje` null; `renderizar` lo convierte en 422 en `plantilla_id`.
  - **Unicidad de `nombre` por empresa:** la decide la collation. En MySQL (`utf8mb4_unicode_ci`) «Seguimiento», «seguimiento» y «Seguimiénto» chocan; en SQLite (pruebas) no. Dos altas simultáneas con el mismo nombre pueden pasar la regla `unique` y la segunda chocar con el índice `empresa_id + nombre`: hoy eso es un 500, no un 422.
  - Las 4 predeterminadas están en `PlantillasWhatsappPredeterminadas` y se siembran solo si la empresa no tiene ninguna: la migración para las empresas existentes y `CrmPlantillasWhatsappSeeder` (en `DatabaseSeeder`) para las nuevas. `CrmPermisosSeeder` no siembra plantillas a propósito: lo corren casi todas las pruebas.
- Endpoints:
  - `GET crm/plantillas-whatsapp`: lectura abierta en el tenant. `?all=1` devuelve solo las activas, sin paginar. Sin `all`, paginado con `per_page` de 1 a 100. `search` solo cuenta si es texto (un arreglo se ignora) y busca `%` y `_` literalmente (`LIKE … ESCAPE '!'`, válido en MySQL y SQLite).
  - `POST`, `PUT` y `DELETE crm/plantillas-whatsapp/{id}` exigen `catalogos.plantillas-whatsapp` `crear`, `editar` o `eliminar`. Una de otra empresa responde 404. Sin evento de tiempo real.
  - `GET crm/whatsapp/destinatarios` y `POST crm/whatsapp/renderizar` (`WhatsAppController` + `WhatsAppMensajeService`) exigen `actividades.crear`.
    - Entidad de otra empresa u oportunidad no visible (`VisibilidadOportunidades::entidadVisible`) → 404.
    - La oportunidad se resuelve a su cliente (o prospecto) para teléfono y contactos; la empresa externa solo lista contactos. Un cliente o prospecto de otra empresa (FK corrupta) se descarta en `WhatsAppMensajeService::titular()`: nunca salen teléfonos de otro tenant.
    - `cotizacion_id` exige `cotizaciones.ver`, una oportunidad viva, de la misma empresa y visible, y que la cotización sea de esa entidad; si no, 404 (siempre el mismo, sin folio ni total). Cotización u oportunidad eliminadas (soft delete) → 404.
    - Plantilla inactiva o ajena → 404. `destinatario_clave` (`entidad` o `contacto:{id}`) que no es del registro → 422.
    - Las variables sin dato se sustituyen por cadena vacía y se listan en `variables_faltantes`. Dinero: `$12,345.00`. `{{nombre}}` es el primer nombre del contacto, o el nombre completo del cliente o prospecto.
  - `POST crm/seguimientos` acepta `actividad.fuente` ∈ `SeguimientoService::FUENTES_REGISTRO` (`manual`, `whatsapp_enlace`); por defecto `manual`. Con `whatsapp_enlace` la fecha la pone el servidor (ver «Hora del registro») y `actividad.tipo` tiene que ser `whatsapp` (otro tipo → 422 en `actividad.tipo`); con `manual` se admite cualquier tipo, como antes. `POST crm/actividades` sigue forzando `manual`.
- Permisos: submódulo `catalogos.plantillas-whatsapp` («Plantillas de WhatsApp», orden 7) en `CrmPermisosSeeder` y en el perfil administración. La migración `2026_10_12_200100_add_crm_plantillas_whatsapp_submodule` lo crea en los CRM existentes. A quien ya tiene `catalogos.configuracion-comercial.editar` le concede ver/crear/editar/eliminar y el acceso al submódulo (sin acceso no ve el menú). No toca filas existentes ni revive revocadas. Su `down()` quita el submódulo con sus concesiones y accesos, pero no borra plantillas.
- **Despliegue:** `php artisan migrate` (tabla, submódulo, permisos y plantillas). Va antes que el front: con el front nuevo y el back viejo el botón responde 404 en `destinatarios` y el modal muestra «Reintentar». Después, comprobar que el administrador ve «Plantillas de WhatsApp» en Catálogos.
- **Pendiente manual:** probar en un celular real que `wa.me/52XXXXXXXXXX` abre el chat de un móvil mexicano.
- **Adopción** por vendedor y semana:
  ```sql
  SELECT vendedor_id, YEAR(fecha_actividad) a, WEEK(fecha_actividad) s, COUNT(*) n
  FROM crm_actividades WHERE fuente = 'whatsapp_enlace'
  GROUP BY vendedor_id, a, s ORDER BY a DESC, s DESC;
  ```
  Se compara con las actividades `tipo = 'whatsapp'` y `fuente = 'manual'` de las semanas previas.
- Hora del registro: con `fuente = whatsapp_enlace`, `actividad.fecha_actividad` es opcional y se ignora; el back guarda `now()` (zona de la app, `America/Mazatlan`), porque la actividad se registra en el momento en que el vendedor confirma. Así un dispositivo en otro huso (CDMX va una hora adelante) no recibe 422. Con `manual` (y `fuente` nula) la fecha es **opcional**: `nullable|date|before_or_equal:now+5min`. Sin fecha (ausente, `null` o vacía) `SeguimientoService::crearActividad` guarda `now()`; con fecha pasada se respeta tal cual y con fecha futura (más de 5 minutos) responde 422 en `actividad.fecha_actividad`. Es el parche C del huso: el front ya no manda la fecha mientras el usuario no la edite, así que el valor por defecto lo pone el servidor en su zona. Si el usuario sí la edita, el riesgo del huso sigue abierto (Laravel guarda los dígitos recibidos sin convertir zonas); la solución completa (instantes absolutos) queda pendiente.

## Acceso cruzado de entidades (cross-enterprise)
Tabla pivot `enterprise_entity` (`enterprise_id`, `entity_id`, `access_level: read|write`) — permite que una empresa acceda a bodegas/plantas de otra. Ver `EntityAccessController` (Admin).

## Vacaciones (RH Grupo Espléndido)

- Reglas en `App\Services\RH\VacationRequestService` (crear, calcular, reprogramar, cancelar, aprobarPaso, rechazar, resolverComoRh). Lo usan `VacationController` (RH), `ProfileController` y `PendingApprovalController`; ningún controlador repite reglas. Las fallas lanzan `App\Exceptions\RH\VacacionesException` (se responde sola: 422 o 403).
- Saldo **acumulado sin vencimiento** (`VacationCalculatorService::diasDisponibles`): días LFT de todos los años cumplidos («Vacaciones Dignas 2023»: 12, 14, 16, 18, 20, 22 de 6 a 10 años, +2 por quinquenio) + `vacation_balances.adjustment_days` − aprobadas − pendientes. Usados y pendientes salen de `vacation_requests`; los contadores `used_days`/`pending_days` de `vacation_balances` ya no se leen (la migración `2026_10_15_100100` los concilió y dejó la diferencia como ajuste).
- Días que se descuentan: `WorkingDaysCalculator` (horario del empleado → predeterminado de su empresa en `enterprise_work_schedule.is_default` → lunes a viernes) menos festivos (`holidays`, fechas concretas; `enterprise_id` nulo = todas). `php artisan vacaciones:festivos {año}` carga los del art. 74 LFT.
- Reglas: antigüedad ≥ 1 año (RH puede omitirla), no pasar del saldo, sin empalmes con pendientes/aprobadas, rango ≤ 60 días naturales, al menos 1 día descontable; RH puede capturar fechas pasadas. `cancelar` bloquea solo la fila de la solicitud (no al empleado): únicamente libera días. `excluir_id` (reprogramar) se limita al empleado y el llamador no puede sobrescribirlo.
- Aprobación en cadena (solo vacaciones): `approval_flow_steps` del proceso `vacation_requests`; mismo `step_order` = alternativas, distinto = en serie. Tipos propios de vacaciones (422 en otros procesos): `direct_supervisor` (`employees.reports_to`), `department_manager` («Jefe del departamento»: `departments.manager_id` del solicitante; si el departamento no trae `manager_id` cargado se consulta) y `user` («Usuario específico»: `approval_flow_steps.user_id`; no necesita ser empleado, y si lo es debe estar activo; las listas se limpian al cambiar de tipo). La cadena se congela en `vacation_request_approvals` al crear.
- Un «usuario específico» sin empleado ligado ve y resuelve su paso desde la bandeja (`PendingApprovalController::entradaVacacionesSinEmpleado`; `approve`/`reject` de `vacation_request` ya no exigen empleado, el servicio valida el paso). `canApproveProcess` de vacaciones también acepta a quien es jefe de algún departamento o usuario específico de una regla. `AprobadorVacaciones` decide quién puede (patrón de `AprobadorOrdenCompra`: corporativo solo por regla de puesto; nadie aprueba lo suyo ni dos pasos). Un paso sin elegibles con usuario se salta; si ninguno aprobó, la solicitud espera en el paso final de RH. Quien tiene `aprobar` puede resolver como RH en cualquier paso.
- Solicitud propia: `AprobadorVacaciones::esSolicitante(User, VacationRequest)` es verdadero si el `user_id` del empleado de la solicitud es el usuario, o si el empleado del usuario (consultado en fresco) es el solicitante (`employees.user_id` no es único). Lo usan `puedeDecidir` y `resolverComoRh`.
- Bandeja de pendientes: `vacacionesPara` revisa todas las solicitudes pendientes con `current_step` (sin tope de 200) y memoiza por usuario dentro de la instancia del controlador; la lista de la bandeja muestra hasta 50 después de filtrar. Los usuarios de RH sin empleado ligado resuelven desde la pantalla de Vacaciones de RH, no desde la bandeja.
- El perfil lista solicitudes con `vacation_year` ≥ año actual.
- Permisos de RH → Gestión → Vacaciones: `view`, `create`, `edit`, `aprobar` (`App\Support\PermisoSubmodulo`, que también usa el trait `VerificaPermisoSubmodulo` del CRM con la aplicación como parámetro). `PermisoSubmodulo` solo cuenta un permiso concedido cuyo tipo pertenezca al mismo submódulo (aplica también al CRM).
- Migración de permisos `2026_10_15_100500`: solo la empresa corporativa, RH → Gestión → Vacaciones. A los usuarios con acceso activo les fuerza `view/create/edit` como concedidos (los valores por defecto del seeder esta API nunca los aplicó) y les crea `aprobar` concedido salvo que ya exista una fila.
- Aprobar crea `attendance_records` con `status = vacation` (respeta los días con entrada); cancelar una aprobada futura los borra.
- PIN del empleado (checador y portal): hasheado (cast `hashed`, unidireccional), fuera de `activity_logs`, bloqueo de 15 min tras 5 errores (`VerificadorPinEmpleado`). RH solo lo ve al generarlo o regenerarlo; regenerar también desbloquea. Las bitácoras anteriores pueden contener PINs en claro (pendiente de limpieza).
- Hueco conocido: `GET vacaciones/balance` sigue devolviendo los contadores viejos por año (la vista usa `empleado/{id}/info`).
- Portal del empleado (`/api/portal-vacaciones`, fuera de `auth:sanctum`): `Api\Portal\VacationPortalController`. Login con número + PIN (`VerificadorPinEmpleado`, mismo bloqueo que el checador) → token propio de 64 caracteres; en `vacation_portal_sessions` solo va su sha256. El middleware `portal.vacaciones` (`AuthenticatePortalSession`) lee `X-Portal-Token`, caduca a las 2 h o tras 15 min sin uso, exige empleado activo y deja el empleado en `$request->attributes->get('portal_employee')`; nunca consulta Sanctum ni `device_pairings` (un token del portal no abre rutas internas ni al revés).
- Lo que sale del portal pasa por `App\Support\RH\PresentadorPortalVacaciones` (lista blanca: nombre, número, empresa, puesto, departamento, ingreso, saldo y solicitudes propias; de quien decidió, solo el nombre). Solicitud ajena o inexistente → 404 «Solicitud no encontrada.». Las reglas son las de `VacationRequestService` (`source = portal`, sin capturista).
- Límites: login `throttle:10,1,portal-login` por IP; el resto, limitador `portal-vacaciones` de `AppServiceProvider` (60/min por sesión, no por IP: la oficina comparte IP).
- Regenerar el PIN, dar de baja o borrar al empleado revoca sus sesiones del portal (`SesionPortalVacaciones::revocarTodas`). `vacaciones:limpiar-sesiones-portal` corre a diario (03:30) y borra las vencidas o revocadas hace más de 7 días.
- Avisos al empleado (plan 3): `AvisosVacaciones` (después de confirmar la transacción) manda el correo con `CorreoVacaciones` → `App\Mail\RH\VacationStatusMail` (eventos `recibida`, `aprobada`, `rechazada`, `cancelada_por_rh`; solo con email válido; nunca lleva el PIN; el link sale de `config('vacaciones.portal_url')` = `FRONTEND_URL` + `/vacaciones`). Un fallo de SMTP no rompe la operación: queda en `vacation_notifications.error` y en el log. `VACACIONES_CORREO_EN_COLA=true` lo manda por la cola (`EnviarCorreoVacacionesJob`, necesita worker; por defecto se envía en la misma petición). Los textos de correo y WhatsApp viven en `App\Support\RH\TextosVacaciones`.
- WhatsApp manual: `VacationAvisoController` (`GET {id}/whatsapp-mensaje`, `POST {id}/avisos/whatsapp`, `GET {id}/avisos`) en `grupoesplendido/rh/vacaciones` y, para `whatsapp-mensaje` y `avisos/whatsapp`, también en `pending-approvals/vacation_request/{id}/…`. Autoriza el propio controlador: RH con `edit` (o `view` para el historial) o quien decidió algún paso de esa solicitud; el teléfono sale normalizado con `TelefonoWhatsApp` (celular, si no teléfono; `null` si ninguno sirve). `vacation_notifications` registra correo y WhatsApp (`sent_by` nulo = sistema).
- Festivos: `HolidayController` (`grupoesplendido/rh/festivos`, permisos del submódulo RH → Gestión → Festivos: `view/create/edit/delete`; `mis-permisos` para el front). La unicidad (fecha, empresa o «todas») se valida en la aplicación porque el índice único de MySQL deja pasar varios `NULL`. Cambiar un festivo solo afecta solicitudes nuevas. `GET vacaciones/mis-permisos` hace lo mismo para Vacaciones.
- Tiempo real: `VacationRequestUpdated` se emite siempre a `module.grupoesplendido.rh.gestion` (un módulo real, el único que `ModuleChannel` autoriza para RH) y a `employee.{id}.vacations`. El canal `module.grupoesplendido.administration.rh` que decía el spec no existe.
- `GET /api/profile` trae `vacation.requests[].revision` (`paso_actual`, `total_pasos`, `revisor_actual`, `pasos`) con el mismo presentador del portal. `GET vacaciones` de RH incluye `employee.department`.
- Cierre de pendientes del plan 1: `reprogramar` avisa siempre al aprobador del paso actual; `VacationBalance::applyAdjustment` suma con bloqueo y conserva el motivo nuevo; «Jefe directo» solo se acepta en el proceso `vacation_requests` (422 en otros).

## Agrícola · Plan de siembra

- `POST /api/splendidfarms/operacion-agricola/agricola/etapas/registrar-siembra` (`EtapaController::registrarSiembra`) registra la siembra real de varias etapas a la vez. Cuerpo: `{ temporada_id, etapa_ids: int[], fecha_siembra_real: "Y-m-d" }`. Responde 200 `{ success, message: "N siembras registradas", data: Etapa[] }` con las mismas relaciones que el `index` de etapas (lote con productor y zona, variedad y tipo de variedad).
- Reglas (422 con la clave del campo): `temporada_id` existente; `etapa_ids` de 1 a 100, enteros, sin repetir (el error cae en `etapa_ids.N`) y sin etapas borradas; `fecha_siembra_real` en `Y-m-d` y no futura (`before_or_equal:today`, la app corre en `America/Mazatlan`). Ya dentro de la transacción: todas las etapas deben pertenecer a un lote activo de la temporada («Alguna etapa no pertenece a la temporada.») y ninguna puede tener ya `fecha_siembra_real` («L1-E2 ya tiene siembra registrada.» / «L1-E2, L1-E3 ya tienen siembra registrada.», por `etapa_ids`).
- Todo o nada: una sola `DB::transaction` con las etapas bloqueadas (`lockForUpdate`, en orden de id). Si una falla no se toca ninguna. Cada etapa se guarda con `update()` para que `Loggable` deje bitácora.
- Ciclo: días entre `fecha_siembra_estimada` y `fecha_cosecha_estimada` de cada etapa; `fecha_cosecha_proyectada` = fecha de siembra real + ciclo, o `null` si la etapa no trae las dos estimadas.
- Hueco conocido: `EtapaController` no revisa permisos de submódulo (cualquier usuario autenticado de la empresa puede llamarlo, igual que el resto de sus acciones).
- Tiempo real: `store`, `update`, `destroy` y `registrarSiembra` emiten `App\Events\EtapaUpdated` (`etapa.updated`) por el canal fijo `module.splendidfarms.operacion-agricola.agricola` (no por las cabeceras `X-*`; lo autoriza `ModuleChannel`). `data.etapas` siempre es una lista: en `created`/`updated` va la etapa completa (mismas relaciones que el `index`) más `temporada_ids`, las temporadas donde su lote está activo; en el registro en bloque, `updated` con parches `{ id, fecha_siembra_real, fecha_cosecha_proyectada, updated_at }` en grupos de `PARCHES_POR_EVENTO` (40), porque Reverb rechaza mensajes de más de 10 KB; en `deleted`, `{ id, lote_id }`. Sin `toOthers()` (el front no manda `X-Socket-ID`): quien escribe también recibe su evento y el front lo aplica sin duplicar. Se emite con `difundir()` (try/catch, como en el CRM): un Reverb caído no convierte en 500 una escritura guardada. Un `descripcion` muy largo puede pasar de 10 KB: ese evento se pierde (queda en el log) y la otra pantalla se entera al recargar. Pruebas: `EtapasTiempoRealTest`.
- Despliegue: sin migraciones; el back va antes que el front; `php artisan optimize:clear` si hay rutas en caché.

## Agrícola · Dashboard de cosecha (pestaña Ejecutivo)

- `GET /api/splendidfarms/operacion-agricola/cosecha/dashboard/ejecutivo?temporada_id={id}` (`Cosecha\DashboardCosechaController::ejecutivo`). Solo lectura, hereda `auth:sanctum` del grupo `operacion-agricola`; sin migraciones, eventos ni permisos nuevos. `temporada_id` es `required|integer|exists:temporadas,id` (422 si falta o no existe).
- Respuesta: `{ success, data: { temporada: {id, nombre}, etapas: Fila[], sin_etapa: {kg, cajas}, avisos: string[] } }`. Cada `Fila`: `id`, `codigo`, `nombre`, `is_active`, `superficie` (texto, 4 decimales), `lote`, `productor` (nombre y apellido unidos), `zona`, `variedad`, `tipo_variedad` (`{id, nombre}`, los tres últimos `null` si no hay), las cuatro fechas del plan (`fecha_siembra_estimada`, `fecha_siembra_real`, `fecha_cosecha_estimada`, `fecha_cosecha_proyectada`, `AAAA-MM-DD | null`), `cortes`, `venta_nacional {ha, kg}` y `empaque {cajas, kg, cajas_exportacion, cajas_nacional}`. Los decimales van como texto (`ha` con 4 decimales, `kg` con 2).
- Etapas: las de `Temporada::lotesActivos()`; una etapa inactiva solo sale si ya tiene cortes o empaque. Fuera de la lista quedan las etapas de un lote inactivo en la temporada.
- `cortes[]` (`{fecha, ha, kg, venta_nacional}`, por fecha): los cierres de cosecha suman sus hectáreas por día sin topar a la superficie de la etapa (el tope es del front) y las salidas de campo (`eliminado = 0`) suman `peso_neto_kg` por día. Se separan en dos cortes del mismo día según `venta_nacional`.
- Regla de venta nacional: un cierre es venta nacional si tiene al menos una venta de cosecha no borrada y con `status <> 'cancelada'` (sin importar su unidad); sus hectáreas (`superficie_cosechada`) van al corte `venta_nacional: true` y a `venta_nacional.ha` una sola vez por cierre. Los kilos del corte y de `venta_nacional.kg` salen de la `cantidad` de esas ventas en el día del cierre (`fecha_inicio`): `kg`, `kgs`, `kilo(s)`, `kilogramo(s)` ×1; `t`, `ton`, `tons`, `tonelada(s)` ×1000 (minúsculas y sin espacios en los bordes). Con otra unidad la venta no suma kilos y se agrega a `avisos` («La venta N está en «unidad» y no se sumó a la venta nacional»).
- `empaque`: producción no borrada (`produccion_empaque`) ligada a su etapa por `proceso_empaque.etapa_id`; `cajas_exportacion` y `cajas_nacional` suman los detalles de embarques no borrados según `embarques_empaque.tipo_venta`. `sin_etapa` junta las salidas sin `etapa_id` (kg) y la producción cuyo proceso no trae etapa (cajas).

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

- **Testing real: en progreso.** Cobertura completa (1084 tests) en dos módulos como referencia de patrón:
  - `tests/Feature/ActivosFijos/` — Activos Fijos (474 tests en 47 archivos: ActivoCrud, AislamientoActivos, AlcanceYPermisos, AltaCompraApi, AltaCompraBloqueoAnulacion, AltaCompraBloqueoSalidas, AltaCompraCreador, AltaCompraEsquema, AltaCompraGeneracion, AltaCompraServicio, AsignacionActivaEnListado, AsignacionApi, AsignacionCarta, AsignacionModelo, AsignadorActivos, BandejaAsignacionesMovimientos, BandejaAsignacionesResumen, CalculadoraDepreciacion, CalculoSalida, ContextoActivos, DepreciacionApi, DepreciacionCierre, DepreciacionConSalida, DepreciacionEsquema, DepreciacionResolutor, DeshacerSalida, GeneradorCodigoActivo, ImportLegacyEmpresa, IntegridadActivo, ListaActivosFiltros, ListaActivosResumen, MigracionActivosPorEmpresa, MigracionMenuActivos, RegistradorSalida, ResponsablesActivo, SalidaActivoEstado, SalidaApi, SalidaCarta, SalidaEsquema, SembradoAsignaciones, SembradoDepreciacion, SembradoPrefijosActivos, SembradoSalidas, SembradoTodasLasEmpresas, TiempoRealActivos, TipoActivo, TraspasoSalida). Resuelven la empresa desde la URL (`/api/{empresa}/administration/activos-fijos/...`), cubren SF y GE (y SBP en las pruebas de aislamiento) y usan `tests/Concerns/CreatesAssetFixtures.php` (SF + GE con Administración → Activos Fijos y helpers de permisos `activos.view/create/edit/delete`; las pruebas de asignaciones suman `tests/Concerns/CreatesAssignmentFixtures.php`, las de alta desde compras `tests/Concerns/CreatesAltaCompraFixtures.php` y las de depreciación `tests/Concerns/CreatesDepreciacionFixtures.php`, y las de salidas `tests/Concerns/CreatesSalidaFixtures.php`).
  - `tests/Feature/CRM/` — CRM Comercial (60 archivos, 612 tests; arrancó con Cliente, Prospecto, Vendedor, Región/Zona/Bodega, Producto, Contacto, EmpresaExterna y Actividad), usa `tests/Concerns/CreatesCrmFixtures.php`. A diferencia de Activos Fijos, el CRM resuelve la empresa por el header `X-Enterprise-Id` (no por la URL) — los tests lo mandan explícitamente vía `$this->crmHeaders()`.
  - El resto de módulos (RH, Agrícola, Empaque) todavía no tiene cobertura.
  - Escribir estos tests **encontró y corrigió 2 bugs reales** que llevaban tiempo sin detectarse por falta de cobertura: `ClienteController::index()` llamaba a un método de scope inexistente (`->empresa()` en vez de `->where('empresa_id', ...)`) y tronaba 500 en cada request; y `crm_vendedores.user_id` era `NOT NULL` en la BD aunque la validación del controller lo declara opcional, causando un 500 al crear un vendedor sin cuenta ligada (migración `2026_08_11_190000_make_user_id_nullable_on_crm_vendedores_table.php`).
- **Los tests corren en SQLite in-memory** (`phpunit.xml`), no contra MySQL. Varias migraciones antiguas tenían SQL específico de MySQL (`MODIFY COLUMN`, índices no liberados antes de `dropColumn`) que se fueron arreglando conforme se encontraron al escribir tests — si al escribir un test nuevo `RefreshDatabase` truena con un error de sintaxis SQLite en una migración que no tocaste, es este mismo patrón: revisa si falta `dropIndex()` antes de un `dropColumn()`, o si hay un `DB::statement()` con sintaxis de MySQL que necesita un guard `if (DB::getDriverName() === 'mysql')`.
- **CI de CRM ahora sí prueba algo**: `.github/workflows/crm-ci.yml` corre `--filter=CRM`, y antes de este trabajo eso coincidía con 0 tests (`INFO No tests found`, exit code 0 — el badge siempre salía verde sin validar nada). Ya hay 612 tests reales bajo ese filtro. Los demás módulos (incluyendo Activos Fijos) siguen sin estar en ningún workflow de CI — sería el siguiente paso natural.
- **Sin análisis estático**: no hay Larastan/PHPStan configurado, solo Pint (con la configuración por defecto, sin `pint.json` propio).
- **Límite de intentos de login y proxies:** `POST /api/auth/login` limita 5/min por correo+IP y 20/min por IP (`RateLimiter::for('login')` en `AppServiceProvider`). No hay `trustProxies` configurado: si `sentinelback.ddns.net` está detrás de un proxy inverso, todas las peticiones llegan con la IP del proxy y el límite por IP se compartiría. Antes de desplegar, comprobar en el servidor y, si hay proxy, añadir `$middleware->trustProxies(at: ['<ip-del-proxy>']);` en `bootstrap/app.php`.
- **`MailSettings::apply()` no corre bajo `config:cache`/`optimize`:** si corriera, la contraseña SMTP desencriptada de `/admin/settings` quedaría congelada en texto plano en `bootstrap/cache/config.php`, y un "desactivar SMTP" posterior no se reflejaría hasta un `config:clear` manual. `AppServiceProvider::boot()` lo guarda con `$this->app->runningConsoleCommand(['config:cache', 'optimize'])`. Si el SMTP se comporta raro después de un deploy con `config:cache`, correr `php artisan config:clear` — los ajustes se vuelven a aplicar solos en la siguiente petición.

## Credenciales BD (desarrollo local)

```
DB_HOST=localhost
DB_DATABASE=sentinel
DB_USERNAME=root
DB_PASSWORD=MasterKey
```

## Respaldos de la base de datos

- `/admin/backups` (solo `role = superadmin`, middleware `admin:superadmin`). El panel crea filas `pendiente` en `database_backups`; `respaldos:ejecutar` (programador, cada minuto, `withoutOverlapping(180)`) las genera, crea el programado diario (`backups.hour`), marca `fallido` lo atascado >2 h, aplica la retención (`backups.keep_scheduled`, solo programados; fallidos >30 días) y deja un latido en caché (`backups.scheduler_heartbeat`).
- **No usa la cola a propósito:** `retry_after` (90 s) relanzaría en paralelo un volcado largo.
- Volcado: `App\Contracts\VolcadorBaseDatos` → `MysqlDumpPhpVolcador` (druidfi/mysqldump-php, PHP puro). Las pruebas lo sustituyen por `Tests\Support\VolcadorFalso` porque corren en SQLite; la prueba real es `@group mysql` con `BACKUP_TEST_DB_*`.
- Las claves `backups.*` de `SettingsService` están reservadas: el endpoint general `/admin/settings` (nivel admin) no las lee ni las escribe.
- Descarga: `POST admin/backups/{id}/download-url` → URL firmada **relativa** de 5 min a `GET api/backups/{id}/download` (`signed:relative`, sin Sanctum).
- Guía de restauración y despliegue: `docs/respaldos.md`.

## Inventario · Costeo PEPS por capas

- Todo el inventario se costea PEPS. `inventory_cost_layers` (capas) e `inventory_cost_layer_consumptions` (porciones de cada salida). `products.cost_method` se ignora.
- Motor `App\Services\Inventory\CapasCosto`: `entrar`, `salir` (con `permitirNegativo` → capa `faltante`), `devolver` (porciones exactas, últimas primero; a otra cubeta conserva costo y fecha), `revertirEntrada` (422 `movement` si ya se usó), `deshacerTransferencia`, `reetiquetar`, `sincronizarFila`, `conciliar` (crea la capa inicial de filas sin capas).
- Orden obligatorio: operación del motor → `InventoryStock::ajustarCantidad()` (único escritor de cantidad; sincroniza `unit_cost`/`total_cost` desde las capas).
- `AplicadorStock` orquesta motor + cantidad + kardex (dirección física real; ya no existe `esReversa`). Las salidas escriben su costo real en el renglón.
- Empaque usa `updateStock(..., referencia: 'empaque_produccion:{id}')` y `devolverPorReferencia`.
- Verificación: `php artisan inventario:verificar-capas` compara cada fila de `inventory_stock` contra la suma de sus capas y lista las inconsistencias (sale 0 si todo cuadra).
- Despliegue (orden): respaldo → `migrate` (crea capas iniciales) → `php artisan inventario:verificar-capas` (debe salir 0) → `php artisan optimize:clear`. Siempre **antes** que el front.
- Limitaciones conocidas del motor (aceptadas):
  - Cancelar una entrada que cubrió un faltante abierto no es exactamente reversible (el faltante ya quedó saldado con el costo de esa entrada).
  - Las devoluciones a un destino no cubren el faltante abierto de ese destino.
