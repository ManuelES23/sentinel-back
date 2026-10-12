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

Módulo de Administración → Activos Fijos (SF y GE). La carpeta de controlador `Inventory/` es heredada; ver la estructura de controllers.

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
- Huecos conocidos: (a) si un activo de compra vuelve de `baja` a otro estado después de haberse dado salida a su stock, su unidad vuelve a contar como vigente y retiene una pieza de la siguiente entrada (la semántica de la baja queda pendiente para la fase 5); (b) si el almacén de la recepción pertenece a otra empresa (acceso cruzado) o está inactivo, el alta responde 422 (`branch_id`/`entity_id`) y la unidad solo se puede descartar; (c) `ConsumidorInventarioAplicacion::repartir` no descuenta las reservas al repartir FIFO entre lotes, así que puede elegir un lote respaldado y la aplicación responde 422 `stock` aunque otro lote tenga existencia libre; (d) las recepciones sin empresa no generan unidades de activo fijo (`GeneradorUnidadesCompra` devuelve 0 y la confirmación sigue normal).

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
- `POST/PATCH crm/actividades` validan `vendedor_id` con `exists:crm_vendedores,id` sin filtrar por empresa.
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
   - Después de `migrate`, aprobadores (`aprobar` o `rechazar`) sin `oportunidades.equipo` concedido: solo les queda una fila `equipo` revocada. Solo aprueban las cotizaciones de sus propias oportunidades. Para listarlos, cambiar la primera condición de la consulta anterior por `spt.slug IN ('aprobar', 'rechazar')`, y en el `NOT EXISTS` usar `ovt.slug = 'equipo'`. Local, antes de migrar: 1.
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

- **Testing real: en progreso.** Cobertura completa (563 tests) en dos módulos como referencia de patrón:
  - `tests/Feature/ActivosFijos/` — Activos Fijos (213 tests en 26 archivos: ActivoCrud, AislamientoActivos, AlcanceYPermisos, AltaCompraApi, AltaCompraBloqueoAnulacion, AltaCompraBloqueoSalidas, AltaCompraCreador, AltaCompraEsquema, AltaCompraGeneracion, AltaCompraServicio, AsignacionActivaEnListado, AsignacionApi, AsignacionCarta, AsignacionModelo, AsignadorActivos, ContextoActivos, GeneradorCodigoActivo, ImportLegacyEmpresa, IntegridadActivo, MigracionActivosPorEmpresa, MigracionMenuActivos, ResponsablesActivo, SembradoAsignaciones, SembradoPrefijosActivos, TiempoRealActivos, TipoActivo). Resuelven la empresa desde la URL (`/api/{empresa}/administration/activos-fijos/...`), cubren SF y GE (y SBP en las pruebas de aislamiento) y usan `tests/Concerns/CreatesAssetFixtures.php` (SF + GE con Administración → Activos Fijos y helpers de permisos `activos.view/create/edit/delete`; las pruebas de asignaciones suman `tests/Concerns/CreatesAssignmentFixtures.php` y las de alta desde compras `tests/Concerns/CreatesAltaCompraFixtures.php`).
  - `tests/Feature/CRM/` — CRM Comercial (40 archivos, 350 tests; arrancó con Cliente, Prospecto, Vendedor, Región/Zona/Bodega, Producto, Contacto, EmpresaExterna y Actividad), usa `tests/Concerns/CreatesCrmFixtures.php`. A diferencia de Activos Fijos, el CRM resuelve la empresa por el header `X-Enterprise-Id` (no por la URL) — los tests lo mandan explícitamente vía `$this->crmHeaders()`.
  - El resto de módulos (RH, Agrícola, Empaque) todavía no tiene cobertura.
  - Escribir estos tests **encontró y corrigió 2 bugs reales** que llevaban tiempo sin detectarse por falta de cobertura: `ClienteController::index()` llamaba a un método de scope inexistente (`->empresa()` en vez de `->where('empresa_id', ...)`) y tronaba 500 en cada request; y `crm_vendedores.user_id` era `NOT NULL` en la BD aunque la validación del controller lo declara opcional, causando un 500 al crear un vendedor sin cuenta ligada (migración `2026_08_11_190000_make_user_id_nullable_on_crm_vendedores_table.php`).
- **Los tests corren en SQLite in-memory** (`phpunit.xml`), no contra MySQL. Varias migraciones antiguas tenían SQL específico de MySQL (`MODIFY COLUMN`, índices no liberados antes de `dropColumn`) que se fueron arreglando conforme se encontraron al escribir tests — si al escribir un test nuevo `RefreshDatabase` truena con un error de sintaxis SQLite en una migración que no tocaste, es este mismo patrón: revisa si falta `dropIndex()` antes de un `dropColumn()`, o si hay un `DB::statement()` con sintaxis de MySQL que necesita un guard `if (DB::getDriverName() === 'mysql')`.
- **CI de CRM ahora sí prueba algo**: `.github/workflows/crm-ci.yml` corre `--filter=CRM`, y antes de este trabajo eso coincidía con 0 tests (`INFO No tests found`, exit code 0 — el badge siempre salía verde sin validar nada). Ya hay 350 tests reales bajo ese filtro. Los demás módulos (incluyendo Activos Fijos) siguen sin estar en ningún workflow de CI — sería el siguiente paso natural.
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
