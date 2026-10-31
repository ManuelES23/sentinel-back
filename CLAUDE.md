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

Rutas de Administración → Activos Fijos y Organización (cualquier empresa): viven en `routes/activos-fijos.php` y `routes/organizacion.php` (sucursales, tipos de entidades, entidades y áreas; controladores `SplendidFarms\Administration\*`, que filtran por `X-Enterprise-Slug`) y se incluyen **una sola vez** en el grupo genérico `Route::prefix('{empresa}/administration')` al final del grupo `auth:sanctum` de `routes/api.php` (después de los prefijos fijos de empresa, que siguen ganando; `{empresa}` restringido a `[A-Za-z0-9_-]+`). No hay lista de slugs fijos: funcionan con cualquier empresa existente, así que un cambio de nombre/slug de una empresa (como le pasó a Canes Agro en producción) o una empresa nueva no las rompe.
- Middleware `App\Http\Middleware\EmpresaDeLaRuta` (por clase, sin alias): (1) la empresa del slug debe existir (404 `{status:'error', message:'Empresa no encontrada'}`); (2) el usuario debe ser admin o tener `user_enterprise_access` activo (403 «No tienes acceso a esta empresa.», mismo criterio que `AlcanceActivos::tieneAcceso`); (3) deja la empresa como la actual de `AlcanceActivos` (`recordarEmpresa`, no se repite la consulta); (4) **fija** `X-Enterprise-Slug` al slug de la URL, así los controladores de Organización, la bitácora y los eventos operan siempre sobre la empresa de la URL y no sobre una cabecera arbitraria (esto cierra, para estas rutas, la brecha conocida de que el header nunca se validaba); (5) `forgetParameter('empresa')`: sin esto Laravel pasaría el slug como primer argumento posicional a las acciones (`show(Branch $branch)`, `destroy($id)`…) y desplazaría los demás.
- El menú y los permisos siguen saliendo de la BD (apps/módulos/submódulos de cada usuario). A nivel API Organización responde también en Grupo Espléndido (solo con lo suyo), aunque GE no tiene el módulo en la BD y no le aparece en el menú.
- Cambio respecto a las rutas fijas: Organización antes no comprobaba el acceso a la empresa (solo usaba el header); ahora un usuario sin acceso a la empresa de la URL recibe 403 (en Activos Fijos ya se exigía vía `AlcanceActivos`), y las rutas de Activos Fijos que no llamaban a `AlcanceActivos` (p. ej. el catálogo de tipos) también lo exigen.
- Despliegue: `php artisan route:clear` (o `optimize:clear`); sin migraciones. Nota: `php artisan route:cache` ya fallaba antes de este cambio por nombres de ruta duplicados ajenos (p. ej. `productores.index`); las rutas de este grupo ya no aportan duplicados. Pruebas: `tests/Feature/ActivosFijos/RutasGenericasPorEmpresaTest.php`.

Rutas de Inventario → Catálogos de empresas distintas de Splendid Farms y Splendid by Porvenir (categorías, unidades, artículos, marcas y recetas; antes el bloque fijo `canes-agro`): también son genéricas. Viven en `routes/inventario-catalogos-empresa.php` y se incluyen **una sola vez** en el grupo `Route::prefix('{empresa}/inventario')` (middleware `EmpresaDeLaRuta`, `{empresa}` restringido a `[A-Za-z0-9_-]+`) al final del grupo `auth:sanctum` de `routes/api.php`, después de los prefijos fijos: SF y SBP tienen sus propias rutas de inventario (más completas, sin `EmpresaDeLaRuta`) y siguen ganando. No dependen de slugs, así que el cambio de nombre/slug de Canes Agro no las rompe (el slug `canes-agro` sigue funcionando). Ojo: ahora el usuario necesita acceso activo (`user_enterprise_access`) a la empresa de la URL o ser admin (403 en otro caso); el header `X-Enterprise-Slug` se reescribe al slug de la URL. Pruebas: `tests/Feature/Inventario/CatalogosEmpresaGenericoTest.php`. Despliegue: `php artisan route:clear`; sin migraciones. `CanesAgroInventoryModuleSeeder` (menú/permisos de Inventario de esa empresa) sigue buscando la empresa por el slug literal `canes-agro`.

Despliegue de Organización en empresas nuevas (back antes que el front): `php artisan route:clear` (o `optimize:clear`) + `php artisan db:seed --class=OrganizacionEmpresasSeeder` (idempotente; recorre las activas salvo GE, omite las que ya tienen el módulo y deja acceso sin conceder solo a admin@ y demo@) + dar permisos de Organización por empresa en el panel + capturar sucursales/entidades/áreas. Pruebas: `tests/Feature/ActivosFijos/OrganizacionEmpresasNuevasTest.php`.

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

## CRM · Monto de la oportunidad y cotizaciones

- Con `productos_en_oportunidad = false` la cotización reemplaza al estimado: `MontoOportunidadService` (`alGuardar`, `alRechazar`, `alAprobar`) lo llama `CotizacionController` dentro de la transacción y con la oportunidad bloqueada (`lockForUpdate`). El monto es `CrmCotizacion::baseConDescuento()` (base tras descuento, sin impuestos). Con `true` el monto sigue saliendo de las líneas de la oportunidad y el servicio no hace nada.
- Abierta: guardar/editar toma el monto; rechazar vuelve a la más reciente vigente (ni rechazada ni superada). Ganada: un borrador no cambia el monto, aprobar sí (flujo «superado», que conserva `fecha_cierre_real` y `estado_cobro`). Perdida: no cambia. Guardar una cotización nunca cambia la etapa.
- `PUT crm/oportunidades` solo acepta un `monto_esperado` distinto en oportunidades abiertas cuyo monto no venga de una cotización; si no, 422 en `monto_esperado` (el mismo valor al centavo se ignora).
- `CrmOportunidad::montoOrigen()` (`cotizacion` con folio, `estimado`, `sin_monto`) es la fuente única; sale como `monto_origen` vía `conMontoOrigen()` (listado, detalle, alta, edición, cambio de etapa y eventos). `RELACIONES_API` carga `cotizaciones` con columnas mínimas para no hacer una consulta por fila, y `conMontoOrigen()` la oculta del JSON.
- Una cotización cuya base o total con impuestos pase de `CrmOportunidad::MONTO_MAXIMO` responde 422 antes de escribir nada.

## CRM · Cierre, cobro, reasignar y convertir

- `PATCH crm/oportunidades/{id}/cobro` (`{estado: por_cobrar|cobrada|rechazada, motivo?}`): orden tenant (404), `oportunidades.cobro` (403), visibilidad (403) y validación (solo ganadas; `motivo` obligatorio con `rechazada`). El mismo estado es un 200 sin cambios, actividad ni eventos. Un cambio real guarda `estado_cobro`, `cobro_actualizado_at/por` y crea una nota de sistema en la oportunidad («Cobro: Cobrada», «Cobro rechazado: {motivo}») en la misma transacción; no toca etapa, `fecha_cierre_real` ni monto.
- `cambiar-etapa`: `cerrado_perdido` exige `oportunidades.editar` (el vendedor puede perder un trato); `cerrado_ganado` sigue exigiendo `cerrar`. Al perder, los eventos de agenda pendientes de la oportunidad pasan a `completado = true` en la misma transacción, sin actividad ni siguiente paso, y se emite `AgendaUpdated('completed')` por cada uno.
- `PUT crm/oportunidades` con otro `vendedor_id`: los eventos pendientes de esa oportunidad pasan al vendedor nuevo (`AgendaUpdated('updated')`); los completados se quedan con quien los hizo.
- `POST crm/prospectos/{id}/convertir-cliente` mueve al cliente las oportunidades abiertas y ganadas del prospecto (`cliente_id` y `prospecto_id = null` juntos) y emite `OportunidadUpdated('updated')` por cada una; las perdidas se quedan en el prospecto.
- `tipo_producto` (`POST`/`PUT crm/oportunidades`, texto de hasta 100): con commodities en la empresa (`CrmProducto::commoditiesDe`) y `productos_en_oportunidad = false` es obligatorio al crear y debe ser uno de ellos; al editar solo se valida si cambia (un valor viejo ya guardado no se revalida) y no se puede borrar. Sin commodities, o con el interruptor encendido, es texto libre opcional.
- `GET crm/oportunidades` acepta además `search` (texto de hasta 100, sin distinguir mayúsculas, `%` y `_` literales, por nombre de la oportunidad, del cliente o del prospecto; un valor no texto se ignora) y `abiertas=1` (solo etapas no terminales); componen con los demás filtros, con la visibilidad por vendedor y con `per_page`.

