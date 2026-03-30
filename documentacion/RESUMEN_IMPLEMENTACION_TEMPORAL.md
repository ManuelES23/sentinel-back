# SENTINEL 3.0 - Resumen de Implementación

## Descripción General

SENTINEL 3.0 es un ERP multi-tenant construido con Laravel 12 (backend) y React 19 (frontend). Gestiona múltiples empresas con una jerarquía de permisos: **Empresa → Aplicación → Módulo → Submódulo**.

### Empresas Implementadas

| Empresa              | Aplicaciones                                                 |
| -------------------- | ------------------------------------------------------------ |
| Splendid Farms       | Administración, Inventario, Contabilidad, Operación Agrícola |
| Grupo Espléndido     | Recursos Humanos                                             |
| Splendid by Porvenir | Placeholder (sin implementación)                             |

---

## Módulos Implementados

### 1. Sistema Base

- **Autenticación**: Login/logout con Laravel Sanctum (tokens Bearer)
- **Permisos jerárquicos**: Usuario → Empresa → App → Módulo → Submódulo → Permisos
- **Procesos de aprobación configurables**: Vacaciones, OC, incidencias, movimientos de inventario
- **Notificaciones en tiempo real**: Via Laravel Reverb (WebSockets), con fluent API
- **Perfil de usuario**: Datos personales, foto, cambio de contraseña, vacaciones propias
- **Pendientes por aprobar**: Centralizados desde cualquier proceso
- **Auditoría**: Trait `Loggable` + headers de contexto X- en todas las peticiones

### 2. Splendid Farms - Administración

- **Organización**: Sucursales, tipos de entidad, entidades, áreas
- **Agrícola**: Cultivos, ciclos agrícolas, temporadas (con gestión temporal M2M), variedades, tipos de variedad, productores, zonas de cultivo, lotes
- **Catálogos**: Proveedores (con contactos)

### 3. Splendid Farms - Inventario

- **Catálogos**: Categorías (tree jerárquico), marcas (CRUD + `list` para selects), artículos (imagen, marca FK, is_for_sale), recetas/BOM, tipos de carga, tipos de movimiento, unidades de medida
- **Operaciones**: Entradas, salidas, transferencias, ajustes de inventario (kardex + stock automático)
- **Compras**: Órdenes de compra (flujo de estados: borrador→enviada→parcial→completa/cancelada), recepciones de compra
- **Reportes**: Stock actual, movimientos, valorizado

### 4. Splendid Farms - Contabilidad

- **Cuentas por pagar**: Documentos con pagos parciales/totales

### 5. Splendid Farms - Operación Agrícola

- **Agrícola**: Productores (simplificado), zonas de cultivo, lotes, etapas, etapas fenológicas, plagas, visitas de campo (fotos, plagas, recomendaciones), requisiciones de insumos, costeo agrícola, diagnóstico IA
- **Cosecha**: Salidas de campo, cierres de cosecha, ventas de cosecha, calidad
- **Empaque (7 fases)**: Recepciones → Proceso → Producción → Rezaga → Embarques → Venta de rezaga → Calidad

### 6. Grupo Espléndido - Recursos Humanos

- **Catálogos**: Departamentos (jerárquico), puestos, horarios
- **Empleados**: Lista con QR, PIN, credencial
- **Asistencia**: Registros, checador público (sin auth, QR/PIN)
- **Gestión**: Vacaciones (LFT México), incidencias, tipos de incidencia

---

## Implementaciones Recientes

### Catálogo de Marcas (Inventario)

- Tabla `brands`: id, code (auto MRC-XXX), name, is_active, timestamps, softDeletes
- `BrandController` con CRUD completo + endpoint `list` para dropdowns
- Relación: `Brand hasMany Product`, `Product belongsTo Brand` (nullable FK)
- Frontend: select de marca en modal de artículos con **quick-create** inline (botón + para crear marca sin salir del formulario)

### Sistema de Gestión Temporal

- Relaciones Many-to-Many para productores, zonas y lotes por temporada
- Tablas pivot: `temporada_productor`, `temporada_zona_cultivo`, `temporada_lote`
- 14 endpoints en `TemporadaController` para asignar/desasignar/toggle
- Hook `useTemporadas` con 9 métodos adicionales

### Módulo Empaque

- 7 fases completas con controllers, modelos, hooks y vistas independientes
- EmbarqueEmpaque y VentaRezagaEmpaque con modelos de detalle
- Filtrado por `entity_id` (planta empacadora seleccionada en frontend)
- Contexto `EmpaqueOAContext` persiste selección en sessionStorage

---

## Patrones de Código

### Auto-generación de Códigos

```php
$lastCode = Brand::withTrashed()->where('code', 'like', 'MRC-%')->orderByDesc('code')->value('code');
$nextNumber = $lastCode ? (int)substr($lastCode, 4) + 1 : 1;
$code = 'MRC-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
```

**Prefijos**: PROD-XXXXX (productos), MRC-XXX (marcas), CAT-XXXX (categorías), OC-XXXXX (órdenes compra)

### Endpoint `list` para Selects

```php
public function list() {
    $brands = Brand::where('is_active', true)->select('id', 'code', 'name')->orderBy('name')->get();
    return response()->json(['success' => true, 'data' => $brands]);
}
```

### Respuestas JSON

```php
// Éxito
return response()->json(['success' => true, 'message' => 'Operación exitosa', 'data' => $resource], 200);

// Error
return response()->json(['status' => 'error', 'message' => 'Descripción del error'], 404);
```

---

## Estadísticas

| Recurso            | Cantidad |
| ------------------ | -------- |
| Controllers        | 48       |
| Modelos            | 87       |
| Events (broadcast) | 13       |
| Servicios          | 4        |
| Migraciones        | ~80      |
| Rutas API          | ~200+    |

---

## Comandos

```bash
composer dev                      # Server + queue + logs + reverb
php artisan migrate               # Ejecutar migraciones
php artisan migrate:fresh --seed  # Reset DB
php artisan tinker                # REPL
php artisan pail                  # Logs tiempo real
php artisan reverb:start          # WebSocket server
```
