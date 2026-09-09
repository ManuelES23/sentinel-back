# Generalización del submódulo de Recetas (BOM) para multi-empresa — Design Spec

## Contexto

El submódulo de Recetas (`RecetasView.jsx`, ~3300 líneas; `Recipe`/`RecipeItem`/
`RecipeCalibre`/`RecipeCalibrePlu` + `RecipeController` en el backend) vive hoy
bajo `splendidfarms/inventario/catalogos/recetas` y bajo su copia literal en
`splendidbyporvenir` (mismas rutas, mismo controller, mismas tablas). Fue
diseñado exclusivamente para el caso de uso de empaque agrícola de Splendid
Farms: cada receta es un BOM con campos de cultivo/variedad/calibre/PLU y una
lógica especial que fuerza un grupo intercambiable "Caja" cuando la receta es
de tipo `empaque` sobre una categoría "pallet".

**Empresa nueva: Canes Agro.** Auditado en la base de datos (`enterprises.id =
6`, slug `canes-agro`, activa desde 2026-07-04). Hoy solo tiene provisionada
la aplicación "CRM Comercial" (`applications.enterprise_id = 6`) — cero
Inventario, cero Recetas. Es una comercializadora de agroinsumos: necesita
combinar artículos (insumos) para crear productos propios, sin ningún
concepto agrícola de Splendid Farms.

**Bug de aislamiento encontrado en esta sesión (bloqueante, no opcional).**
`products`, `product_categories`, `brands`, `units_of_measure`, `recipes` y
`recipe_items` no tienen `enterprise_id` — confirmado con `Schema::
getColumnListing()` vía tinker. `RecipeController@index` no filtra por
empresa en ningún punto. Splendid Farms y Splendid by Porvenir ya comparten
hoy, sin saberlo, el mismo catálogo de artículos y las mismas recetas en la
base de datos — solo no se nota porque nadie más entra a esas rutas. Dar de
alta Recetas para Canes Agro tal cual mezclaría su catálogo con el de
Splendid Farms.

Las rutas de negocio (`splendidfarms`, `splendidbyporvenir`,
`grupoesplendido`) están hardcodeadas en `routes/api.php` — no existe (en
este proyecto, a diferencia de una copia hermana más adelantada) un mecanismo
de "empresa espejo" data-driven. Cada negocio nuevo requiere un bloque de
rutas agregado a mano.

## Objetivo

1. Aislar por empresa el catálogo de inventario y las recetas (`enterprise_id`
   + filtro automático), sin romper los datos existentes de Splendid Farms /
   Splendid by Porvenir.
2. Generalizar el modelo de Receta: un núcleo universal (artículos, grupos,
   costo, estado, versión, producto de salida) + una extensión agrícola
   opcional (cultivo/variedad/calibre/PLU) que Canes Agro simplemente no usa.
3. Dar de alta Inventario → Catálogos → Recetas para Canes Agro sobre esa
   base generalizada.
4. Agregar funcionalidad hoy ausente: versionado real, flujo de aprobación
   (reusando el motor de aprobaciones ya existente en el proyecto),
   trazabilidad de uso, y duplicar/comparar recetas.
5. Partir `RecetasView.jsx` en piezas chicas y aislables, y rediseñar el
   editor como una vista dividida con preview en vivo (Propuesta C, elegida
   entre 3 mockups estructurales comparados con el usuario).

## No-objetivos

- No se construye un mecanismo de "empresa espejo" data-driven tipo la copia
  hermana del proyecto (`mirror_source_id`) — eso es un rediseño de todo el
  sistema de rutas, fuera de alcance. Canes Agro se da de alta con un bloque
  de rutas propio, siguiendo el mismo patrón manual que ya usa
  `splendidbyporvenir`.
- No se toca `grupoesplendido` (suite RH) — no tiene inventario ni recetas.
- No se migra `recipe_calibres`/`recipe_calibre_plus` a un esquema distinto
  más allá de moverlas conceptualmente bajo la extensión agrícola; su
  estructura interna no cambia.
- No se resuelve aquí ningún otro módulo de Inventario más allá de lo que
  las recetas necesitan tocar (Artículos, Categorías, Marcas, Unidades) —
  Activos Fijos, Compras, Reportes de Inventario quedan fuera.

## Decisión de arquitectura

Se evaluaron 3 approaches con el usuario:

- **A — Módulo único con campos condicionales**: agregar `enterprise_id` y
  mostrar/ocultar cultivo/variedad/calibre según el perfil de la empresa.
  Rápido, pero acumula condicionales sobre un archivo ya sobrecargado.
