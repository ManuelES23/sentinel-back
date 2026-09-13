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

**Bug de aislamiento encontrado en esta sesión (bloqueante, no opcional) —
corregido tras revisar los controllers completos.** `products` **sí** tiene
un mecanismo de aislamiento parcialmente construido: tabla pivote
muchos-a-muchos `enterprise_product` (ya poblada, todo lo existente enlazado
a Splendid Farms), resuelta vía header `X-Enterprise-Slug` +
`Product::scopeForEnterprise()`, y `ProductController@index`/`store` ya la
usan. `product_categories`, `brands` y `units_of_measure` **no tienen nada**
— confirmado revisando sus controllers, ningún filtro por empresa en
ninguno de los tres. `recipes`/`recipe_items` tampoco tienen nada —
`RecipeController@index` no filtra por empresa en ningún punto, y no existe
ninguna tabla pivote para ellas. Splendid Farms y Splendid by Porvenir ya
comparten hoy, sin saberlo, las mismas recetas y (para todo lo que no sea
`Product`) el mismo catálogo — solo no se nota porque nadie más entra a esas
rutas. Dar de alta Recetas para Canes Agro tal cual mezclaría su catálogo y
sus recetas con las de Splendid Farms.

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

Dos mecanismos distintos, cada uno siguiendo el patrón que **ya existe en
el proyecto** para el caso análogo — no se introduce un trait nuevo tipo
`BelongsToEnterprise`, se replica lo que `Product`/`enterprise_product` ya
hacen:

**a) Categorías, marcas y unidades — pivote muchos-a-muchos, igual que
`Product`.** Un artículo o categoría legítimamente puede compartirse entre
negocios (ya es el diseño actual de `Product`); una tabla de referencia como
"Kilogramos" no tiene por qué duplicarse por empresa. Se crean 3 tablas
pivote nuevas siguiendo exactamente la forma de `enterprise_product`:

```php
Schema::create('enterprise_product_category', function (Blueprint $table) {
    $table->id();
    $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
    $table->foreignId('product_category_id')->constrained()->cascadeOnDelete();
    $table->timestamps();
    $table->unique(['enterprise_id', 'product_category_id']);
});
// mismo patrón para enterprise_brand (brand_id) y enterprise_unit_of_measure (unit_of_measure_id)
```

Cada migración backfillea lo existente a Splendid Farms (mismo bloque
`DB::table('enterprises')->where('slug','splendidfarms')...` que ya usa
`2026_04_09_002324_create_enterprise_product_table.php`). `ProductCategory`,
`Brand`, `UnitOfMeasure` ganan una relación `enterprises(): BelongsToMany` y
un `scopeForEnterprise()` idénticos a los de `Product`. Sus controllers
(`ProductCategoryController`, `BrandController`, `UnitOfMeasureController`)
ganan el mismo bloque que ya está repetido 4 veces en `ProductController`:
leer `X-Enterprise-Slug`, resolver la empresa, aplicar `->forEnterprise($id)`
en `index()` y `syncWithoutDetaching()` en `store()`.

**b) Recetas — columna `enterprise_id` directa, no pivote.** A diferencia de
un artículo, una receta no tiene sentido compartida entre negocios (es una
fórmula propia). Migración de 3 pasos (nullable → backfill a Splendid Farms
→ NOT NULL) sobre `recipes` y `recipe_items`:

```php
$table->foreignId('enterprise_id')->nullable()->after('id')
    ->constrained('enterprises')->cascadeOnDelete();
```

`Recipe`/`RecipeItem` ganan un `scopeForEnterprise()` propio (mismo nombre
que en `Product`, misma firma, para que el patrón sea reconocible). Se
resuelve con el mismo header `X-Enterprise-Slug` que ya usa
`ProductController` y que `RecipeController::syncOutputProduct()` **ya lee**
para enlazar el producto de salida — hoy es la única función del archivo que
conoce la empresa activa; con este cambio la conoce todo el controller.
`RecipeCalibre`/`RecipeCalibrePlu` no necesitan columna propia — heredan
aislamiento a través de `Recipe`.

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

1. Migraciones de aislamiento — pivotes para categorías/marcas/unidades y
   columna `enterprise_id` en recipes/recipe_items — se pueden desplegar
   solas, son retrocompatibles (backfill automático a Splendid Farms), sin
   afectar el frontend. `products` no requiere migración, ya tiene
   `enterprise_product`; solo sus controllers hermanos (categorías, marcas,
   unidades) se ponen al día con el filtro que `ProductController` ya usa.
2. Split de `recipe_agro_details` + generalización del modelo — junto con la
   reestructura del backend de `RecipeController`.
3. Alta de Canes Agro (Applications/Modules/Submodules + rutas).
4. Funcionalidad nueva (versionado, aprobación, trazabilidad, comparar).
5. Reestructura del frontend + Propuesta C.

## Riesgos conocidos

- El backfill de las tablas nuevas (categorías/marcas/unidades/recetas)
  asume que **todo** lo existente hoy pertenece a Splendid Farms — mismo
  supuesto que ya usó `enterprise_product` en su momento. Si Splendid by
  Porvenir ya generó categorías, marcas o recetas propias sin saberlo
  (comparte las mismas tablas hoy), esos registros quedarían mal asignados
  en el backfill — se audita antes de correr las migraciones en producción.
  `products` no corre este riesgo de nuevo: su backfill ya se hizo y no se
  toca.
- Reusar `ApprovalProcess` asume que sus pasos de aprobación (por puesto/
  nivel jerárquico, vía `Employee`/`Position`) tienen sentido para Recetas;
  si Canes Agro no tiene esa estructura de puestos aún configurada, el flujo
  de aprobación no tendrá aprobadores válidos hasta que se configure.