## CRM · Captura ligada a una oportunidad (spec 2026-10-30)

- **Toda captura nueva vive dentro de una oportunidad.** `POST crm/agenda`, `POST crm/actividades` y `POST crm/seguimientos` con `entidad_tipo` `cliente` o `prospecto` responden 422 en `entidad_tipo` («Elige una oportunidad o abre una nueva para este cliente.», «prospecto» según el caso; `SeguimientoService::exigirCapturaConOportunidad`). Pasan `oportunidad`, `empresa_externa` (`ENTIDADES_SIN_OPORTUNIDAD`) y, en agenda, sin entidad. El 422 va después de los permisos (403) y antes de buscar la entidad.
- **Sin exigir oportunidad:** `POST crm/prospectos/rapido` (con `primer_contacto`), Dialpad, notas `fuente = sistema`, la actividad del cobro, y `PATCH crm/agenda/{id}/completar` de un evento viejo ligado a cliente o prospecto (se completa; con `siguiente` responde 422 en `siguiente`: primero se liga). `PUT crm/agenda/{id}` no cambia de entidad, pero un `entidad_tipo` cliente/prospecto en el cuerpo se rechaza igual.
- **Etiqueta (`etiqueta_id`):** obligatoria y activa de la empresa cuando la captura es sobre una oportunidad (agenda store/update, actividades store, seguimientos `actividad.etiqueta_id` y `siguiente.etiqueta_id`, `siguiente` de `completar`); opcional pero validada en lo demás. Una `actividad` con `fuente = whatsapp_enlace` no la exige. Reglas y mensajes («Elige una etiqueta válida.») en `SeguimientoService::reglaEtiqueta`/`mensajesEtiqueta`; `reglasSiguiente($prefijo, $empresaId, $exigeEtiqueta)`. `programar()` y `crearActividad()` la guardan. Al completar, la actividad hereda la etiqueta del evento SIN revalidarla (una etiqueta desactivada después no impide completar). Agenda y actividades devuelven `etiqueta:id,nombre`.
- **`POST crm/oportunidades/rapida`** (`{cliente_id | prospecto_id, tipo_producto, monto_esperado?, vendedor_id?}`, `oportunidades.crear`): `AperturaOportunidadService::abrir` dentro de una transacción; nombre «{entidad} · {tipo_producto}», etapa `prospecto`, monto 0 por omisión. Prospecto ya convertido: 422 en `prospecto_id` con el nombre del cliente. Sin `oportunidades.equipo` el dueño es el vendedor activo propio (sin él 403; entidad de otro vendedor 403 «Este cliente es de {vendedor}; pide que te lo reasignen.»; pedir otro `vendedor_id` 403); con `equipo`: el pedido, si no el asignado a la entidad, si no el propio, y debe ser activo de la empresa (422 en `vendedor_id`). La regla de `tipo_producto` es `AperturaOportunidadService::reglasTipoProducto`, la misma de `POST/PUT crm/oportunidades`.
- **`POST crm/agenda/{id}/ligar`** (`{oportunidad_id}`, `agenda.editar`): liga un evento sin oportunidad. Orden: tenant (404), permiso (403), evento del vendedor (403), oportunidad visible y de la empresa (404 igual que inexistente), y 422 en `oportunidad_id` si está perdida, el evento ya tiene oportunidad o es de una empresa externa, o la oportunidad no es del cliente/prospecto del evento (un evento de un prospecto ya convertido acepta las oportunidades de su cliente). Un evento sin entidad (interno u Outlook) se liga a cualquier oportunidad visible. Difunde `AgendaUpdated('updated')`.

## CRM · Mi día con la oportunidad como eje (spec 2026-10-30)