- **B — Módulo "Combos" independiente para Canes Agro**: tablas y vista
  nuevas, sin tocar Recetas de Splendid Farms. Cero riesgo de regresión, pero
  duplica costeo/versionado/aprobación para siempre.
- **C — Núcleo genérico + extensión agrícola opcional** (elegido): separar lo
  universal de lo agrícola en el modelo de datos y en el frontend; la
  funcionalidad nueva (versionado, aprobación, trazabilidad, comparación) se
  construye una sola vez en el núcleo y la heredan ambos negocios.

Justificación: el archivo de 3300 líneas y el bug de aislamiento se tienen
que resolver de cualquier forma; construir el núcleo genérico correctamente
desde ahí cuesta poco extra sobre A y evita la deuda duplicada de B.

## Diseño

### 1. Aislamiento por empresa

Migración (mismo patrón de 3 pasos ya usado en otros retrofits del
proyecto: columna nullable → backfill a Splendid Farms → NOT NULL) sobre:
`products`, `product_categories`, `brands`, `units_of_measure`, `recipes`,
`recipe_items`.

```php
$table->foreignId('enterprise_id')->nullable()->after('id')
    ->constrained('enterprises')->cascadeOnDelete();
```

Trait `BelongsToEnterprise` (nuevo) + global scope que filtra automáticamente
por la empresa resuelta del usuario autenticado (mismo mecanismo de
resolución que ya usa el resto del sistema de permisos jerárquicos). Se
aplica a los 6 modelos de la lista. `RecipeController`, `ProductController`,
`ProductCategoryController`, `BrandController`, `UnitOfMeasureController` no
cambian su lógica interna — el scope filtra antes de que la query llegue al
controller. `RecipeCalibre`/`RecipeCalibrePlu` heredan aislamiento a través
de `Recipe` (no necesitan columna propia, mismo criterio que las tablas pivote
documentadas en retrofits anteriores del proyecto).

### 2. Generalización del modelo de Receta

`recipes` pierde `cultivo_id`, `variedad_id`, `peso_pieza` como columnas
propias. Tabla nueva `recipe_agro_details` (1:1 con `recipes`, nullable):

```php
Schema::create('recipe_agro_details', function (Blueprint $table) {
    $table->id();
    $table->foreignId('recipe_id')->unique()->constrained()->cascadeOnDelete();
    $table->foreignId('cultivo_id')->nullable()->constrained('cultivos');
    $table->foreignId('variedad_id')->nullable()->constrained('variedades');
    $table->decimal('peso_pieza', 10, 4)->nullable();
    $table->timestamps();
});
```

`recipe_calibres`/`recipe_calibre_plus` pasan conceptualmente a ser parte de
esta extensión (siguen siendo tablas separadas, solo se cargan/consultan
junto con `recipe_agro_details`). Una receta sin fila en
`recipe_agro_details` es, sin ningún cambio adicional, una combinación pura
de artículos — el caso de Canes Agro.

`Recipe::calculateEstimatedCost()`, `getGroups()` y el resto del núcleo no
cambian: ya eran agnósticos de agricultura.

### 3. Alta de Canes Agro

- `Applications` (Inventario) → `Modules` (Catálogos) → `Submodules`
  (Artículos, Categorías, Marcas, Unidades, **Recetas**), `enterprise_id = 6`,
  reusando `firstOrCreate` como el resto de seeders del proyecto.
- Bloque de rutas nuevo en `api.php` bajo prefijo `canes-agro`, apuntando a
  los mismos controllers que `splendidfarms` (ya quedan aislados por el
  trait) — mismo patrón manual que ya existe para `splendidbyporvenir`, sin
  Operación Agrícola/Cosecha/Empaque (Canes Agro no los necesita).
- Frontend: nueva entrada de `ModuleLoader` para el prefijo `canes-agro`
  apuntando a las vistas ya existentes de Inventario (no se duplican
  componentes).

### 4. Funcionalidad nueva

- **Versionado**: tabla `recipe_versions` (`recipe_id`, `version_number`
  autoincremental por receta, `snapshot` JSON, `created_by`, `change_note`,
  `created_at`). Cada guardado con cambios reales snapshotea el estado
  anterior antes de aplicar el cambio. UI: pestaña "Historial" con diff y
  "Restaurar" (crea una versión nueva, nunca sobreescribe).
- **Aprobación**: se reusa el motor existente `ApprovalProcess` +
  `ApprovalFlowStep` (ya usado para vacaciones, compras, movimientos de
  inventario). Nuevo proceso `recipe_approval` (`module = 'inventario'`).
  `Recipe.status` gana `pending_approval`; `RecipeController` gana
  `submit-for-approval` / `approve` / `reject`, validados contra
  `ApprovalProcess::canBeApprovedBy()`. Las recetas pendientes aparecen en el
  inbox que ya usa `PendingApprovalController` — no se construye bandeja
  nueva.
- **Trazabilidad de uso**: `GET /recetas/{recipe}/uso` (vía
  `produccion_empaque.recipe_id`, ya existente) y
  `GET /articulos/{product}/usado-en-recetas` (inverso). Ambos expuestos como
  pestaña/badge en la UI.
- **Duplicar y comparar**: "Usar como plantilla" ya existe. Modo comparación
  nuevo: 2 recetas lado a lado con diferencias resaltadas, calculado en el
  frontend a partir de los payloads de detalle ya existentes — sin backend
  nuevo.

### 5. Reestructura del frontend

```
recetas/
  RecetasView.jsx              — contenedor: fetch, filtros, abre/cierra modales
  constants.js                 — STATUS_CONFIG, RECIPE_TYPE_CONFIG, estilos react-select
  components/
    RecipeCard.jsx
    RecipeGroupedGrid.jsx
    RecipeFilters.jsx
  editor/                       — Propuesta C: vista dividida + preview en vivo
    RecipeEditorView.jsx        — layout de 2 columnas, ya no modal de scroll único
    sections/
      GeneralSection.jsx
      OutputProductSection.jsx
      ItemsSection.jsx
      GroupsSection.jsx
      AgroDetailsSection.jsx    — solo se monta si la receta tiene extensión agrícola
    RecipeLivePreview.jsx       — reutiliza el mismo look de RecipeCard, se actualiza
                                   con cada cambio de estado del formulario, sin guardar
    hooks/useRecipeEditorState.js
  detail/
    RecipeDetailModal.jsx
    tabs/OverviewTab.jsx, ItemsTab.jsx, HistoryTab.jsx (nuevo), UsageTab.jsx (nuevo)
  compare/RecipeCompareModal.jsx (nuevo)
```

**Propuesta C** (elegida entre 3 mockups estructurales comparados
visualmente con el usuario): cada sección del formulario vive en un
acordeón — una sola expandida a la vez, nunca el formulario completo en
pantalla — y una tarjeta de preview a la derecha, actualizándose en vivo
(artículos, costo estimado, producto de salida) sin necesidad de guardar.
`AgroDetailsSection` no se monta cuando la receta no tiene extensión
agrícola — así es como Canes Agro nunca ve cultivo/variedad/calibre.

## Testing

- **Backend**: feature tests de aislamiento — crear receta/artículo en
  Canes Agro y en Splendid Farms, confirmar que cada empresa solo ve lo
  suyo en `index()`. Tests de la migración de `recipe_agro_details` (recetas
  existentes de Splendid Farms conservan sus datos agrícolas tras el
  split). Tests del flujo de aprobación (envío, aprobación, rechazo,
  permisos). Tests de versionado (snapshot correcto, restaurar no
  sobreescribe historia).
- **Frontend**: verificación manual en el navegador de que
  `AgroDetailsSection` no se renderiza para una receta de Canes Agro, y que
  el preview en vivo refleja cambios sin submit.

## Rollout

1. Migraciones de aislamiento (`enterprise_id` en las 6 tablas) — se puede
   desplegar solo, es retrocompatible (backfill automático a Splendid
   Farms), sin afectar el frontend.
2. Split de `recipe_agro_details` + generalización del modelo — junto con la
   reestructura del backend de `RecipeController`.
3. Alta de Canes Agro (Applications/Modules/Submodules + rutas).
4. Funcionalidad nueva (versionado, aprobación, trazabilidad, comparar).
5. Reestructura del frontend + Propuesta C.

## Riesgos conocidos

- El backfill de `enterprise_id` asume que **todo** lo existente en
  `products`/`recipes` hoy pertenece a Splendid Farms. Si Splendid by
  Porvenir ya generó artículos o recetas propias sin saberlo (comparte las
  mismas tablas hoy), esos registros quedarían mal asignados en el backfill
  — se audita antes de correr la migración en producción.
- Reusar `ApprovalProcess` asume que sus pasos de aprobación (por puesto/
  nivel jerárquico, vía `Employee`/`Position`) tienen sentido para Recetas;
  si Canes Agro no tiene esa estructura de puestos aún configurada, el flujo
  de aprobación no tendrá aprobadores válidos hasta que se configure.