- **`GET crm/mi-dia`** conserva sus claves (`umbrales`, `contadores`, `vencidos`, `hoy`, `tratos_detenidos`, `cotizaciones_por_vencer`, …) y agrega `oportunidad_eje: true` (marca de versión; también en `MiDiaService::vacio()`), `agenda`, `tablero` y `por_cobrar`. Los eventos de `vencidos`/`hoy` solo ganan `etiqueta` y `liga_pendiente`. La clave clásica `hoy` sigue trayendo también los de hoy ya terminados o completados.
- **`Evento`** = evento clásico + `etiqueta {id, nombre} | null` + `liga_pendiente` (true si cuelga de cliente, de prospecto o de nada; false con oportunidad o empresa externa). Los de `agenda.*` traen además `oportunidad: OportunidadMiDia | null`.
- **`OportunidadMiDia`** = `{id, nombre, etapa, tipo_producto, monto_esperado, monto_origen, estado_cobro, entidad, motivo_perdida, motivo_rechazo}` (`motivo_perdida` = texto libre de la oportunidad; `motivo_rechazo` = `{id, nombre, categoria: {id, nombre}} | null`; ambos null si no está perdida); `monto_origen` sale de `CrmOportunidad::montoOrigen()`. Se arma con `MiDiaService::oportunidadMiDia()` y exige `MiDiaService::CARGA_OPORTUNIDAD` cargado (cliente, prospecto y cotizaciones mínimas) para no consultar por oportunidad; en la agenda se carga con `morphWith`.
- **`agenda`**: `{vencidas, hoy, proximas}`, solo pendientes del vendedor, cada grupo con tope `LIMITE_POR_BLOQUE` (50), por `fecha_inicio` y `id`. `vencidas` = `fecha_fin < ahora`; `hoy` = `fecha_inicio` hasta el fin de hoy (sin cota inferior) y `fecha_fin >= ahora`, así un evento de varios días en curso cuenta como de hoy (uno que ya terminó sale solo en `vencidas`); `proximas` = de mañana a hoy + `DIAS_PROXIMAS` (7) inclusive.
- **`tablero`**: `[{etapa, oportunidades: Tarjeta[]}]` en el orden de `ETAPAS_TABLERO` (siempre las 5 columnas). `Tarjeta` = `OportunidadMiDia` + `siguiente` (evento base, sin `oportunidad`; la pendiente más próxima del MISMO vendedor, de cualquier fecha). Abiertas: todas las del vendedor; `cerrado_ganado`: solo con pendiente o `estado_cobro = por_cobrar`; nunca perdidas. Orden por `siguiente.fecha_inicio`, las sin siguiente al final; tope de 50 por columna.
- **`por_cobrar`**: `{cantidad, monto}` de las ganadas del vendedor con `estado_cobro = por_cobrar`, sin tope; las ganadas sin estado (legado) no cuentan.
- **`GET crm/mi-dia/oportunidades/{oportunidad}?vendedor_id=`** (`MiDiaController::oportunidad`, `MiDiaDetalleService`): `mi-dia.ver` (403), tenant (404) y el vendedor se resuelve igual que en `index` (`vendedor_id` ajeno solo con `mi-dia.equipo`). Abre si la oportunidad es de ese vendedor O él tiene un evento (cualquier estado) sobre ella; si no, 403. No exige `oportunidades.ver`. Devuelve `{oportunidad, cotizaciones, eventos, seguimiento}`: `cotizaciones` `[{id, folio, estado, total}]` (más nueva primero) solo con `cotizaciones.ver`, si no `[]`; `eventos` = pendientes de ese vendedor en la oportunidad (más viejo primero); `seguimiento` = hasta `LIMITE_SEGUIMIENTO` (30) actividades de la oportunidad, su cliente y su prospecto, más nueva primero, cada una `{id, tipo, descripcion, resultado, fecha, etiqueta, origen: oportunidad|cliente|prospecto}`. Un gerente sin vendedor propio debe mandar `vendedor_id`: sin él responde el 422 estándar de vendedor no vinculado.
- **`POST crm/mi-dia/actividades`** (`MiDiaController::crearActividad`, `NuevaActividadService::crear`): «Nueva actividad», reemplaza los botones Prospecto rápido y Actividad de Mi día. Una sola `DB::transaction` crea el prospecto (si es nuevo), abre la oportunidad (si hace falta) y registra el evento o la actividad; `ProspectoUpdated`/`OportunidadUpdated`/`AgendaUpdated`/`ActividadUpdated` (`created`) salen con `difundir()` solo después de confirmarla (`OportunidadUpdated` solo si la oportunidad es nueva). Cuerpo: EXACTAMENTE UN origen entre `prospecto_nuevo {nombre, telefono?, email?}`, `cliente_id`, `prospecto_id`, `oportunidad_id` (0 o 2+ → 422 en `origen`) y EXACTAMENTE UN registro entre `evento` (las reglas de `siguiente` de `POST crm/seguimientos`: `etiqueta_id` obligatoria, `fecha_inicio` futura) y `actividad {tipo, descripcion, resultado?, etiqueta_id, fecha_actividad?}` (0 o 2 → 422 en `registro`; sin `fecha_actividad` guarda `now()`, con ella no puede pasar de ahora + 5 min); `tipo_producto`/`monto_esperado` solo al abrir (`reglasTipoProducto`, obligatorio con commodities); `vendedor_id?`. `cliente_id`, `prospecto_id` y `prospecto_nuevo` SIEMPRE abren una oportunidad nueva (`AperturaOportunidadService::abrir`, con sus 403/422); `oportunidad_id` usa una existente de la empresa y visible (`entidadVisible`: si no, el mismo 404 que una inexistente), aceptando ganadas y rechazando perdidas (422 en `oportunidad_id`). Permisos: `mi-dia.ver`, `agenda.crear` con `evento`, `actividades.crear` con `actividad`, `oportunidades.crear` solo al abrir una nueva y `prospectos.crear` solo con `prospecto_nuevo` (403, tras validar). Todo queda a nombre del vendedor resuelto con `VendedorActualService::resolver` (otro vendedor solo con `mi-dia.equipo`; sin `oportunidades.equipo` abrir a nombre de otro responde 403). `prospecto_nuevo` se crea como `storeRapido` (estatus `nuevo`, mismo correo único por empresa sin eliminados y mensaje «Ya existe un prospecto con este correo.»). Responde 201 `{oportunidad (RELACIONES_API + monto_origen), evento (vendedor, etiqueta), actividad (ActividadController::RELACIONES), prospecto}`; lo no creado va `null`. `POST crm/prospectos/rapido` y `POST crm/seguimientos` siguen igual para el front viejo.

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
- Fichas (spec 2026-10-30 §5.4): `GET crm/{cliente|prospecto}/{id}/historial` y `GET crm/clientes/{id}/resumen` (`actividades` = 10 más recientes y `metricas.actividades`) traen también las actividades de las oportunidades del cliente o prospecto que el usuario ve, con la misma regla de visibilidad (`App\Services\CRM\ActividadesDeFicha`: un solo `where (... or ...)`, así que total y paginación cuadran). Cada fila lleva `oportunidad {id, nombre}` o `null` (una consulta por página, sin N+1). La ficha excluye las actividades de oportunidades eliminadas (soft delete) aunque el usuario tenga `oportunidades.equipo`. El historial de una oportunidad o de una empresa externa y `GET crm/actividades` no cambian (con `equipo` este último sigue sin filtrar eliminadas) y no traen `oportunidad`. El prospecto no tiene resumen: solo aplica su historial. Pruebas: `FichasActividadesOportunidadTest`.

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

## RH · Empleados (lista, búsqueda, duplicados)

- `GET grupoesplendido/rh/empleados`: `search` divide el texto en palabras y cada una debe aparecer en algún campo (`Employee::scopeSearch`: número, nombre, apellido, segundo apellido, email, CURP, clave de checador; `%` y `_` literales con `ESCAPE '!'`). Así «Fernando Figueroa» encuentra a FERNANDO FRANCISCO / FIGUEROA VALENZUELA (los dos apellidos suelen venir juntos en `last_name`). `per_page` de 1 a 1000 (no numérico = 15); `eliminados=1` lista solo los borrados lógicos.
- `POST empleados/{id}/restaurar` restaura un eliminado (404 si no lo está) con su estado de antes.
- CURP y clave de checador son únicas en la tabla también contra los eliminados (índice único). Alta y edición ya no usan la regla `unique`: `EmployeeController::duplicado` responde 422 en español con quién la tiene («La CURP ya está registrada para Rosa Pérez (GE-0007).», más «Ese empleado está eliminado: restáuralo…» si aplica), `errors.{campo}` y `existente {id, nombre, numero, eliminado}`. La CURP se compara sin mayúsculas. Pruebas: `EmpleadosBusquedaYDuplicadosTest`.

## Vacaciones (RH Grupo Espléndido)

- Reglas en `App\Services\RH\VacationRequestService` (crear, calcular, reprogramar, cancelar, aprobarPaso, rechazar, resolverComoRh). Lo usan `VacationController` (RH), `ProfileController` y `PendingApprovalController`; ningún controlador repite reglas. Las fallas lanzan `App\Exceptions\RH\VacacionesException` (se responde sola: 422 o 403).
- Saldo **acumulado sin vencimiento** (`VacationCalculatorService::diasDisponibles`): días LFT de todos los años cumplidos («Vacaciones Dignas 2023»: 12, 14, 16, 18, 20, 22 de 6 a 10 años, +2 por quinquenio) + `vacation_balances.adjustment_days` − aprobadas − pendientes. Usados y pendientes salen de `vacation_requests`; los contadores `used_days`/`pending_days` de `vacation_balances` ya no se leen (la migración `2026_10_15_100100` los concilió y dejó la diferencia como ajuste).
- Días que se descuentan: `WorkingDaysCalculator` (horario del empleado → predeterminado de su empresa en `enterprise_work_schedule.is_default` → lunes a sábado) menos festivos y **nunca el domingo** (el horario «Mexicano» lo marca para el checador, pero no se trabaja) (`holidays`, fechas concretas, **por empresa**). De qué empresa salen los festivos de un empleado lo decide `EmpresaDeFestivos`: su excepción (`employees.holiday_source_enterprise_id`) → la empresa que sigue su empresa (`enterprises.holiday_source_enterprise_id`, un solo nivel) → su empresa (migración `2026_10_29_100000`). Un festivo viejo sin empresa sigue valiendo para todas. `php artisan vacaciones:festivos {año} --empresa={slug} --pais=mx|us` carga los oficiales: mx = art. 74 LFT; us = los 6 comunes (New Year's, Memorial, Independence, Labor, Thanksgiving, Christmas; fecha fija en sábado → viernes, en domingo → lunes, «(observado)»). Sin `--empresa` usa `splendidfarms`.
- Reglas: ya no se exige 1 año; **adelanto**: si pide más de su saldo, la diferencia se guarda en `vacation_requests.advance_days` (migración `2026_10_22_120000`) y el saldo queda negativo hasta los días de su siguiente aniversario (`calcular` devuelve `adelanto`, `limite_adelanto`, `proximo_aniversario`; error «Puede adelantar hasta N días…»); al cumplir el aniversario el negativo se cubre solo. El saldo ya no se recorta a 0 (`total_available_days`, perfil `available_days`, portal). `omitir_antiguedad` se acepta pero ya no hace nada. RH tiene vista previa en `POST vacaciones/calcular` (`view`). Además: no pasar del saldo + adelanto, sin empalmes con pendientes/aprobadas, rango ≤ 60 días naturales, al menos 1 día descontable; RH puede capturar fechas pasadas. `cancelar` bloquea solo la fila de la solicitud (no al empleado): únicamente libera días. `excluir_id` (reprogramar) se limita al empleado y el llamador no puede sobrescribirlo.
- **Ya autorizada fuera del sistema** (`VacationRequestService::capturarAutorizada`): `POST vacaciones` con `ya_autorizada: true` (exige `aprobar`, y `esRh` en el servicio) y `nota_autorizacion` opcional (≤ 255). Mismas reglas de `calcular` (sin domingos/festivos, empalmes, adelanto, fechas pasadas), pero entra `approved` con `resolved_by_rh`, un solo paso `CadenaAprobacionVacaciones::YA_AUTORIZADA` aprobado por el usuario de RH (comentario «Capturada como ya autorizada por RH: <nota>»), marca asistencia y **no avisa** (solo `emitir` en tiempo real: sin correo ni avisos internos). RH no puede capturar así sus propias vacaciones (403). Se cancela como cualquier aprobada que no ha empezado.
- Aprobación en cadena (solo vacaciones): `approval_flow_steps` del proceso `vacation_requests`; mismo `step_order` = alternativas, distinto = en serie. Tipos propios de vacaciones (422 en otros procesos): `direct_supervisor` (`employees.reports_to`), `department_manager` («Jefe del departamento»: `departments.manager_id` del solicitante; si el departamento no trae `manager_id` cargado se consulta) y `user` («Usuario específico»: `approval_flow_steps.user_id`; no necesita ser empleado, y si lo es debe estar activo; las listas se limpian al cambiar de tipo). La cadena se congela en `vacation_request_approvals` al crear.
- El jefe de un departamento se asigna en RH → Departamentos (`manager_id` de `DepartmentController` store/update): debe ser un empleado ACTIVO de la MISMA empresa (422 en `manager_id`; solo se revalida cuando la petición trae `manager_id`). Sin jefe, o con el jefe dado de baja, el paso «Jefe del departamento» se salta; un jefe sin usuario de Sentinel NO se salta: decide desde el portal.
- **Jefe de departamento en el portal** (con o sin usuario): solo la regla «Jefe del departamento» abre esa puerta. `AprobadorVacaciones::puedeDecidirComoEmpleado` (empleado activo, jefe del departamento exacto del solicitante, no es el solicitante, no decidió otro paso, la regla permite aprobar/rechazar), `jefesDelPortal` y `hayQuienDecida` (la cadena solo salta un paso si no hay ni usuarios elegibles ni jefe del portal). `VacationRequestService::aprobarPaso/rechazar` aceptan `User|Employee`; la decisión del jefe del portal guarda `vacation_request_approvals.decided_by_employee_id` (migración `2026_10_20_120000`) y `decided_by` solo si tiene usuario; si cierra la cadena, `approved_by` y el `approved_by` de la asistencia quedan nulos (el nombre sale de `VacationRequestApproval::nombreDeQuienDecidio()`, que combina `decider` y `decidedByEmployee`; por eso las listas cargan `approvals.decidedByEmployee:id,first_name,last_name,second_last_name`). API en `Api\Portal\VacationPortalRevisionController` bajo `portal-vacaciones`: `GET por-revisar`, `GET por-revisar/{id}`, `POST por-revisar/{id}/aprobar` y `POST por-revisar/{id}/rechazar` (`reason` obligatorio); el jefe sale de la sesión, una solicitud que no le toca da el mismo 404 que una inexistente y lo que sale es una lista blanca (`PresentadorPortalVacaciones::paraRevisar`: solicitante, puesto, departamento, periodo, días, motivo y pasos; sin saldo ni datos personales). `GET yo` trae `es_jefe`. Aviso: el jefe recibe el correo `VacationReviewMail` con o sin usuario (ver «Correos de la cadena» más abajo). Las solicitudes ya creadas conservan su cadena congelada (un paso saltado por «sin usuario» no se reabre). «Jefe directo» y los demás tipos siguen exigiendo usuario. Pruebas: `JefeDelPortalTest`.
- Un «usuario específico» sin empleado ligado ve y resuelve su paso desde la bandeja (`PendingApprovalController::entradaVacacionesSinEmpleado`; `approve`/`reject` de `vacation_request` ya no exigen empleado, el servicio valida el paso). `canApproveProcess` de vacaciones también acepta a quien es jefe de algún departamento o usuario específico de una regla. `AprobadorVacaciones` decide quién puede (patrón de `AprobadorOrdenCompra`: corporativo solo por regla de puesto; nadie aprueba lo suyo ni dos pasos). Un paso sin elegibles con usuario se salta; si ninguno aprobó, la solicitud espera en el paso final de RH. Quien tiene `aprobar` puede resolver como RH en cualquier paso.
- Solicitud propia: `AprobadorVacaciones::esSolicitante(User, VacationRequest)` es verdadero si el `user_id` del empleado de la solicitud es el usuario, o si el empleado del usuario (consultado en fresco) es el solicitante (`employees.user_id` no es único). Lo usan `puedeDecidir` y `resolverComoRh`.
- Bandeja de pendientes: `vacacionesPara` revisa todas las solicitudes pendientes con `current_step` (sin tope de 200) y memoiza por usuario dentro de la instancia del controlador; la lista de la bandeja muestra hasta 50 después de filtrar. Los usuarios de RH sin empleado ligado resuelven desde la pantalla de Vacaciones de RH, no desde la bandeja.
- El perfil lista solicitudes con `vacation_year` ≥ año actual.
- Permisos de RH → Gestión → Vacaciones: `view`, `create`, `edit`, `aprobar` (`App\Support\PermisoSubmodulo`, que también usa el trait `VerificaPermisoSubmodulo` del CRM con la aplicación como parámetro). `PermisoSubmodulo` solo cuenta un permiso concedido cuyo tipo pertenezca al mismo submódulo (aplica también al CRM).
- Migración de permisos `2026_10_15_100500`: solo la empresa corporativa, RH → Gestión → Vacaciones. A los usuarios con acceso activo les fuerza `view/create/edit` como concedidos (los valores por defecto del seeder esta API nunca los aplicó) y les crea `aprobar` concedido salvo que ya exista una fila.
- Aprobar crea `attendance_records` con `status = vacation` (respeta los días con entrada); cancelar una aprobada futura los borra.
- PIN del empleado (checador y portal): hasheado (cast `hashed`, unidireccional), fuera de `activity_logs`, bloqueo de 15 min tras 5 errores (`VerificadorPinEmpleado`). RH solo lo ve al generarlo o regenerarlo; regenerar también desbloquea. Las bitácoras anteriores pueden contener PINs en claro (pendiente de limpieza).
- Hueco conocido: `GET vacaciones/balance` sigue devolviendo los contadores viejos por año (la vista usa `empleado/{id}/info`).
- **Historial capturado por RH** (lo que llevaba en Excel; spec §16): tabla `vacation_history` (`VacationHistoryEntry`, Loggable + SoftDeletes; migración `2026_10_21_100000`): `vacation_year`, `start_date`/`end_date` opcionales (las dos o ninguna), `days`, `notes` (solo RH), `source` (`manual`|`importacion`), `import_file`, `created_by`/`updated_by`. Sin cadena de aprobación ni asistencia. **Cuenta como días tomados:** `VacationCalculatorService::totalesDeSolicitudes` suma el historial a `usados` y lo devuelve aparte (`historial`; `accumulated.total_history_days`, `saldo.de_historial` del portal, `balance.history_days` del perfil). El ajuste queda para lo que no son vacaciones tomadas (pagados, vencidos).
  - **Pantalla de RH (bandeja con pestañas):** `GET vacaciones/saldos?enterprise_id=` (`view`; `SaldosVacaciones::todos`) da el saldo de todos los empleados activos o de permiso con las mismas cuentas que `diasDisponibles` en tres consultas agrupadas (`le_corresponden`, `ajustes`, `tomados` con `del_historial`, `en_revision`, `disponibles`, `proximo_aniversario {fecha, dias}`); `GET vacaciones/festivos-rango?desde&hasta&enterprise_id=` (`view` de Vacaciones, rango ≤ 400 días) da los festivos del calendario del equipo sin pedir el permiso de Festivos.
  - **Empezar de cero** (`vacaciones:reiniciar`, `ReiniciarVacacionesCommand`): sin `--confirmar` solo muestra los conteos; con `--confirmar` (y la confirmación interactiva) borra en una transacción `vacation_notifications`, `vacation_request_approvals`, `vacation_requests` (también las eliminadas), `vacation_balances` (ajustes y conciliación) y `vacation_history`, y deja `Log::warning('Reinicio de vacaciones')`. Conserva empleados, PINs, festivos y la cadena. Se corre después del `migrate` del deploy (la conciliación crea ajustes) y antes de importar el Excel.
  - Reglas en `App\Services\RH\HistorialVacaciones::errores` (formulario e importación dicen lo mismo): año no futuro ni anterior al ingreso; días 1–120; fechas no futuras, `end ≥ start`, no antes del ingreso; un periodo con fechas no se encima con otro del historial, con una solicitud aprobada/pendiente ni con otra fila del mismo lote. `crear`/`actualizar` bloquean al empleado. `yaExiste` (mismas fechas, o mismo año y días sin fechas) hace que reimportar no duplique.
  - API `grupoesplendido/rh/vacaciones/historico` (`VacationHistoryController`): `GET ?employee_id=` (`view`), `POST`, `PUT {id}`, `DELETE {id}` (`edit`), y la importación (`edit`): `POST importar/leer` (multipart `archivo`, xlsx/xls/csv ≤ 5 MB; no guarda), `POST importar/validar` (`filas[]`: errores por fila, `ya_importada`, `saldos` antes/después; no guarda) y `POST importar` (todo o nada; errores en `filas.N.campo`).
  - `App\Services\RH\ImportadorHistorialVacaciones` (PhpSpreadsheet, `setReadDataOnly`): toma la hoja «Registro de Vacaciones» si existe (si no, la primera) y busca encabezados en las primeras 5 filas. Formato RH: «Fecha de regreso» es el día en que vuelve (último día = regreso − 1) y «Días disfrutados» es una fórmula: se usa el valor que Excel dejó calculado (`getOldCalculatedValue`; PhpSpreadsheet no evalúa `_xludf.MAXIFS`). Plantilla: Número de empleado, Nombre, Año, Desde, Hasta (último día), Días, Nota. «Periodo» se toma por su primer año de 4 dígitos (`2024-2025` → 2024). Estados: `omitida` (sin empleado, fecha futura, paternidad, duplicada exacta), `revisar` (faltan fechas o días, nota con días entre paréntesis o festivos, encimada con otra fila) o `lista`. Empleados: por número si viene; si no, por nombre normalizado (sin acentos ni dobles espacios) exacto; si no, candidatos con 2+ palabras en común y sugerido solo si un único candidato contiene todas las palabras. De «Control de Personal» saca los pendientes del Excel (para comparar saldos), la nota (p. ej. días pagados → ajuste) y la fecha de ingreso (avisa si difiere). `opciones` trae todos los empleados para asignar a mano.
  - Portal: `GET portal-vacaciones/historial` (sin `notes`, que es interna de RH); el perfil trae `vacation.history`. Pruebas: `HistorialVacacionesTest`.
  - Despliegue: una migración más (7 en total). Antes de importar en producción, revisar a los empleados con ajuste de la conciliación (log «Conciliación de saldos de vacaciones»): si ese ajuste ya descontaba días usados, el historial los contaría dos veces.
- Portal del empleado (`/api/portal-vacaciones`, fuera de `auth:sanctum`): `Api\Portal\VacationPortalController`. Login con número + PIN (`VerificadorPinEmpleado`, mismo bloqueo que el checador) → token propio de 64 caracteres; en `vacation_portal_sessions` solo va su sha256. El middleware `portal.vacaciones` (`AuthenticatePortalSession`) lee `X-Portal-Token`, caduca a las 2 h o tras 15 min sin uso, exige empleado activo y deja el empleado en `$request->attributes->get('portal_employee')`; nunca consulta Sanctum ni `device_pairings` (un token del portal no abre rutas internas ni al revés).
- Lo que sale del portal pasa por `App\Support\RH\PresentadorPortalVacaciones` (lista blanca: nombre, número, empresa, puesto, departamento, ingreso, saldo y solicitudes propias; de quien decidió, solo el nombre). Solicitud ajena o inexistente → 404 «Solicitud no encontrada.». Las reglas son las de `VacationRequestService` (`source = portal`, sin capturista).
- Límites: login `throttle:10,1,portal-login` por IP; el resto, limitador `portal-vacaciones` de `AppServiceProvider` (60/min por sesión, no por IP: la oficina comparte IP).
- Regenerar el PIN, dar de baja o borrar al empleado revoca sus sesiones del portal (`SesionPortalVacaciones::revocarTodas`). `vacaciones:limpiar-sesiones-portal` corre a diario (03:30) y borra las vencidas o revocadas hace más de 7 días.
- Avisos al empleado (plan 3): `AvisosVacaciones` (después de confirmar la transacción) manda el correo con `CorreoVacaciones` → `App\Mail\RH\VacationStatusMail` (eventos `recibida`, `aprobada`, `rechazada`, `cancelada_por_rh`; solo con email válido; nunca lleva el PIN; el link sale de `config('vacaciones.portal_url')` = `FRONTEND_URL` + `/vacaciones`). Un fallo de SMTP no rompe la operación: queda en `vacation_notifications.error` y en el log. `VACACIONES_CORREO_EN_COLA=true` lo manda por la cola (`EnviarCorreoVacacionesJob`, necesita worker; por defecto se envía en la misma petición). Los textos de correo y WhatsApp viven en `App\Support\RH\TextosVacaciones`.
- **Correos de la cadena (2026-10-27):** cada vez que un paso queda en espera (al crear, al aprobar un paso intermedio y al reprogramar) `AvisosVacaciones::avisarAprobadoresDelPasoActual` manda, además del aviso interno, un `VacationReviewMail` («Tienes una solicitud de vacaciones por revisar») a cada aprobador, una vez por dirección: el jefe del portal (correo de su ficha o, si no tiene, el de su usuario; vía `portal` sin usuario o `portal_o_sentinel` con usuario) y cada usuario elegible que no sea ese mismo jefe (vía `sentinel`, enlace `config('vacaciones.sentinel_url')` + `/profile?tab=approvals`; en el paso final de RH vía `rh`). Lo arma `CorreoVacaciones::avisarAprobador` (nunca lanza; correo inválido = no se manda; respeta `VACACIONES_CORREO_EN_COLA`; no va a `vacation_notifications`). Cuando un paso intermedio aprueba, `aprobarPaso` llama `AvisosVacaciones::pasoAprobado`: aviso al siguiente aprobador y correo al empleado con el evento `en_revision` («Tu solicitud de vacaciones avanzó»: quién la aprobó y quién la revisa ahora; sí se registra en `vacation_notifications`, no está en `EVENTOS` porque no es de WhatsApp). Reprogramar usa `pasoAvanzado` (solo aprobadores). La aprobación final, el rechazo y la cancelación por RH siguen con su correo al empleado. `sentinel_url` = `FRONTEND_URL` sin ruta. Pruebas: `CorreosAprobadoresVacacionesTest`.
- WhatsApp manual: `VacationAvisoController` (`GET {id}/whatsapp-mensaje`, `POST {id}/avisos/whatsapp`, `GET {id}/avisos`) en `grupoesplendido/rh/vacaciones` y, para `whatsapp-mensaje` y `avisos/whatsapp`, también en `pending-approvals/vacation_request/{id}/…`. Autoriza el propio controlador: RH con `edit` (o `view` para el historial) o quien decidió algún paso de esa solicitud; el teléfono sale normalizado con `TelefonoWhatsApp` (celular, si no teléfono; `null` si ninguno sirve). `vacation_notifications` registra correo y WhatsApp (`sent_by` nulo = sistema).
- Festivos: `HolidayController` (`grupoesplendido/rh/festivos`, permisos del submódulo RH → Gestión → Festivos: `view/create/edit/delete`; `mis-permisos` para el front). Cada festivo nuevo exige `enterprise_id` (`GET ?enterprise_id=` filtra). `GET festivos/empresas?year=` (`view`) lista las empresas activas con `holiday_source_enterprise_id` y `festivos_propios`; `PUT festivos/empresas/{enterprise}` (`edit`) cambia a quién sigue (`null` = los suyos; 422 si se sigue a sí misma, si la elegida ya sigue a otra o si otras la siguen). `POST cargar-oficiales` pide `year`, `enterprise_id` y `pais` (mx|us). La migración pasó los festivos «de todas» a Splendid Farms y dejó a las demás empresas (menos Splendid by Porvenir) siguiéndola (`FestivosGeneralesAEmpresa`). `GET vacaciones/festivos-rango?enterprise_id=` trae los que usa esa empresa; sin empresa, todos con `empresa`. La unicidad (fecha, empresa) se valida en la aplicación. Cambiar un festivo solo afecta solicitudes nuevas. `GET vacaciones/mis-permisos` hace lo mismo para Vacaciones.
- Tiempo real: `VacationRequestUpdated` se emite siempre a `module.grupoesplendido.rh.gestion` (un módulo real, el único que `ModuleChannel` autoriza para RH) y a `employee.{id}.vacations`. El canal `module.grupoesplendido.administration.rh` que decía el spec no existe.
- `GET /api/profile` trae `vacation.requests[].revision` (`paso_actual`, `total_pasos`, `revisor_actual`, `pasos`) con el mismo presentador del portal. `GET vacaciones` de RH incluye `employee.department`.
- Cierre de pendientes del plan 1: `reprogramar` avisa siempre al aprobador del paso actual; `VacationBalance::applyAdjustment` suma con bloqueo y conserva el motivo nuevo; «Jefe directo» solo se acepta en el proceso `vacation_requests` (422 en otros).

## Agrícola · Plan de siembra

- `POST /api/splendidfarms/operacion-agricola/agricola/etapas/registrar-siembra` (`EtapaController::registrarSiembra`) registra la siembra real de varias etapas a la vez. Cuerpo: `{ temporada_id, etapa_ids: int[], fecha_siembra_real: "Y-m-d" }`. Responde 200 `{ success, message: "N siembras registradas", data: Etapa[] }` con las mismas relaciones que el `index` de etapas (lote con productor y zona, variedad y tipo de variedad).
- Reglas (422 con la clave del campo): `temporada_id` existente; `etapa_ids` de 1 a 100, enteros, sin repetir (el error cae en `etapa_ids.N`) y sin etapas borradas; `fecha_siembra_real` en `Y-m-d` y no futura (`before_or_equal:today`, la app corre en `America/Mazatlan`). Ya dentro de la transacción: todas las etapas deben pertenecer a un lote activo de la temporada («Alguna etapa no pertenece a la temporada.») y ninguna puede tener ya `fecha_siembra_real` («L1-E2 ya tiene siembra registrada.» / «L1-E2, L1-E3 ya tienen siembra registrada.», por `etapa_ids`).
- Todo o nada: una sola `DB::transaction` con las etapas bloqueadas (`lockForUpdate`, en orden de id). Si una falla no se toca ninguna. Cada etapa se guarda con `update()` para que `Loggable` deje bitácora.
- Ciclo: días entre `fecha_siembra_estimada` y `fecha_cosecha_estimada` de cada etapa; `fecha_cosecha_proyectada` = fecha de siembra real + ciclo, o `null` si la etapa no trae las dos estimadas.
- Superficie disponible de un lote (`Etapa::superficieDisponible` y `GET etapas/superficie-disponible`): se redondea a **2 decimales** (`Etapa::redondearDisponible`), la precisión con la que se muestran lotes y etapas, y el tope se compara con `Etapa::excedeDisponible` (4 decimales, los de la columna). Sin eso, la resta en punto flotante (24.4 − 20 = 4.3999999999999986) o una superficie de mapa con 4 decimales (14.3996, que se ve 14.40) no dejaban capturar lo que se veía disponible. `LoteController` y `LoteSimpleController` comparan la superficie del lote contra lo asignado también a 2 decimales. Pruebas en `AgricolaBugfixesTest`.
- Hueco conocido: `EtapaController` no revisa permisos de submódulo (cualquier usuario autenticado de la empresa puede llamarlo, igual que el resto de sus acciones).
- Tiempo real: `store`, `update`, `destroy` y `registrarSiembra` emiten `App\Events\EtapaUpdated` (`etapa.updated`) por el canal fijo `module.splendidfarms.operacion-agricola.agricola` (no por las cabeceras `X-*`; lo autoriza `ModuleChannel`). `data.etapas` siempre es una lista: en `created`/`updated` va la etapa completa (mismas relaciones que el `index`) más `temporada_ids`, las temporadas donde su lote está activo; en el registro en bloque, `updated` con parches `{ id, fecha_siembra_real, fecha_cosecha_proyectada, updated_at }` en grupos de `PARCHES_POR_EVENTO` (40), porque Reverb rechaza mensajes de más de 10 KB; en `deleted`, `{ id, lote_id }`. Sin `toOthers()` (el front no manda `X-Socket-ID`): quien escribe también recibe su evento y el front lo aplica sin duplicar. Se emite con `difundir()` (try/catch, como en el CRM): un Reverb caído no convierte en 500 una escritura guardada. Un `descripcion` muy largo puede pasar de 10 KB: ese evento se pierde (queda en el log) y la otra pantalla se entera al recargar. Pruebas: `EtapasTiempoRealTest`.
- Despliegue: sin migraciones; el back va antes que el front; `php artisan optimize:clear` si hay rutas en caché.

## Agrícola · Dashboard de cosecha (pestaña Ejecutivo)

- `GET /api/splendidfarms/operacion-agricola/cosecha/dashboard/ejecutivo?temporada_id={id}` (`Cosecha\DashboardCosechaController::ejecutivo`). Solo lectura, hereda `auth:sanctum` del grupo `operacion-agricola`; sin migraciones, eventos ni permisos nuevos. `temporada_id` es `required|integer|exists:temporadas,id` (422 si falta o no existe).
- Respuesta: `{ success, data: { temporada: {id, nombre}, etapas: Fila[], sin_etapa: {kg, cajas}, avisos: string[] } }`. Cada `Fila`: `id`, `codigo`, `nombre`, `is_active`, `superficie` (texto, 4 decimales), `lote`, `productor` (nombre y apellido unidos), `zona`, `variedad`, `tipo_variedad` (`{id, nombre}`, los tres últimos `null` si no hay), las cuatro fechas del plan (`fecha_siembra_estimada`, `fecha_siembra_real`, `fecha_cosecha_estimada`, `fecha_cosecha_proyectada`, `AAAA-MM-DD | null`), `cortes`, `venta_nacional {ha, kg}` y `empaque {cajas, kg, cajas_exportacion, cajas_nacional}`. Los decimales van como texto (`ha` con 4 decimales, `kg` con 2).
- Etapas: las de `Temporada::lotesActivos()`; una etapa inactiva solo sale si ya tiene cortes o empaque. Fuera de la lista quedan las etapas de un lote inactivo en la temporada y las etapas borradas: sus salidas (kg) y su producción (cajas) se cuentan en `sin_etapa`, para que sumen en los totales sin inflar las hectáreas del plan. Sus cierres (ha) y ventas no se cuentan: no hay superficie del plan contra la cual compararlos.
- `productor` de cada fila: `{id, nombre, tipo}` (`tipo` = `interno`|`externo`, de `productores.tipo`); el front lo usa para las casillas «Excluir Splendid Farms», «Excluir productores internos» y «Solo productores internos».
- `cortes[]` (`{fecha, ha, kg, venta_nacional}`, por fecha): los cierres de cosecha suman sus hectáreas por día sin topar a la superficie de la etapa (el tope es del front) y las salidas de campo (`eliminado = 0`) suman `peso_neto_kg` por día. Se separan en dos cortes del mismo día según `venta_nacional`.
- Regla de venta nacional: un cierre es venta nacional si tiene al menos una venta de cosecha no borrada y con `status <> 'cancelada'` (sin importar su unidad); sus hectáreas (`superficie_cosechada`) van al corte `venta_nacional: true` y a `venta_nacional.ha` una sola vez por cierre. Los kilos del corte y de `venta_nacional.kg` salen de la `cantidad` de esas ventas en el día del cierre (`fecha_inicio`): `kg`, `kgs`, `kilo(s)`, `kilogramo(s)` ×1; `t`, `ton`, `tons`, `tonelada(s)` ×1000 (minúsculas y sin espacios en los bordes). Con otra unidad la venta no suma kilos y se agrega a `avisos` («La venta N está en «unidad» y no se sumó a la venta nacional»).
- `empaque`: producción no borrada (`produccion_empaque`) con el mismo criterio que los reportes de empaque (`ReporteEmpaqueController::produccionPorRecepcion`): un pallet sin desglose va a la etapa de su proceso (`proceso_empaque.etapa_id`) y un pallet con desglose (mixto, con filas en `produccion_empaque_detalles`) se reparte por sus renglones, cada uno con su proceso. La producción de un proceso borrado no cuenta. `cajas_exportacion` y `cajas_nacional` suman los detalles de embarques no borrados según `embarques_empaque.tipo_venta`; en un pallet mixto las cajas embarcadas se reparten entre sus renglones en enteros proporcionales (resto mayor), y la parte de un renglón de proceso borrado no se asigna. `sin_etapa` junta las salidas (kg) y la producción (cajas) sin etapa o con una etapa fuera de la lista.
- Datos reales de Bajío (temporada 4): el pallet mixto MFR-0046 (40 cajas, sin proceso propio) se reparte en L13-E7 (19) y L10-E9 (21), y la salida de 8,090 kg de la etapa borrada L12-E4 va a `sin_etapa`. Pruebas: `DashboardCosechaEjecutivoTest` (13).

## Planeación · Programa semanal (corte, empaque, ventas y logística)

- Reemplaza el Excel «PROGRAMA CORN OI». Solo Splendid Farms: tablas sin `enterprise_id` (`plan_*` = catálogos y configuración; `programa*` = la semana). Modelos en `app/Models/Planeacion/`, servicios en `app/Services/Planeacion/`, controladores en `Api/SplendidFarms/OperacionAgricola/Planeacion/`, rutas bajo `/api/splendidfarms/operacion-agricola/planeacion/` (28: `GET programa?lunes=`, y por programa `cortes`, `cortes/repartir`, `necesidades`, `necesidades/copiar-anterior`, `viajes`, `acuerdos`, `rendimientos`, `inventario-inicial`, `registrar-junta`; `PUT|DELETE` sueltos de `cortes`, `necesidades`, `viajes`, `acuerdos` (+ `PATCH acuerdos/{id}/activo`); `GET clientes`; `GET configuracion` y sus escrituras: `rendimientos`, `rendimientos/copiar`, `grupos`, `recetas-combinacion`, `cajas-por-carga`, `{catalogo}`).
- Permisos (`Concerns/PermisoProgramaSemanal`, vía `PermisoSubmodulo::tiene(..., 'operacion-agricola', 'planeacion', 'programa-semanal', $slug)`): lectura `view`; corte y rendimiento de la semana `capturar-corte`; necesidades `capturar-ventas`; viajes `capturar-logistica`; junta y acuerdos `acuerdos`; configuración e inventario inicial `configurar`. 403 «No tienes permiso para esta acción del Programa semanal.». Los ids que llegan en el cuerpo (necesidad, etapa, temporada) se validan contra el programa o la temporada abierta.
- Un programa por semana (`lunes`, se crea al pedirlo) que junta todas las temporadas abiertas que tocan la semana; filtros `temporada_id`/`cultivo_id`/`variedad_id` en el GET. Días 0–5 = lunes a sábado.
- Balance (`CalculadoraBalance`, por **grupo de balance**: `plan_grupos_balance` junta variedades, p. ej. «Amarillo / Bicolor»; una variedad sin grupo es su propio grupo con clave `v{id}`): producción = ha × rendimiento elegido (medio del rango por defecto; el de la semana se guarda en `programa_rendimientos`), necesidad = cajas de los renglones del grupo, inventario inicial = el capturado o el final del programa existente más cercano hacia atrás (una semana sin programa deja pasar el inventario, Ruling 7); cada `balance[g]` trae `inicial_origen` (`arrastrado`|`capturado`) e `inicial_arrastrado`, y `PUT inventario-inicial` con `cajas: null` quita la captura manual. Necesidades y viajes solo se aceptan en semanas con una temporada abierta del cultivo (422); los renglones de una temporada cerrada se pueden borrar pero no editar. Estados `falta` (algún día < 0), `cubre_inventario` (no falta, pero se apoya en el inicial) y `cubierto`. `final_sin_acuerdos` usa el mismo inicial efectivo.
- Acuerdos (`programa_acuerdos`, capas activas/inactivas): `adelantar_etapa` (ha × rendimiento al día elegido, tope = ha disponibles de la etapa), `mover_pedido` (resta del renglón y suma en la semana/día destino, topado a las cajas del renglón; `PlanSemana::repartirMovidas`) y `conseguir_producto` (cajas de un origen libre). Borrar un renglón borra también sus acuerdos de mover pedido, con una entrada de historial por cada uno (`programa_cambios`), y avisa por tiempo real a la semana destino. `opcionesFalta` del payload trae, por grupo con falta, las etapas próximas que se pueden adelantar y los renglones que se pueden mover.
- Historial: `RegistroCambiosPrograma` escribe `programa_cambios` en cada escritura de la semana (no en configuración, Ruling 10).
- Tiempo real: `App\Events\ProgramaSemanalUpdated` (`programa.updated`, `{ lunes, programa_id }`) por `module.splendidfarms.operacion-agricola.planeacion`, uno solo por programa y transacción (`RegistroCambiosPrograma` es `#[Scoped]` y junta los avisos en un `DB::afterCommit`; copiar la semana o repartir emite una vez), con try/catch: si Reverb está caído la escritura se guarda, queda un WARNING en el log y la respuesta tarda ~2 s más (timeout de cURL).
- Pruebas: `tests/Feature/SplendidFarms/OperacionAgricola/Planeacion/*` (trait `ConstruyePrograma`, con `cargarWk48()` = la WK48 del Excel: Amarillo/Bicolor +10,635 `cubre_inventario`; Blanco −2,893, falta desde el miércoles, peor −4,882; con un adelanto de 4 ha queda en −93) y `tests/Unit/Planeacion/CalculadoraBalanceTest.php`.
- Despliegue: 4 migraciones `2026_10_28_1000..1003` (tablas, submódulo con 6 permisos y acceso para admin@/demo@, catálogo del elote; las dos últimas son idempotentes). Si hay pendientes de otros módulos, correr estas con `--path` o seguir antes sus propias listas. Lista completa en `docs/superpowers/plans/2026-10-28-programa-semanal-deploy.md` del repo externo. Back antes que el front.

## Reportes · Recepciones vs. Embarques (rezaga por folio)

- `GET /api/splendidfarms/administration/reportes/empaque/rezaga-recepciones?temporada_id=&empaques[]=&desde=&hasta=` descarga `Consolidado_Recepciones_Embarques_{Y-m-d}.xlsx` (`ReporteRezagaRecepcionesController::descargar`, `response()->download()->deleteFileAfterSend()`); `GET …/rezaga-recepciones/empaques?temporada_id=` lista las plantas con recepciones (`id`, `nombre`, `hoja`, `folios`). `temporada_id` es obligatorio; `empaques[]` vacío = todas las que tengan datos; `desde`/`hasta` (`Y-m-d`) filtran la fecha de RECEPCIÓN; sin datos → 422 «No hay recepciones con esos filtros.». Solo `auth:sanctum`, como el resto de Reportes. `config/cors.php` expone `Content-Disposition` para que el front lea el nombre.
- `App\Services\Reportes\RezagaRecepcionesService`: `obtenerDatos()` (recepciones no borradas con folio, orden fecha+folio; LEFT JOIN con embarques agrupados por folio × manifiesto × folio/fecha de embarque en SQL con `COUNT(DISTINCT numero_pallet)`) y `generarExcel()`. Atribución pallet → folio como `ReporteEmpaqueController::produccionPorRecepcion()`: pallet sin desglose → folio de su proceso; con desglose (`produccion_empaque_detalles`) → cada entrada a su folio, con sus cajas y `peso_neto_kg`, y la báscula del pallet (`ed.peso_bascula_kg`, si no `pe.peso_bascula_kg`) repartida por cajas. Se excluyen embarques borrados, `cancelado` y sin manifiesto. Peso de la rezaga = `peso_recibido_kg`; báscula nula = 0 («Sin captura»).
- Pestaña = nombre de la planta sin el prefijo «Empaque », sin espacios ni caracteres inválidos, ≤ 24 caracteres (para «Rezaga {hoja}»); tabla `tbl{hoja en ASCII}`. «Produce Firts» sale tal cual: el nombre está mal capturado en `entities`.
- **Fórmulas precalculadas en PHP:** el Excel lleva fórmulas, pero sus valores se calculan en PHP y se dejan en la caché del motor (`Calculation::saveValueToCache`) antes de `setPreCalculateFormulas(true)`; evaluar los COUNTIFS/SUMIFS con PhpSpreadsheet tardaba ~30 s con 230 filas (crece cuadráticamente). Si se cambia una fórmula hay que cambiar también su valor (`precalcular`); la prueba recalcula todo el libro con el motor y compara. Después de guardar, `igualarNombresDeTablas` pone `name` = `displayName` en `xl/tables/*.xml` (PhpSpreadsheet escribe `name="TableN"`).
- Pruebas: `ReporteRezagaRecepcionesTest`. Con la BD local (temporada 4) Agrigar y FreshCooling cuadran exacto con la especificación (36/3/1, 320,235 / 255,411.20; 17/1/0, 149,760 / 91,062.80); MacuFrescos y ProduceFirst tienen menos folios en local que en producción. Despliegue: `php artisan migrate` (`2026_10_22_100000_ensure_reportes_empaque_productores_submodules`: los submódulos Reportes → Empaque y Productores solo los creaba `MasterStructureSeeder`, sin tipos de permiso; la migración los asegura con view/create/edit/delete y da acceso a admin@ y demo@; a los demás usuarios se les concede en el panel) + `php artisan route:clear && php artisan config:clear` (por CORS). Back antes que el front.

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

- **Testing real: en progreso.** Cobertura completa (1118 tests) en dos módulos como referencia de patrón:
  - `tests/Feature/ActivosFijos/` — Activos Fijos (506 tests en 49 archivos: ActivoCrud, AislamientoActivos, AlcanceYPermisos, AltaCompraApi, AltaCompraBloqueoAnulacion, AltaCompraBloqueoSalidas, AltaCompraCreador, AltaCompraEsquema, AltaCompraGeneracion, AltaCompraServicio, AsignacionActivaEnListado, AsignacionApi, AsignacionCarta, AsignacionModelo, AsignadorActivos, BandejaAsignacionesMovimientos, BandejaAsignacionesResumen, CalculadoraDepreciacion, CalculoSalida, ContextoActivos, DepreciacionApi, DepreciacionCierre, DepreciacionConSalida, DepreciacionEsquema, DepreciacionResolutor, DeshacerSalida, GeneradorCodigoActivo, ImportLegacyEmpresa, IntegridadActivo, ListaActivosFiltros, ListaActivosResumen, MigracionActivosPorEmpresa, MigracionMenuActivos, OrganizacionEmpresasNuevas, RegistradorSalida, ResponsablesActivo, RutasGenericasPorEmpresa, SalidaActivoEstado, SalidaApi, SalidaCarta, SalidaEsquema, SembradoAsignaciones, SembradoDepreciacion, SembradoPrefijosActivos, SembradoSalidas, SembradoTodasLasEmpresas, TiempoRealActivos, TipoActivo, TraspasoSalida). Resuelven la empresa desde la URL (`/api/{empresa}/administration/activos-fijos/...`), cubren SF y GE (y SBP en las pruebas de aislamiento) y usan `tests/Concerns/CreatesAssetFixtures.php` (SF + GE con Administración → Activos Fijos y helpers de permisos `activos.view/create/edit/delete`; las pruebas de asignaciones suman `tests/Concerns/CreatesAssignmentFixtures.php`, las de alta desde compras `tests/Concerns/CreatesAltaCompraFixtures.php` y las de depreciación `tests/Concerns/CreatesDepreciacionFixtures.php`, y las de salidas `tests/Concerns/CreatesSalidaFixtures.php`).
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
- Cada respaldo se guarda en su propia carpeta `backups/{nombre sin .sql.gz}/{nombre}.sql.gz` (`DatabaseBackup::rutaRelativa()`; los sueltos anteriores se resuelven por compatibilidad). El servicio crea la carpeta con `chmod 02775` para que el usuario web pueda borrar su archivo.
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
