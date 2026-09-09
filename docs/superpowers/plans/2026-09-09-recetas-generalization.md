# Generalización de Recetas para Multi-Empresa — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aislar por empresa el catálogo de inventario y las recetas, generalizar el modelo de Receta (núcleo + extensión agrícola opcional), dar de alta Inventario/Recetas para Canes Agro, agregar versionado/aprobación/trazabilidad/comparación, y rediseñar el editor como una vista dividida con preview en vivo.

**Architecture:** Backend Laravel (`sentinel-back`) + Frontend React (`sentinel-front`). Aislamiento vía dos mecanismos ya establecidos en el código: pivote muchos-a-muchos + header `X-Enterprise-Slug` (igual que `Product`/`enterprise_product`) para categorías/marcas/unidades, y columna `enterprise_id` directa para recetas (una receta pertenece a una sola empresa). El modelo de Receta se divide en un núcleo agnóstico y una extensión `recipe_agro_details` opcional. El frontend reutiliza el patrón `Object.fromEntries([...empresas].map(...))` que `ModuleLoader.jsx` ya usa para CRM, y reestructura `RecetasView.jsx` en un editor de 2 columnas (acordeón + preview en vivo).

**Tech Stack:** Laravel 11 (PHP), MySQL, PHPUnit + Sanctum (`RefreshDatabase`, fixtures en `Tests\Concerns`), React 18, Tailwind CSS, framer-motion, react-select, lucide-react. El frontend no tiene framework de test automatizado configurado (sin vitest/jest en `package.json`) — las tareas de frontend usan verificación manual en navegador en vez de tests unitarios.

**Spec:** `docs/superpowers/specs/2026-09-09-recetas-generalization-design.md`

## Global Constraints

- Nunca romper `splendidfarms`/`splendidbyporvenir` en producción: todo backfill de datos asume que lo existente pertenece a Splendid Farms (auditado en el spec), y cada migración de aislamiento es retrocompatible (nullable → backfill → NOT NULL).
- Todas las rutas de negocio van dentro de `Route::middleware('auth:sanctum')->group(...)` (`routes/api.php:43`) — el bloque `canes-agro` se agrega al mismo nivel que `splendidbyporvenir` (`routes/api.php:766`), dentro de ese middleware.
- Los nombres de URL/parámetros en español siguen la convención ya usada (`articulos`, `categorias`, `marcas`, `recetas`, `unidades`) — nunca introducir nombres en inglés en rutas nuevas.
- Todo controller que filtra por empresa usa el mismo patrón ya establecido: leer `X-Enterprise-Slug` del header, resolver `Enterprise::where('slug', ...)->first()`, aplicar el scope — nunca inventar un mecanismo de resolución distinto (ni sesión, ni JWT claim).
- Migraciones de columna `enterprise_id` siguen el patrón de 3 pasos ya usado en el proyecto: nullable → backfill → `nullable(false)->change()`.
- Commits frecuentes, un commit por tarea completada, mensajes en el estilo ya usado en el repo (`git log` reciente: prefijo tipo `feat:`/`fix:`/`docs:`, cuerpo en español).

---

## File Structure

**Backend — nuevo:**
- `database/migrations/2026_09_09_140000_create_enterprise_product_category_table.php`
- `database/migrations/2026_09_09_140100_create_enterprise_brand_table.php`
- `database/migrations/2026_09_09_140200_create_enterprise_unit_of_measure_table.php`
- `database/migrations/2026_09_09_140300_add_enterprise_id_to_recipes_and_items.php`
- `database/migrations/2026_09_09_150000_create_recipe_agro_details_table.php`
- `database/migrations/2026_09_09_150100_migrate_agro_fields_into_recipe_agro_details.php`
- `database/migrations/2026_09_09_160000_create_recipe_versions_table.php`
- `database/migrations/2026_09_09_160100_seed_recipe_approval_process.php`
- `app/Models/RecipeAgroDetail.php`
- `app/Models/RecipeVersion.php`
- `app/Services/RecipeVersioningService.php`
- `app/Services/RecipeApprovalService.php`
- `database/seeders/CanesAgroInventoryModuleSeeder.php`
- `tests/Concerns/CreatesCanesAgroFixtures.php`
- `tests/Feature/SplendidFarms/Inventory/ProductCategoryEnterpriseScopeTest.php`
- `tests/Feature/SplendidFarms/Inventory/BrandEnterpriseScopeTest.php`
- `tests/Feature/SplendidFarms/Inventory/UnitOfMeasureEnterpriseScopeTest.php`
- `tests/Feature/SplendidFarms/Inventory/RecipeEnterpriseScopeTest.php`
- `tests/Feature/SplendidFarms/Inventory/RecipeAgroDetailsTest.php`
- `tests/Feature/CanesAgro/Inventory/RecetasProvisioningTest.php`
- `tests/Feature/SplendidFarms/Inventory/RecipeVersioningTest.php`
- `tests/Feature/SplendidFarms/Inventory/RecipeApprovalTest.php`
- `tests/Feature/SplendidFarms/Inventory/RecipeUsageTraceabilityTest.php`

**Backend — modificado:**
- `app/Models/ProductCategory.php`, `app/Models/Brand.php`, `app/Models/UnitOfMeasure.php` — relación `enterprises()` + `scopeForEnterprise()`
- `app/Models/Recipe.php`, `app/Models/RecipeItem.php` — `scopeForEnterprise()`, relación `agroDetails()`, quitar `cultivo()`/`variedad()` directas (se mueven a través de `agroDetails`)
- `app/Http/Controllers/Api/SplendidFarms/Inventory/ProductCategoryController.php`, `BrandController.php`, `UnitOfMeasureController.php` — filtro por empresa en `index`/`store`
- `app/Http/Controllers/Api/SplendidFarms/Inventory/RecipeController.php` — filtro por empresa, payload anidado `agro_details`, versionado, aprobación, trazabilidad
- `app/Http/Controllers/Api/PendingApprovalController.php` — sumar recetas pendientes al inbox
- `routes/api.php` — bloque `canes-agro` (Inventario/Catálogos únicamente)

**Frontend — nuevo** (`sentinel-front/src/views/splendidfarms/inventory/catalogos/recetas/`):
- `constants.js`
- `components/RecipeCard.jsx`, `components/RecipeGroupedGrid.jsx`, `components/RecipeFilters.jsx`
- `editor/RecipeEditorView.jsx`, `editor/RecipeLivePreview.jsx`
- `editor/sections/GeneralSection.jsx`, `OutputProductSection.jsx`, `ItemsSection.jsx`, `GroupsSection.jsx`, `AgroDetailsSection.jsx`
- `editor/hooks/useRecipeEditorState.js`
- `detail/RecipeDetailModal.jsx`, `detail/tabs/OverviewTab.jsx`, `ItemsTab.jsx`, `HistoryTab.jsx`, `UsageTab.jsx`
- `compare/RecipeCompareModal.jsx`

**Frontend — modificado:**
- `RecetasView.jsx` (queda como contenedor delgado)
- `src/hooks/splendidfarms/inventory/catalogos/useRecipes.js` — endpoints nuevos (versions, submit/approve/reject, uso)
- `src/components/workspace/ModuleLoader.jsx` — entradas `canes-agro/inventario/catalogos/*`

---

## Fase 1 — Aislamiento por empresa

### Task 1: Aislar Categorías de producto por empresa

**Files:**
- Create: `database/migrations/2026_09_09_140000_create_enterprise_product_category_table.php`
- Modify: `app/Models/ProductCategory.php`
- Modify: `app/Http/Controllers/Api/SplendidFarms/Inventory/ProductCategoryController.php:15-46,70-111`
- Test: `tests/Feature/SplendidFarms/Inventory/ProductCategoryEnterpriseScopeTest.php`

**Interfaces:**
- Consumes: `Enterprise` model (ya existe, `slug` column).
- Produces: `ProductCategory::enterprises(): BelongsToMany`, `ProductCategory::scopeForEnterprise(int $enterpriseId)` — mismo nombre/firma que `Product::scopeForEnterprise()` (`app/Models/Product.php:122`), para que las tareas siguientes lo reconozcan.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php
// tests/Feature/SplendidFarms/Inventory/ProductCategoryEnterpriseScopeTest.php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Enterprise;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCategoryEnterpriseScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_solo_devuelve_categorias_de_la_empresa_del_header(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $splendidFarms = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true]);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true]);

        $sfCategory = ProductCategory::create(['code' => 'CAT-SF', 'name' => 'Empaque', 'is_active' => true]);
        $sfCategory->enterprises()->attach($splendidFarms->id);

        $caCategory = ProductCategory::create(['code' => 'CAT-CA', 'name' => 'Fertilizantes', 'is_active' => true]);
        $caCategory->enterprises()->attach($canesAgro->id);

        $response = $this->getJson('/api/splendidfarms/inventario/catalogos/categorias', [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Empaque'));
        $this->assertFalse($names->contains('Fertilizantes'));
    }

    public function test_store_vincula_la_categoria_nueva_a_la_empresa_del_header(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true]);

        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/categorias', [
            'name' => 'Agroquímicos',
        ], ['X-Enterprise-Slug' => 'canes-agro']);

        $response->assertCreated();
        $categoryId = $response->json('data.id');

        $this->assertDatabaseHas('enterprise_product_category', [
            'enterprise_id' => $canesAgro->id,
            'product_category_id' => $categoryId,
        ]);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php artisan test --filter=ProductCategoryEnterpriseScopeTest`
Expected: FAIL — `enterprises` relation no existe en `ProductCategory` / tabla `enterprise_product_category` no existe.

- [ ] **Step 3: Crear la migración del pivote**

```php
<?php
// database/migrations/2026_09_09_140000_create_enterprise_product_category_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_product_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['enterprise_id', 'product_category_id']);
        });

        // Mismo criterio que enterprise_product (2026_04_09_002324): todo lo
        // existente hoy se vincula a Splendid Farms.
        $splendidFarms = DB::table('enterprises')->where('slug', 'splendidfarms')->first();
        if ($splendidFarms) {
            $categoryIds = DB::table('product_categories')->whereNull('deleted_at')->pluck('id');
            $records = $categoryIds->map(fn ($id) => [
                'enterprise_id' => $splendidFarms->id,
                'product_category_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            if (! empty($records)) {
                DB::table('enterprise_product_category')->insert($records);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_product_category');
    }
};
```

- [ ] **Step 4: Agregar la relación y el scope a `ProductCategory`**

En `app/Models/ProductCategory.php`, agregar el import `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` junto a los demás imports, y estos dos métodos después de `products()` (línea 64):

```php
    /**
     * Empresas que usan esta categoría
     */
    public function enterprises(): BelongsToMany
    {
        return $this->belongsToMany(Enterprise::class, 'enterprise_product_category')
            ->withTimestamps();
    }

    /**
     * Scope para filtrar por empresa
     */
    public function scopeForEnterprise($query, int $enterpriseId)
    {
        return $query->whereHas('enterprises', function ($q) use ($enterpriseId) {
            $q->where('enterprises.id', $enterpriseId);
        });
    }
```

- [ ] **Step 5: Filtrar por empresa en el controller**

En `app/Http/Controllers/Api/SplendidFarms/Inventory/ProductCategoryController.php`, agregar `use App\Models\Enterprise;` al bloque de imports. En `index()` (línea 17), justo después de `->withCount('products');`:

```php
        // Filtrar por empresa si se envía el header
        $enterpriseSlug = $request->header('X-Enterprise-Slug');
        if ($enterpriseSlug) {
            $enterprise = Enterprise::where('slug', $enterpriseSlug)->first();
            if ($enterprise) {
                $query->forEnterprise($enterprise->id);
            }
        }
```

En `store()` (línea 70), después de `$category = ProductCategory::create($validated);` (línea 102):

```php
        // Vincular la categoría a la empresa actual
        $enterpriseSlug = $request->header('X-Enterprise-Slug');
        if ($enterpriseSlug) {
            $enterprise = Enterprise::where('slug', $enterpriseSlug)->first();
            if ($enterprise) {
                $category->enterprises()->syncWithoutDetaching([$enterprise->id]);
            }
        }
```

- [ ] **Step 6: Correr el test y confirmar que pasa**

Run: `php artisan test --filter=ProductCategoryEnterpriseScopeTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_09_140000_create_enterprise_product_category_table.php \
        app/Models/ProductCategory.php \
        app/Http/Controllers/Api/SplendidFarms/Inventory/ProductCategoryController.php \
        tests/Feature/SplendidFarms/Inventory/ProductCategoryEnterpriseScopeTest.php
git commit -m "feat: aislar categorías de producto por empresa (enterprise_product_category)"
```

---

### Task 2: Aislar Marcas por empresa

Mismo patrón que Task 1, aplicado a `Brand`.

**Files:**
- Create: `database/migrations/2026_09_09_140100_create_enterprise_brand_table.php`
- Modify: `app/Models/Brand.php`
- Modify: `app/Http/Controllers/Api/SplendidFarms/Inventory/BrandController.php:12-34,46-75`
- Test: `tests/Feature/SplendidFarms/Inventory/BrandEnterpriseScopeTest.php`

**Interfaces:**
- Consumes: `Enterprise` model.
- Produces: `Brand::enterprises(): BelongsToMany`, `Brand::scopeForEnterprise(int $enterpriseId)`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php
// tests/Feature/SplendidFarms/Inventory/BrandEnterpriseScopeTest.php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Brand;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrandEnterpriseScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_solo_devuelve_marcas_de_la_empresa_del_header(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $splendidFarms = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true]);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true]);

        $sfBrand = Brand::create(['code' => 'MRC-SF', 'name' => 'CajaMax']);
        $sfBrand->enterprises()->attach($splendidFarms->id);

        $caBrand = Brand::create(['code' => 'MRC-CA', 'name' => 'AgroQuim']);
        $caBrand->enterprises()->attach($canesAgro->id);

        $response = $this->getJson('/api/splendidfarms/inventario/catalogos/marcas', [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('CajaMax'));
        $this->assertFalse($names->contains('AgroQuim'));
    }

    public function test_store_vincula_la_marca_nueva_a_la_empresa_del_header(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true]);

        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/marcas', [
            'name' => 'Nutrisol',
        ], ['X-Enterprise-Slug' => 'canes-agro']);

        $response->assertCreated();
        $this->assertDatabaseHas('enterprise_brand', [
            'enterprise_id' => $canesAgro->id,
            'brand_id' => $response->json('data.id'),
        ]);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php artisan test --filter=BrandEnterpriseScopeTest`
Expected: FAIL.

- [ ] **Step 3: Crear la migración**

```php
<?php
// database/migrations/2026_09_09_140100_create_enterprise_brand_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_brand', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['enterprise_id', 'brand_id']);
        });

        $splendidFarms = DB::table('enterprises')->where('slug', 'splendidfarms')->first();
        if ($splendidFarms) {
            $brandIds = DB::table('brands')->whereNull('deleted_at')->pluck('id');
            $records = $brandIds->map(fn ($id) => [
                'enterprise_id' => $splendidFarms->id,
                'brand_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            if (! empty($records)) {
                DB::table('enterprise_brand')->insert($records);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_brand');
    }
};
```

- [ ] **Step 4: Agregar relación y scope a `Brand`**

En `app/Models/Brand.php`, agregar `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` y, después de `products()` (línea 28):

```php
    public function enterprises(): BelongsToMany
    {
        return $this->belongsToMany(Enterprise::class, 'enterprise_brand')
            ->withTimestamps();
    }

    public function scopeForEnterprise($query, int $enterpriseId)
    {
        return $query->whereHas('enterprises', function ($q) use ($enterpriseId) {
            $q->where('enterprises.id', $enterpriseId);
        });
    }
```

- [ ] **Step 5: Filtrar por empresa en `BrandController`**

Agregar `use App\Models\Enterprise;` al top. En `index()` (línea 12), después de `$query = Brand::withCount('products');`:

```php
        $enterpriseSlug = $request->header('X-Enterprise-Slug');
        if ($enterpriseSlug) {
            $enterprise = Enterprise::where('slug', $enterpriseSlug)->first();
            if ($enterprise) {
                $query->forEnterprise($enterprise->id);
            }
        }
```

En `store()` (línea 46), después de `$brand = Brand::create($validated);` (línea 68):

```php
        $enterpriseSlug = $request->header('X-Enterprise-Slug');
        if ($enterpriseSlug) {
            $enterprise = Enterprise::where('slug', $enterpriseSlug)->first();
            if ($enterprise) {
                $brand->enterprises()->syncWithoutDetaching([$enterprise->id]);
            }
        }
```

- [ ] **Step 6: Correr el test y confirmar que pasa**

Run: `php artisan test --filter=BrandEnterpriseScopeTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_09_140100_create_enterprise_brand_table.php \
        app/Models/Brand.php \
        app/Http/Controllers/Api/SplendidFarms/Inventory/BrandController.php \
        tests/Feature/SplendidFarms/Inventory/BrandEnterpriseScopeTest.php
git commit -m "feat: aislar marcas por empresa (enterprise_brand)"
```

---

### Task 3: Aislar Unidades de medida por empresa

Mismo patrón, aplicado a `UnitOfMeasure` (tabla física `units_of_measure`).

**Files:**
- Create: `database/migrations/2026_09_09_140200_create_enterprise_unit_of_measure_table.php`
- Modify: `app/Models/UnitOfMeasure.php`
- Modify: `app/Http/Controllers/Api/SplendidFarms/Inventory/UnitOfMeasureController.php:15-57,62-106`
- Test: `tests/Feature/SplendidFarms/Inventory/UnitOfMeasureEnterpriseScopeTest.php`

**Interfaces:**
- Consumes: `Enterprise` model.
- Produces: `UnitOfMeasure::enterprises(): BelongsToMany`, `UnitOfMeasure::scopeForEnterprise(int $enterpriseId)`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php
// tests/Feature/SplendidFarms/Inventory/UnitOfMeasureEnterpriseScopeTest.php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Enterprise;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitOfMeasureEnterpriseScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_solo_devuelve_unidades_de_la_empresa_del_header(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $splendidFarms = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true]);
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true]);

        $sfUnit = UnitOfMeasure::create(['code' => 'PZA', 'name' => 'Pieza', 'abbreviation' => 'pza', 'type' => 'unit']);
        $sfUnit->enterprises()->attach($splendidFarms->id);

        $caUnit = UnitOfMeasure::create(['code' => 'LT', 'name' => 'Litro', 'abbreviation' => 'lt', 'type' => 'volume']);
        $caUnit->enterprises()->attach($canesAgro->id);

        $response = $this->getJson('/api/splendidfarms/inventario/catalogos/unidades', [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Pieza'));
        $this->assertFalse($names->contains('Litro'));
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php artisan test --filter=UnitOfMeasureEnterpriseScopeTest`
Expected: FAIL.

- [ ] **Step 3: Crear la migración**

```php
<?php
// database/migrations/2026_09_09_140200_create_enterprise_unit_of_measure_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_unit_of_measure', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_of_measure_id')->constrained('units_of_measure')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['enterprise_id', 'unit_of_measure_id']);
        });

        $splendidFarms = DB::table('enterprises')->where('slug', 'splendidfarms')->first();
        if ($splendidFarms) {
            $unitIds = DB::table('units_of_measure')->whereNull('deleted_at')->pluck('id');
            $records = $unitIds->map(fn ($id) => [
                'enterprise_id' => $splendidFarms->id,
                'unit_of_measure_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            if (! empty($records)) {
                DB::table('enterprise_unit_of_measure')->insert($records);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_unit_of_measure');
    }
};
```

- [ ] **Step 4: Agregar relación y scope a `UnitOfMeasure`**

Agregar `use Illuminate\Database\Eloquent\Relations\BelongsToMany;`, y después de `products()` (línea 57):

```php
    public function enterprises(): BelongsToMany
    {
        return $this->belongsToMany(Enterprise::class, 'enterprise_unit_of_measure', 'unit_of_measure_id', 'enterprise_id')
            ->withTimestamps();
    }

    public function scopeForEnterprise($query, int $enterpriseId)
    {
        return $query->whereHas('enterprises', function ($q) use ($enterpriseId) {
            $q->where('enterprises.id', $enterpriseId);
        });
    }
```

Nota: se pasan explícitamente `foreignPivotKey='unit_of_measure_id'` y `relatedPivotKey='enterprise_id'` porque el nombre de tabla del modelo (`units_of_measure`) no coincide con el nombre singular que Eloquent infiere (`unitOfMeasure`) — sin esto, `belongsToMany` adivina mal la columna.

- [ ] **Step 5: Filtrar por empresa en `UnitOfMeasureController`**

Agregar `use App\Models\Enterprise;`. En `index()` (línea 15), después de `$query = UnitOfMeasure::with(['baseUnit:id,name,abbreviation']);`:

```php
        $enterpriseSlug = $request->header('X-Enterprise-Slug');
        if ($enterpriseSlug) {
            $enterprise = Enterprise::where('slug', $enterpriseSlug)->first();
            if ($enterprise) {
                $query->forEnterprise($enterprise->id);
            }
        }
```

En `store()` (línea 62), después de `$unit = UnitOfMeasure::create($validated);` (línea 98):

```php
        $enterpriseSlug = $request->header('X-Enterprise-Slug');
        if ($enterpriseSlug) {
            $enterprise = Enterprise::where('slug', $enterpriseSlug)->first();
            if ($enterprise) {
                $unit->enterprises()->syncWithoutDetaching([$enterprise->id]);
            }
        }
```

- [ ] **Step 6: Correr el test y confirmar que pasa**

Run: `php artisan test --filter=UnitOfMeasureEnterpriseScopeTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_09_140200_create_enterprise_unit_of_measure_table.php \
        app/Models/UnitOfMeasure.php \
        app/Http/Controllers/Api/SplendidFarms/Inventory/UnitOfMeasureController.php \
        tests/Feature/SplendidFarms/Inventory/UnitOfMeasureEnterpriseScopeTest.php
git commit -m "feat: aislar unidades de medida por empresa (enterprise_unit_of_measure)"
```

---

### Task 4: Aislar Recetas por empresa (columna directa)

A diferencia de las Tasks 1-3, una receta pertenece a **una sola** empresa — columna `enterprise_id`, no pivote.

**Files:**
- Create: `database/migrations/2026_09_09_140300_add_enterprise_id_to_recipes_and_items.php`
- Modify: `app/Models/Recipe.php:12-45`
- Modify: `app/Models/RecipeItem.php:10-38`
- Modify: `app/Http/Controllers/Api/SplendidFarms/Inventory/RecipeController.php:23-79,84-199,230-381,676-714`
- Test: `tests/Feature/SplendidFarms/Inventory/RecipeEnterpriseScopeTest.php`

**Interfaces:**
- Consumes: `Enterprise` model; header `X-Enterprise-Slug` (mismo patrón que Tasks 1-3).
- Produces: `Recipe::scopeForEnterprise(int $enterpriseId)`, columna `recipes.enterprise_id` (NOT NULL tras backfill), columna `recipe_items.enterprise_id` (NOT NULL tras backfill, denormalizada del padre para que `recipe_items` también pueda auditarse/consultarse sola).

- [ ] **Step 1: Escribir el test que falla**

```php
<?php
// tests/Feature/SplendidFarms/Inventory/RecipeEnterpriseScopeTest.php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Enterprise;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRecipeFixtures;
use Tests\TestCase;

class RecipeEnterpriseScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRecipeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecipeFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_index_solo_devuelve_recetas_de_la_empresa_del_header(): void
    {
        $canesAgro = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true]);

        $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(['name' => 'Caja Elote Premium 20lb']),
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(['name' => 'Mezcla Foliar NPK 20-20-20']),
            ['X-Enterprise-Slug' => 'canes-agro']);

        $response = $this->getJson('/api/splendidfarms/inventario/catalogos/recetas', [
            'X-Enterprise-Slug' => 'splendidfarms',
        ]);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Caja Elote Premium 20lb'));
        $this->assertFalse($names->contains('Mezcla Foliar NPK 20-20-20'));
    }

    public function test_recipe_items_hereda_enterprise_id_del_padre(): void
    {
        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload([
                'items' => [['product_id' => $this->productA->id, 'quantity' => 2]],
            ]),
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $recipe = Recipe::find($response->json('data.id'));
        $this->assertSame($this->enterprise->id, $recipe->enterprise_id);
        $this->assertSame($this->enterprise->id, $recipe->items->first()->enterprise_id);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php artisan test --filter=RecipeEnterpriseScopeTest`
Expected: FAIL — columna `enterprise_id` no existe en `recipes`.

- [ ] **Step 3: Crear la migración**

```php
<?php
// database/migrations/2026_09_09_140300_add_enterprise_id_to_recipes_and_items.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paso 1: columna nullable
        Schema::table('recipes', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // Paso 2: backfill a Splendid Farms (única empresa real con recetas hoy)
        $splendidFarms = DB::table('enterprises')->where('slug', 'splendidfarms')->first();
        if ($splendidFarms) {
            DB::table('recipes')->whereNull('enterprise_id')->update(['enterprise_id' => $splendidFarms->id]);

            DB::statement('
                UPDATE recipe_items ri
                INNER JOIN recipes r ON r.id = ri.recipe_id
                SET ri.enterprise_id = r.enterprise_id
                WHERE ri.enterprise_id IS NULL
            ');
        }

        // Paso 3: NOT NULL
        Schema::table('recipes', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable(false)->change();
        });
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enterprise_id');
        });
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enterprise_id');
        });
    }
};
```

- [ ] **Step 4: Agregar `scopeForEnterprise` y `enterprise_id` a `Recipe`/`RecipeItem`**

En `app/Models/Recipe.php`, agregar `'enterprise_id'` como primer elemento de `$fillable` (línea 15), y este scope después de `scopeByStatus()` (línea 116):

```php
    /**
     * Scope para filtrar por empresa
     */
    public function scopeForEnterprise($query, int $enterpriseId)
    {
        return $query->where('enterprise_id', $enterpriseId);
    }
```

En `app/Models/RecipeItem.php`, agregar `'enterprise_id'` a `$fillable` (línea 15).

- [ ] **Step 5: Filtrar por empresa y asignarla al crear, en `RecipeController`**

Agregar `use App\Models\Enterprise;` (ya está importado, línea 6). Agregar un método privado reutilizable al final de la clase, antes del cierre (después de `assertNoDuplicateItems`, línea 742):

```php
    /**
     * Resuelve la empresa activa desde el header X-Enterprise-Slug — mismo
     * mecanismo que ProductController/ProductCategoryController/etc.
     */
    private function resolveEnterprise(Request $request): ?Enterprise
    {
        $slug = $request->header('X-Enterprise-Slug');

        return $slug ? Enterprise::where('slug', $slug)->first() : null;
    }
```

En `index()` (línea 23), después de `->withCount('items');` (línea 31):

```php
        $enterprise = $this->resolveEnterprise($request);
        if ($enterprise) {
            $query->forEnterprise($enterprise->id);
        }
```

En `store()` (línea 84), la creación de la receta pasa a incluir `enterprise_id`. Reemplazar la línea `$recipe = Recipe::create($validated);` (línea 150) por:

```php
            $enterprise = $this->resolveEnterprise($request);
            $recipe = Recipe::create([...$validated, 'enterprise_id' => $enterprise?->id]);
```

Y cada `$recipe->items()->create(...)` dentro de `store()`/`update()` (líneas 153 y 305) necesita el mismo `enterprise_id` heredado — reemplazar ambas ocurrencias de:

```php
                $recipe->items()->create(array_merge($item, [
                    'sort_order' => $item['sort_order'] ?? $index,
                ]));
```

por:

```php
                $recipe->items()->create(array_merge($item, [
                    'sort_order' => $item['sort_order'] ?? $index,
                    'enterprise_id' => $recipe->enterprise_id,
                ]));
```

En `addItem()` (línea 409), antes de `$item = $recipe->items()->create($validated);` (línea 450), agregar:

```php
        $validated['enterprise_id'] = $recipe->enterprise_id;
```

- [ ] **Step 6: Correr el test y confirmar que pasa**

Run: `php artisan test --filter=RecipeEnterpriseScopeTest`
Expected: PASS (2 tests). También correr la suite completa de recetas para confirmar que no se rompió nada:

Run: `php artisan test --filter=RecipeControllerTest`
Expected: PASS (todos los tests existentes siguen verdes).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_09_140300_add_enterprise_id_to_recipes_and_items.php \
        app/Models/Recipe.php app/Models/RecipeItem.php \
        app/Http/Controllers/Api/SplendidFarms/Inventory/RecipeController.php \
        tests/Feature/SplendidFarms/Inventory/RecipeEnterpriseScopeTest.php
git commit -m "feat: aislar recetas y sus items por empresa (enterprise_id)"
```

---

## Fase 2 — Generalización del modelo de Receta

### Task 5: Extraer el detalle agrícola a `recipe_agro_details`

**Files:**
- Create: `database/migrations/2026_09_09_150000_create_recipe_agro_details_table.php`
- Create: `database/migrations/2026_09_09_150100_migrate_agro_fields_into_recipe_agro_details.php`
- Create: `app/Models/RecipeAgroDetail.php`
- Modify: `app/Models/Recipe.php:56-64` (relaciones `cultivo()`/`variedad()`)
- Test: `tests/Feature/SplendidFarms/Inventory/RecipeAgroDetailsTest.php`

**Interfaces:**
- Consumes: `Recipe` (relación `hasOne`).
- Produces: `RecipeAgroDetail` (`recipe_id`, `cultivo_id`, `variedad_id`, `peso_pieza`), `Recipe::agroDetails(): HasOne`. `Recipe::cultivo()`/`Recipe::variedad()` pasan a resolverse **a través de** `agroDetails` (no directo) — Task 6 actualiza el `RecipeController` para usarlas así.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php
// tests/Feature/SplendidFarms/Inventory/RecipeAgroDetailsTest.php

namespace Tests\Feature\SplendidFarms\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRecipeFixtures;
use Tests\TestCase;

class RecipeAgroDetailsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRecipeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecipeFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_receta_de_splendid_farms_migrada_conserva_su_cultivo_en_agro_details(): void
    {
        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(['cultivo_id' => $this->cultivo->id, 'peso_pieza' => 4.01]),
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $recipeId = $response->json('data.id');

        $this->assertDatabaseHas('recipe_agro_details', [
            'recipe_id' => $recipeId,
            'cultivo_id' => $this->cultivo->id,
        ]);
        $this->assertDatabaseMissing('recipes', ['id' => $recipeId, 'cultivo_id' => $this->cultivo->id]);
    }

    public function test_receta_sin_datos_agricolas_no_crea_fila_en_agro_details(): void
    {
        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(),
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $this->assertDatabaseMissing('recipe_agro_details', ['recipe_id' => $response->json('data.id')]);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php artisan test --filter=RecipeAgroDetailsTest`
Expected: FAIL — tabla `recipe_agro_details` no existe.

- [ ] **Step 3: Crear la tabla**

```php
<?php
// database/migrations/2026_09_09_150000_create_recipe_agro_details_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_agro_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('cultivo_id')->nullable()->constrained('cultivos')->nullOnDelete();
            $table->foreignId('variedad_id')->nullable()->constrained('variedades')->nullOnDelete();
            $table->decimal('peso_pieza', 10, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_agro_details');
    }
};
```

- [ ] **Step 4: Migrar los datos existentes y quitar las columnas viejas de `recipes`**

```php
<?php
// database/migrations/2026_09_09_150100_migrate_agro_fields_into_recipe_agro_details.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Solo migrar recetas que de verdad tienen algo agrícola — una
        // receta sin cultivo/variedad/peso_pieza no gana fila en la
        // extensión (es exactamente el caso de Canes Agro).
        $rows = DB::table('recipes')
            ->whereNotNull('cultivo_id')
            ->orWhereNotNull('variedad_id')
            ->orWhereNotNull('peso_pieza')
            ->get(['id', 'cultivo_id', 'variedad_id', 'peso_pieza']);

        $records = $rows->map(fn ($r) => [
            'recipe_id' => $r->id,
            'cultivo_id' => $r->cultivo_id,
            'variedad_id' => $r->variedad_id,
            'peso_pieza' => $r->peso_pieza,
            'created_at' => now(),
            'updated_at' => now(),
        ])->toArray();

        if (! empty($records)) {
            DB::table('recipe_agro_details')->insert($records);
        }

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cultivo_id');
            $table->dropConstrainedForeignId('variedad_id');
            $table->dropColumn('peso_pieza');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->foreignId('cultivo_id')->nullable()->constrained('cultivos')->nullOnDelete();
            $table->foreignId('variedad_id')->nullable()->constrained('variedades')->nullOnDelete();
            $table->decimal('peso_pieza', 10, 4)->nullable();
        });

        DB::table('recipe_agro_details')->orderBy('id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('recipes')->where('id', $row->recipe_id)->update([
                    'cultivo_id' => $row->cultivo_id,
                    'variedad_id' => $row->variedad_id,
                    'peso_pieza' => $row->peso_pieza,
                ]);
            }
        });

        Schema::dropIfExists('recipe_agro_details');
    }
};
```

- [ ] **Step 5: Crear el modelo `RecipeAgroDetail` y la relación en `Recipe`**

```php
<?php
// app/Models/RecipeAgroDetail.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeAgroDetail extends Model
{
    protected $fillable = [
        'recipe_id',
        'cultivo_id',
        'variedad_id',
        'peso_pieza',
    ];

    protected $casts = [
        'peso_pieza' => 'decimal:4',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class);
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class);
    }
}
```

En `app/Models/Recipe.php`, reemplazar los métodos `cultivo()` y `variedad()` (líneas 56-64) por:

```php
    /**
     * Detalle agrícola de la receta (cultivo/variedad/peso por pieza).
     * Nula para recetas que no son de empaque agrícola (ej. Canes Agro).
     */
    public function agroDetails(): HasOne
    {
        return $this->hasOne(RecipeAgroDetail::class);
    }
```

y agregar `use Illuminate\Database\Eloquent\Relations\HasOne;` al bloque de imports (junto a `HasMany`, línea 9). Quitar `'cultivo_id'`, `'variedad_id'`, `'peso_pieza'` de `$fillable` (líneas 22-23, 29) y de `$casts` (línea 39, `peso_pieza`).

- [ ] **Step 6: Correr el test y confirmar que pasa**

Run: `php artisan test --filter=RecipeAgroDetailsTest`
Expected: FAIL todavía — el `RecipeController` sigue mandando `cultivo_id`/`variedad_id`/`peso_pieza` directo a `Recipe::create()`, que ya no los acepta. Eso se corrige en la Task 6; este test se deja en rojo intencionalmente hasta entonces (documentarlo en el mensaje de commit).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_09_150000_create_recipe_agro_details_table.php \
        database/migrations/2026_09_09_150100_migrate_agro_fields_into_recipe_agro_details.php \
        app/Models/RecipeAgroDetail.php app/Models/Recipe.php \
        tests/Feature/SplendidFarms/Inventory/RecipeAgroDetailsTest.php
git commit -m "feat: extraer detalle agrícola de recetas a recipe_agro_details (WIP, RecipeController se actualiza en la siguiente tarea)"
```

---

### Task 6: `RecipeController` — payload anidado `agro_details`, compatible con el legado

**Files:**
- Modify: `app/Http/Controllers/Api/SplendidFarms/Inventory/RecipeController.php:23-79,84-199,201-225,230-381`
- Test: `tests/Feature/SplendidFarms/Inventory/RecipeAgroDetailsTest.php` (se completa)

**Interfaces:**
- Consumes: `Recipe::agroDetails()`, `RecipeAgroDetail` (Task 5).
- Produces: el JSON de receta (`show`/`store`/`update`) incluye `agro_details: {cultivo, variedad, peso_pieza}` cuando existe, `null` cuando no. El payload de entrada acepta **ambas** formas — `agro_details: {...}` (nueva) y los campos planos `cultivo_id`/`variedad_id`/`peso_pieza` (legado, hasta que el frontend de la Fase 5 se actualice) — para no romper `RecetasView.jsx` mientras se despliega esta fase antes que la 5.

- [ ] **Step 1: Ampliar el test que quedó en rojo**

Agregar a `tests/Feature/SplendidFarms/Inventory/RecipeAgroDetailsTest.php`:

```php
    public function test_show_devuelve_agro_details_anidado(): void
    {
        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(['cultivo_id' => $this->cultivo->id]),
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $show = $this->getJson("/api/splendidfarms/inventario/catalogos/recetas/{$response->json('data.id')}");

        $show->assertOk();
        $this->assertSame($this->cultivo->id, $show->json('data.agro_details.cultivo_id'));
    }

    public function test_acepta_agro_details_anidado_en_el_payload(): void
    {
        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload([
                'agro_details' => ['cultivo_id' => $this->cultivo->id, 'peso_pieza' => 4.01],
            ]),
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertOk();
        $this->assertDatabaseHas('recipe_agro_details', [
            'recipe_id' => $response->json('data.id'),
            'cultivo_id' => $this->cultivo->id,
        ]);
    }
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php artisan test --filter=RecipeAgroDetailsTest`
Expected: FAIL — `Recipe::create()` sigue recibiendo `cultivo_id` plano como columna inexistente, y no hay lógica de `agro_details`.

- [ ] **Step 3: Actualizar validación, extracción y persistencia en `store()`**

En la regla de validación (líneas 86-125), quitar `'cultivo_id' => 'nullable|exists:cultivos,id'`, `'peso_pieza' => 'nullable|numeric|min:0'` y `'variedad_id' => 'nullable|exists:variedades,id'` como reglas de nivel raíz, y agregar:

```php
            'agro_details' => 'nullable|array',
            'agro_details.cultivo_id' => 'nullable|exists:cultivos,id',
            'agro_details.variedad_id' => 'nullable|exists:variedades,id',
            'agro_details.peso_pieza' => 'nullable|numeric|min:0',
            // Compatibilidad con el frontend actual (payload plano) hasta la Fase 5
            'cultivo_id' => 'nullable|exists:cultivos,id',
            'variedad_id' => 'nullable|exists:variedades,id',
            'peso_pieza' => 'nullable|numeric|min:0',
```

Agregar este método privado, después de `resolveEnterprise()` (Task 4, Step 5):

```php
    /**
     * Normaliza agro_details: acepta tanto el payload anidado nuevo como los
     * campos planos legado (cultivo_id/variedad_id/peso_pieza a nivel raíz),
     * y los saca de $validated para que Recipe::create()/update() no truene
     * por columnas que ya no existen en `recipes`.
     */
    private function extractAgroDetails(array &$validated): ?array
    {
        $nested = $validated['agro_details'] ?? null;
        unset($validated['agro_details']);

        $legacyCultivo = $validated['cultivo_id'] ?? null;
        $legacyVariedad = $validated['variedad_id'] ?? null;
        $legacyPeso = $validated['peso_pieza'] ?? null;
        unset($validated['cultivo_id'], $validated['variedad_id'], $validated['peso_pieza']);

        $details = $nested ?? [
            'cultivo_id' => $legacyCultivo,
            'variedad_id' => $legacyVariedad,
            'peso_pieza' => $legacyPeso,
        ];

        $hasAnyValue = collect($details)->filter(fn ($v) => $v !== null)->isNotEmpty();

        return $hasAnyValue ? $details : null;
    }
```

En `store()`, después de `$calibres = $validated['calibres'] ?? [];` (línea 146), agregar:

```php
        $agroDetails = $this->extractAgroDetails($validated);
```

Dentro de la transacción, después de crear la receta (línea 150) y antes del loop de `$items`:

```php
            if ($agroDetails) {
                $recipe->agroDetails()->create($agroDetails);
            }
```

- [ ] **Step 4: Mismo tratamiento en `update()`**

Igual que en `store()`: quitar `cultivo_id`/`variedad_id`/`peso_pieza` de las reglas de nivel raíz (líneas 232-272) y agregar las mismas 6 reglas de `agro_details` + legado. Dentro de `update()`, antes de la transacción (línea 292):

```php
        $agroDetails = $this->extractAgroDetails($validated);
```

Dentro de la transacción, después de `$recipe->update($validated);` (línea 293):

```php
            if ($agroDetails) {
                $recipe->agroDetails()->updateOrCreate(['recipe_id' => $recipe->id], $agroDetails);
            } elseif ($agroDetails === null && array_key_exists('agro_details', $request->all())) {
                // Se mandó agro_details explícitamente vacío -> quitar la extensión
                $recipe->agroDetails()->delete();
            }
```

- [ ] **Step 5: Incluir `agroDetails` en las respuestas y exponerla como `agro_details`**

En los 3 bloques `->fresh([...])`/`->load([...])` que listan relaciones (`index()` línea 25, `show()` línea 206, `store()` línea 180, `update()` línea 361), reemplazar `'cultivo:id,nombre'` y `'variedad:id,nombre'` por `'agroDetails.cultivo:id,nombre'` y `'agroDetails.variedad:id,nombre'`. Como el modelo `Recipe` ya no tiene columnas `cultivo_id`/`variedad_id` propias, el JSON expone automáticamente `agro_details` (nombre de relación `agroDetails` serializado a snake_case por Eloquent) con `cultivo`/`variedad`/`peso_pieza` anidados — no se requiere un accessor adicional.

En el filtro por cultivo de `index()` (línea 49, `$query->where('cultivo_id', $request->cultivo_id);`), reemplazar por:

```php
        if ($request->filled('cultivo_id')) {
            $query->whereHas('agroDetails', fn ($q) => $q->where('cultivo_id', $request->cultivo_id));
        }
```

- [ ] **Step 6: Correr el test y confirmar que pasa**

Run: `php artisan test --filter=RecipeAgroDetailsTest`
Expected: PASS (4 tests). Correr también:

Run: `php artisan test --filter=RecipeControllerTest`
Expected: PASS — el payload plano legado sigue funcionando porque `extractAgroDetails()` lo acepta.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/SplendidFarms/Inventory/RecipeController.php \
        tests/Feature/SplendidFarms/Inventory/RecipeAgroDetailsTest.php
git commit -m "feat: RecipeController soporta agro_details anidado, compatible con payload legado"
```

---

## Fase 3 — Alta de Canes Agro

### Task 7: Seeder de Inventario/Catálogos para Canes Agro

**Files:**
- Create: `database/seeders/CanesAgroInventoryModuleSeeder.php`
- Create: `tests/Concerns/CreatesCanesAgroFixtures.php`
- Test: `tests/Feature/CanesAgro/Inventory/RecetasProvisioningTest.php`

**Interfaces:**
- Consumes: `Application`, `Module`, `Submodule` (ya existen, ver `applications.enterprise_id`, `modules.application_id`, `submodules.module_id`, confirmado vía `Schema::getColumnListing()` en esta sesión).
- Produces: `CanesAgroInventoryModuleSeeder::run(): void` — idempotente (`firstOrCreate`), mismo patrón que `InventarioAppSeeder.php`/`InventoryModulesSeeder.php` existentes.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php
// tests/Feature/CanesAgro/Inventory/RecetasProvisioningTest.php

namespace Tests\Feature\CanesAgro\Inventory;

use Database\Seeders\CanesAgroInventoryModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecetasProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_crea_el_arbol_de_inventario_para_canes_agro(): void
    {
        $enterprise = \App\Models\Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true]);

        (new CanesAgroInventoryModuleSeeder())->run();

        $this->assertDatabaseHas('applications', ['enterprise_id' => $enterprise->id, 'slug' => 'inventario']);

        $app = \App\Models\Application::where('enterprise_id', $enterprise->id)->where('slug', 'inventario')->first();
        $catalogos = \App\Models\Module::where('application_id', $app->id)->where('slug', 'catalogos')->first();
        $this->assertNotNull($catalogos);

        $submoduleSlugs = \App\Models\Submodule::where('module_id', $catalogos->id)->pluck('slug');
        $this->assertEqualsCanonicalizing(['articulos', 'categorias', 'marcas', 'recetas'], $submoduleSlugs->toArray());
    }

    public function test_seeder_es_idempotente(): void
    {
        \App\Models\Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true]);

        (new CanesAgroInventoryModuleSeeder())->run();
        (new CanesAgroInventoryModuleSeeder())->run();

        $this->assertDatabaseCount('applications', 1);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php artisan test --filter=RecetasProvisioningTest`
Expected: FAIL — la clase `CanesAgroInventoryModuleSeeder` no existe.

- [ ] **Step 3: Escribir el seeder**

```php
<?php
// database/seeders/CanesAgroInventoryModuleSeeder.php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use Illuminate\Database\Seeder;

/**
 * Da de alta Inventario -> Catálogos (Artículos, Categorías, Marcas,
 * Recetas) para Canes Agro. Solo Catálogos — Canes Agro no necesita
 * Activos Fijos, Operaciones, Compras ni Reportes de Inventario (fuera de
 * alcance, ver docs/superpowers/specs/2026-09-09-recetas-generalization-design.md).
 * Idempotente: correr dos veces no duplica nada.
 */
class CanesAgroInventoryModuleSeeder extends Seeder
{
    public function run(): void
    {
        $enterprise = Enterprise::where('slug', 'canes-agro')->firstOrFail();

        $application = Application::firstOrCreate(
            ['enterprise_id' => $enterprise->id, 'slug' => 'inventario'],
            ['name' => 'Inventario', 'icon' => 'Package', 'path' => '/inventario', 'is_active' => true],
        );

        $catalogos = Module::firstOrCreate(
            ['application_id' => $application->id, 'slug' => 'catalogos'],
            ['name' => 'Catálogos', 'icon' => 'FolderOpen', 'order' => 1, 'is_active' => true],
        );

        $submodules = [
            ['slug' => 'categorias', 'name' => 'Categorías', 'icon' => 'Tag', 'order' => 1],
            ['slug' => 'marcas', 'name' => 'Marcas', 'icon' => 'Award', 'order' => 2],
            ['slug' => 'articulos', 'name' => 'Artículos', 'icon' => 'Package', 'order' => 3],
            ['slug' => 'recetas', 'name' => 'Recetas', 'icon' => 'FlaskConical', 'order' => 4],
        ];

        foreach ($submodules as $sub) {
            Submodule::firstOrCreate(
                ['module_id' => $catalogos->id, 'slug' => $sub['slug']],
                array_merge($sub, ['is_active' => true]),
            );
        }
    }
}
```

- [ ] **Step 4: Correr el test y confirmar que pasa**

Run: `php artisan test --filter=RecetasProvisioningTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add database/seeders/CanesAgroInventoryModuleSeeder.php \
        tests/Feature/CanesAgro/Inventory/RecetasProvisioningTest.php
git commit -m "feat: seeder de Inventario/Catálogos (Artículos/Categorías/Marcas/Recetas) para Canes Agro"
```

- [ ] **Step 6: Correrlo contra la base de datos real** (una sola vez, fuera de tests)

Run: `php artisan tinker --execute="(new Database\Seeders\CanesAgroInventoryModuleSeeder())->run(); echo 'ok';"`
Expected: imprime `ok`, y en la BD real de desarrollo `applications`/`modules`/`submodules` ya tienen las filas de Canes Agro (verificable con la misma consulta tinker usada al inicio de esta sesión).

---

### Task 8: Rutas `canes-agro` en `api.php`

**Files:**
- Modify: `routes/api.php` (nuevo bloque, junto al de `splendidbyporvenir` en la línea 766)

**Interfaces:**
- Consumes: `ProductCategoryController`, `BrandController`, `UnitOfMeasureController`, `ProductController`, `RecipeController` (ya existentes, ya aislados por empresa desde las Tasks 1-4).
- Produces: rutas `GET/POST/PUT/DELETE /api/canes-agro/inventario/catalogos/{categorias,marcas,unidades,articulos,recetas}`.

- [ ] **Step 1: Agregar el bloque de rutas**

En `routes/api.php`, inmediatamente después del cierre del bloque `Route::prefix('splendidbyporvenir')` (busca la línea que cierra ese `Route::prefix('splendidbyporvenir')->group(function () { ... });`, alrededor de la línea 931 antes del cierre de `Route::prefix('admin')` — insertar como hermano, mismo nivel de indentación que `splendidbyporvenir`):

```php
    // Rutas específicas de Canes Agro — solo Inventario/Catálogos.
    // Reutiliza literalmente los mismos controllers que Splendid Farms
    // (ya aislados por enterprise_id / enterprise_product* vía header
    // X-Enterprise-Slug, ver docs/superpowers/specs/2026-09-09-recetas-generalization-design.md).
    Route::prefix('canes-agro')->group(function () {
        Route::prefix('inventario')->group(function () {
            Route::prefix('catalogos')->group(function () {
                Route::get('categorias/tree', [App\Http\Controllers\Api\SplendidFarms\Inventory\ProductCategoryController::class, 'tree']);
                Route::apiResource('categorias', App\Http\Controllers\Api\SplendidFarms\Inventory\ProductCategoryController::class)
                    ->parameters(['categorias' => 'category']);

                Route::get('unidades/convert', [App\Http\Controllers\Api\SplendidFarms\Inventory\UnitOfMeasureController::class, 'convert']);
                Route::apiResource('unidades', App\Http\Controllers\Api\SplendidFarms\Inventory\UnitOfMeasureController::class)
                    ->parameters(['unidades' => 'unit']);

                Route::get('articulos/available-import', [App\Http\Controllers\Api\SplendidFarms\Inventory\ProductController::class, 'availableForImport']);
                Route::get('articulos/{product}/stock', [App\Http\Controllers\Api\SplendidFarms\Inventory\ProductController::class, 'stock']);
                Route::apiResource('articulos', App\Http\Controllers\Api\SplendidFarms\Inventory\ProductController::class)
                    ->parameters(['articulos' => 'product']);

                Route::get('marcas/list', [App\Http\Controllers\Api\SplendidFarms\Inventory\BrandController::class, 'list']);
                Route::apiResource('marcas', App\Http\Controllers\Api\SplendidFarms\Inventory\BrandController::class)
                    ->parameters(['marcas' => 'brand']);

                Route::post('recetas/{recipe}/items', [App\Http\Controllers\Api\SplendidFarms\Inventory\RecipeController::class, 'addItem']);
                Route::put('recetas/{recipe}/items/{item}', [App\Http\Controllers\Api\SplendidFarms\Inventory\RecipeController::class, 'updateItem']);
                Route::delete('recetas/{recipe}/items/{item}', [App\Http\Controllers\Api\SplendidFarms\Inventory\RecipeController::class, 'deleteItem']);
                Route::post('recetas/{recipe}/recalculate-cost', [App\Http\Controllers\Api\SplendidFarms\Inventory\RecipeController::class, 'recalculateCost']);
                Route::apiResource('recetas', App\Http\Controllers\Api\SplendidFarms\Inventory\RecipeController::class)
                    ->parameters(['recetas' => 'recipe']);
            });
        });
    });
```

- [ ] **Step 2: Verificar que las rutas quedan registradas**

Run: `php artisan route:list --path=canes-agro`
Expected: lista con las rutas de `categorias`, `unidades`, `articulos`, `marcas`, `recetas` (y sub-rutas de items/recalculate-cost) bajo el prefijo `canes-agro/inventario/catalogos`.

- [ ] **Step 3: Test de extremo a extremo — crear una receta de Canes Agro y confirmar que no aparece en Splendid Farms**

Agregar a `tests/Feature/CanesAgro/Inventory/RecetasProvisioningTest.php`:

```php
    public function test_flujo_completo_receta_de_canes_agro_no_es_visible_para_splendid_farms(): void
    {
        \App\Models\Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true]);
        Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true]);

        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\User::factory()->create());

        $unit = \App\Models\UnitOfMeasure::create(['code' => 'LT', 'name' => 'Litro', 'abbreviation' => 'lt', 'type' => 'volume']);
        $category = \App\Models\ProductCategory::create(['code' => 'CAT-FERT', 'name' => 'Fertilizantes', 'is_active' => true]);
        $ingredient = \App\Models\Product::create([
            'code' => 'PROD-N', 'name' => 'Nitrógeno líquido', 'category_id' => $category->id,
            'unit_id' => $unit->id, 'cost_price' => 12,
        ]);

        $response = $this->postJson('/api/canes-agro/inventario/catalogos/recetas', [
            'name' => 'Mezcla Foliar NPK 20-20-20',
            'output_quantity' => 200,
            'output_unit_id' => $unit->id,
            'items' => [['product_id' => $ingredient->id, 'quantity' => 50]],
        ], ['X-Enterprise-Slug' => 'canes-agro']);

        $response->assertOk();
        $this->assertNull($response->json('data.agro_details'));

        $sfList = $this->getJson('/api/splendidfarms/inventario/catalogos/recetas', ['X-Enterprise-Slug' => 'splendidfarms']);
        $this->assertFalse(collect($sfList->json('data'))->pluck('name')->contains('Mezcla Foliar NPK 20-20-20'));
    }
```

- [ ] **Step 4: Correr el test y confirmar que pasa**

Run: `php artisan test --filter=RecetasProvisioningTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add routes/api.php tests/Feature/CanesAgro/Inventory/RecetasProvisioningTest.php
git commit -m "feat: rutas de Inventario/Catálogos para Canes Agro (recetas incluidas)"
```

---

### Task 9: `ModuleLoader.jsx` — registrar las vistas de Inventario para Canes Agro

**Files:**
- Modify: `sentinel-front/src/components/workspace/ModuleLoader.jsx` (junto al bloque `splendidbyporvenir/inventario/*`, líneas 288-343)

**Interfaces:**
- Consumes: los mismos componentes ya importados para `splendidfarms`/`splendidbyporvenir` (`ProductCategoriesView`, `BrandsView`, `ProductsView`, `RecetasView`).
- Produces: claves `canes-agro/inventario/catalogos/{categorias,marcas,articulos,recetas}` en `REGISTERED_MODULES`.

- [ ] **Step 1: Agregar las entradas**

Inmediatamente después del bloque `"splendidbyporvenir/inventario/catalogos/recetas"` (línea 328-332), agregar:

```jsx
  "canes-agro/inventario/catalogos/categorias": lazy(() =>
    import("../../views/splendidfarms/inventory/catalogos/categorias").then(
      (module) => ({ default: module.ProductCategoriesView }),
    ),
  ),
  "canes-agro/inventario/catalogos/marcas": lazy(() =>
    import("../../views/splendidfarms/inventory/catalogos/marcas").then(
      (module) => ({ default: module.BrandsView }),
    ),
  ),
  "canes-agro/inventario/catalogos/articulos": lazy(() =>
    import("../../views/splendidfarms/inventory/catalogos/articulos").then(
      (module) => ({ default: module.ProductsView }),
    ),
  ),
  "canes-agro/inventario/catalogos/recetas": lazy(() =>
    import("../../views/splendidfarms/inventory/catalogos/recetas").then(
      (module) => ({ default: module.RecetasView }),
    ),
  ),
```

- [ ] **Step 2: Verificación manual en navegador**

1. Levantar el frontend (`npm run dev` en `sentinel-front`) y el backend.
2. Entrar como un usuario con acceso a Canes Agro, seleccionar esa empresa en `EnterpriseSelector`.
3. Navegar a Inventario → Catálogos → Recetas.
4. Confirmar en la pestaña Network del navegador que la petición a `GET /api/canes-agro/inventario/catalogos/recetas` lleva el header `X-Enterprise-Slug: canes-agro` (ya lo pone `useInventoryContext.js:16-21` automáticamente según `useWorkspace().enterprise.slug` — no requiere cambio de código, solo confirmarlo).
5. Crear una receta de prueba ("Mezcla Foliar NPK 20-20-20") y confirmar que el formulario **no** ofrece ningún campo de cultivo/variedad (la Task 6 hizo esos campos condicionales a que el payload los incluya; en esta fase el frontend viejo `RecetasView.jsx` todavía los muestra siempre — anotar esto como pendiente explícito para la Fase 5, Task 19, que es donde `AgroDetailsSection` se vuelve condicional de verdad).

- [ ] **Step 3: Commit**

```bash
git add sentinel-front/src/components/workspace/ModuleLoader.jsx
git commit -m "feat: registrar vistas de Inventario/Catálogos para Canes Agro en ModuleLoader"
```

---

## Fase 4 — Funcionalidad nueva

### Task 10: Versionado de recetas

**Files:**
- Create: `database/migrations/2026_09_09_160000_create_recipe_versions_table.php`
- Create: `app/Models/RecipeVersion.php`
- Create: `app/Services/RecipeVersioningService.php`
- Modify: `app/Http/Controllers/Api/SplendidFarms/Inventory/RecipeController.php` (método `update()`, + 2 endpoints nuevos)
- Modify: `routes/api.php` (2 líneas nuevas en los 2 bloques de recetas — `splendidfarms` y `canes-agro`)
- Test: `tests/Feature/SplendidFarms/Inventory/RecipeVersioningTest.php`

**Interfaces:**
- Consumes: `Recipe` con sus relaciones ya cargadas (`items`, `agroDetails`, `recipeCalibres.plus`).
- Produces: `RecipeVersioningService::snapshotBeforeUpdate(Recipe $recipe, int $userId): RecipeVersion`, `RecipeVersioningService::restore(Recipe $recipe, int $versionNumber, int $userId): Recipe`. Rutas `GET /recetas/{recipe}/versions`, `POST /recetas/{recipe}/versions/{versionNumber}/restore`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php
// tests/Feature/SplendidFarms/Inventory/RecipeVersioningTest.php

namespace Tests\Feature\SplendidFarms\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRecipeFixtures;
use Tests\TestCase;

class RecipeVersioningTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRecipeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecipeFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_actualizar_una_receta_crea_una_version_con_el_estado_anterior(): void
    {
        $create = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(['name' => 'Caja Elote v1']),
            ['X-Enterprise-Slug' => 'splendidfarms']);
        $recipeId = $create->json('data.id');

        $this->putJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}",
            ['name' => 'Caja Elote v2'], ['X-Enterprise-Slug' => 'splendidfarms']);

        $this->assertDatabaseHas('recipe_versions', ['recipe_id' => $recipeId, 'version_number' => 1]);
        $version = \App\Models\RecipeVersion::where('recipe_id', $recipeId)->first();
        $this->assertSame('Caja Elote v1', $version->snapshot['name']);
    }

    public function test_restaurar_una_version_crea_una_version_nueva_sin_borrar_historia(): void
    {
        $create = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(['name' => 'Caja Elote v1']),
            ['X-Enterprise-Slug' => 'splendidfarms']);
        $recipeId = $create->json('data.id');

        $this->putJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}",
            ['name' => 'Caja Elote v2'], ['X-Enterprise-Slug' => 'splendidfarms']);

        $restore = $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/versions/1/restore",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        $restore->assertOk();
        $this->assertSame('Caja Elote v1', $restore->json('data.name'));
        $this->assertDatabaseCount('recipe_versions', 2);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php artisan test --filter=RecipeVersioningTest`
Expected: FAIL — tabla/modelo/rutas no existen.

- [ ] **Step 3: Migración**

```php
<?php
// database/migrations/2026_09_09_160000_create_recipe_versions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_note')->nullable();
            $table->timestamps();

            $table->unique(['recipe_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_versions');
    }
};
```

- [ ] **Step 4: Modelo `RecipeVersion`**

```php
<?php
// app/Models/RecipeVersion.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeVersion extends Model
{
    protected $fillable = [
        'recipe_id',
        'version_number',
        'snapshot',
        'created_by',
        'change_note',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'version_number' => 'integer',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 5: `RecipeVersioningService`**

```php
<?php
// app/Services/RecipeVersioningService.php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Support\Facades\DB;

class RecipeVersioningService
{
    /**
     * Snapshotea el estado ACTUAL de la receta (antes de aplicar la
     * edición) como la siguiente versión. Se llama antes de $recipe->update().
     */
    public function snapshotBeforeUpdate(Recipe $recipe, ?int $userId, ?string $changeNote = null): RecipeVersion
    {
        $recipe->loadMissing(['items', 'agroDetails', 'recipeCalibres.plus']);

        $nextVersion = (RecipeVersion::where('recipe_id', $recipe->id)->max('version_number') ?? 0) + 1;

        return RecipeVersion::create([
            'recipe_id' => $recipe->id,
            'version_number' => $nextVersion,
            'snapshot' => $recipe->toArray(),
            'created_by' => $userId,
            'change_note' => $changeNote,
        ]);
    }

    /**
     * Restaura una versión anterior aplicándola como una edición NUEVA
     * (nunca sobreescribe/borra historia — restaurar también snapshotea
     * el estado justo antes de restaurar).
     */
    public function restore(Recipe $recipe, int $versionNumber, ?int $userId): Recipe
    {
        $version = RecipeVersion::where('recipe_id', $recipe->id)
            ->where('version_number', $versionNumber)
            ->firstOrFail();

        return DB::transaction(function () use ($recipe, $version, $userId) {
            $this->snapshotBeforeUpdate($recipe, $userId, "Restaurado a la versión {$version->version_number}");

            $snapshot = $version->snapshot;

            $recipe->update([
                'name' => $snapshot['name'],
                'description' => $snapshot['description'] ?? null,
                'recipe_type' => $snapshot['recipe_type'] ?? null,
                'category_id' => $snapshot['category_id'] ?? null,
                'output_quantity' => $snapshot['output_quantity'] ?? 1,
                'output_unit_id' => $snapshot['output_unit_id'] ?? null,
                'status' => $snapshot['status'] ?? 'draft',
                'notes' => $snapshot['notes'] ?? null,
            ]);

            $recipe->items()->delete();
            foreach ($snapshot['items'] ?? [] as $item) {
                $recipe->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_id' => $item['unit_id'] ?? null,
                    'waste_percentage' => $item['waste_percentage'] ?? 0,
                    'cost_per_unit' => $item['cost_per_unit'] ?? 0,
                    'is_optional' => $item['is_optional'] ?? false,
                    'sort_order' => $item['sort_order'] ?? 0,
                    'group_key' => $item['group_key'] ?? null,
                    'is_default' => $item['is_default'] ?? false,
                    'solo_interno' => $item['solo_interno'] ?? false,
                    'calibre_id' => $item['calibre_id'] ?? null,
                    'enterprise_id' => $recipe->enterprise_id,
                ]);
            }

            $recipe->recalculateCost();

            return $recipe->fresh(['items', 'agroDetails', 'category', 'outputProduct', 'outputUnit']);
        });
    }
}
```

- [ ] **Step 6: Wiring en `RecipeController`**

Agregar `use App\Services\RecipeVersioningService;` al top. Cambiar el constructor de la clase (no existe uno hoy — agregar uno) justo después de `class RecipeController extends Controller` (línea 18):

```php
class RecipeController extends Controller
{
    public function __construct(private RecipeVersioningService $versioning) {}

    /**
     * Display a listing of the resource.
     */
```

En `update()` (línea 230), como primera línea del método (antes de `$validated = $request->validate(...)`):

```php
        $this->versioning->snapshotBeforeUpdate($recipe, $request->user()?->id);
```

Agregar 2 métodos nuevos al final de la clase, antes del cierre:

```php
    /**
     * Historial de versiones de la receta.
     */
    public function versions(Recipe $recipe): JsonResponse
    {
        $versions = $recipe->versions()->with('createdBy:id,name')->orderByDesc('version_number')->get();

        return response()->json(['success' => true, 'data' => $versions]);
    }

    /**
     * Restaura una versión anterior como una edición nueva.
     */
    public function restoreVersion(Request $request, Recipe $recipe, int $versionNumber): JsonResponse
    {
        $restored = $this->versioning->restore($recipe, $versionNumber, $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => 'Receta restaurada exitosamente',
            'data' => $restored,
        ]);
    }
```

Agregar la relación al modelo `Recipe` (después de `items()`, línea 100):

```php
    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class)->orderByDesc('version_number');
    }
```

- [ ] **Step 7: Rutas**

En `routes/api.php`, en el bloque de recetas de `splendidfarms` (después de la línea `recalculate-cost`, línea 393) y en el bloque equivalente de `canes-agro` (Task 8):

```php
                Route::get('recetas/{recipe}/versions', [App\Http\Controllers\Api\SplendidFarms\Inventory\RecipeController::class, 'versions']);
                Route::post('recetas/{recipe}/versions/{versionNumber}/restore', [App\Http\Controllers\Api\SplendidFarms\Inventory\RecipeController::class, 'restoreVersion']);
```

- [ ] **Step 8: Correr el test y confirmar que pasa**

Run: `php artisan test --filter=RecipeVersioningTest`
Expected: PASS (2 tests). Correr `php artisan test --filter=Recipe` completo para confirmar que nada se rompió.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_09_09_160000_create_recipe_versions_table.php \
        app/Models/RecipeVersion.php app/Models/Recipe.php \
        app/Services/RecipeVersioningService.php \
        app/Http/Controllers/Api/SplendidFarms/Inventory/RecipeController.php \
        routes/api.php \
        tests/Feature/SplendidFarms/Inventory/RecipeVersioningTest.php
git commit -m "feat: versionado de recetas (snapshot + restaurar sin perder historia)"
```

---

### Task 11: Flujo de aprobación — reusar `ApprovalProcess`

**Files:**
- Create: `database/migrations/2026_09_09_160100_seed_recipe_approval_process.php`
- Create: `app/Services/RecipeApprovalService.php`
- Modify: `app/Http/Controllers/Api/SplendidFarms/Inventory/RecipeController.php` (3 endpoints nuevos)
- Modify: `app/Http/Controllers/Api/PendingApprovalController.php` (sumar recetas al inbox)
- Modify: `routes/api.php` (3 líneas nuevas × 2 bloques)
- Test: `tests/Feature/SplendidFarms/Inventory/RecipeApprovalTest.php`

**Interfaces:**
- Consumes: `ApprovalProcess::canBeApprovedBy(Employee $employee, ?int $enterpriseId)` (ya existe, `app/Models/ApprovalProcess.php:83`), `ApprovalProcess::findByCode(string $code)` (ya existe, línea 147).
- Produces: `RecipeApprovalService::submit(Recipe, int $userId): Recipe`, `::approve(Recipe, User $approver): Recipe`, `::reject(Recipe, User $approver, string $reason): Recipe`. Rutas `POST /recetas/{recipe}/submit-for-approval`, `/approve`, `/reject`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php
// tests/Feature/SplendidFarms/Inventory/RecipeApprovalTest.php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Employee;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRecipeFixtures;
use Tests\TestCase;

class RecipeApprovalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRecipeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecipeFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_enviar_a_revision_cambia_el_estado_a_pending_approval(): void
    {
        $create = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(), ['X-Enterprise-Slug' => 'splendidfarms']);
        $recipeId = $create->json('data.id');

        $response = $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/submit-for-approval",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertOk();
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'status' => 'pending_approval']);
    }

    public function test_aprobar_una_receta_pendiente_la_marca_activa(): void
    {
        $position = Position::create(['name' => 'Gerente de Inventario', 'hierarchy_level' => 2, 'can_approve' => true]);
        $approverEmployee = Employee::factory()->create(['position_id' => $position->id]);
        $approver = $approverEmployee->user ?? \App\Models\User::factory()->create();
        $approverEmployee->update(['user_id' => $approver->id]);

        $process = \App\Models\ApprovalProcess::create([
            'code' => 'recipe_approval', 'name' => 'Aprobación de recetas', 'module' => 'inventario',
            'entity_type' => 'Recipe', 'requires_approval' => true, 'is_active' => true,
        ]);
        \App\Models\ApprovalFlowStep::create([
            'approval_process_id' => $process->id, 'step_order' => 1,
            'approver_type' => 'hierarchy_level', 'min_hierarchy_level' => 3,
            'approval_scope' => 'enterprise', 'can_approve' => true, 'can_reject' => true, 'is_active' => true,
        ]);

        $create = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(), ['X-Enterprise-Slug' => 'splendidfarms']);
        $recipeId = $create->json('data.id');
        $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/submit-for-approval",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        Sanctum::actingAs($approver);
        $response = $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/approve",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertOk();
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'status' => 'active']);
    }
}
```

Nota para quien implemente esta tarea: revisar `database/factories/EmployeeFactory.php` y el modelo `Employee` antes de escribir el fixture del segundo test — el plan asume `Employee` tiene `position_id` y `user_id` fillable/factory-able porque `ApprovalFlowStep::matchesEmployee()` (`app/Models/ApprovalFlowStep.php:62`) los requiere, pero no se leyó ese factory en esta sesión; ajustar el test si los nombres reales difieren.

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php artisan test --filter=RecipeApprovalTest`
Expected: FAIL — endpoints no existen.

- [ ] **Step 3: Migración que registra el proceso base (sin pasos — los pasos se configuran desde el admin panel, igual que los demás procesos)**

```php
<?php
// database/migrations/2026_09_09_160100_seed_recipe_approval_process.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('approval_processes')->insertOrIgnore([
            'code' => 'recipe_approval',
            'name' => 'Aprobación de recetas',
            'description' => 'Aprobación de recetas (BOM) antes de pasar a estado activo',
            'module' => 'inventario',
            'entity_type' => 'Recipe',
            'requires_approval' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('approval_processes')->where('code', 'recipe_approval')->delete();
    }
};
```

- [ ] **Step 4: `RecipeApprovalService`**

```php
<?php
// app/Services/RecipeApprovalService.php

namespace App\Services;

use App\Models\ApprovalProcess;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RecipeApprovalService
{
    public function submit(Recipe $recipe): Recipe
    {
        if ($recipe->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Solo una receta en borrador puede enviarse a revisión.']]);
        }

        $recipe->update(['status' => 'pending_approval']);

        return $recipe->fresh();
    }

    public function approve(Recipe $recipe, User $approver): Recipe
    {
        $this->assertCanDecide($recipe, $approver);

        $recipe->update(['status' => 'active']);

        return $recipe->fresh();
    }

    public function reject(Recipe $recipe, User $approver, string $reason): Recipe
    {
        $this->assertCanDecide($recipe, $approver);

        $recipe->update(['status' => 'draft', 'notes' => trim(($recipe->notes ?? '')."\n[Rechazada] {$reason}")]);

        return $recipe->fresh();
    }

    private function assertCanDecide(Recipe $recipe, User $approver): void
    {
        if ($recipe->status !== 'pending_approval') {
            throw ValidationException::withMessages(['status' => ['La receta no está pendiente de aprobación.']]);
        }

        $process = ApprovalProcess::findByCode('recipe_approval');
        $employee = $approver->employee;

        if (! $process || ! $employee || ! $process->canBeApprovedBy($employee, $recipe->enterprise_id)) {
            throw ValidationException::withMessages(['approval' => ['No tienes permiso para aprobar esta receta.']]);
        }
    }
}
```

- [ ] **Step 5: Wiring en `RecipeController`**

Agregar `use App\Services\RecipeApprovalService;` y ampliar el constructor de la Task 10:

```php
    public function __construct(
        private RecipeVersioningService $versioning,
        private RecipeApprovalService $approval,
    ) {}
```

Agregar 3 métodos, junto a `versions()`/`restoreVersion()`:

```php
    public function submitForApproval(Recipe $recipe): JsonResponse
    {
        $recipe = $this->approval->submit($recipe);

        return response()->json(['success' => true, 'message' => 'Receta enviada a revisión', 'data' => $recipe]);
    }

    public function approve(Request $request, Recipe $recipe): JsonResponse
    {
        $recipe = $this->approval->approve($recipe, $request->user());

        return response()->json(['success' => true, 'message' => 'Receta aprobada', 'data' => $recipe]);
    }

    public function reject(Request $request, Recipe $recipe): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);
        $recipe = $this->approval->reject($recipe, $request->user(), $validated['reason']);

        return response()->json(['success' => true, 'message' => 'Receta rechazada', 'data' => $recipe]);
    }
```

También agregar `'pending_approval'` a la regla `'status' => 'nullable|in:draft,active,inactive,archived'` en `store()`/`update()` (líneas 97 y 244) → `'in:draft,pending_approval,active,inactive,archived'`.

- [ ] **Step 6: Sumar al inbox de `PendingApprovalController`**

Agregar un método privado `getRecipeApprovalProcessEntry()` a `PendingApprovalController` (mismo estilo que `getInventoryApprovalProcess()` referenciado en la línea 59 del archivo ya leído) y sumarlo en `summary()` junto a los demás `$processes[]`/`$totalPending +=` — el código exacto depende del método `getInventoryApprovalProcess()` completo, que no se leyó íntegro en esta sesión; quien implemente esta tarea debe abrir `app/Http/Controllers/Api/PendingApprovalController.php` completo, copiar el patrón de `getInventoryApprovalProcess()` reemplazando `ApprovalProcess::INVENTORY_MOVEMENTS` por el código literal `'recipe_approval'` y la cuenta por `Recipe::where('status', 'pending_approval')->count()` (respetando el `enterprise_id` de cada empleado si `getInventoryApprovalProcess()` ya lo hace así).

- [ ] **Step 7: Rutas**

En ambos bloques de recetas (`splendidfarms` y `canes-agro`):

```php
                Route::post('recetas/{recipe}/submit-for-approval', [App\Http\Controllers\Api\SplendidFarms\Inventory\RecipeController::class, 'submitForApproval']);
                Route::post('recetas/{recipe}/approve', [App\Http\Controllers\Api\SplendidFarms\Inventory\RecipeController::class, 'approve']);
                Route::post('recetas/{recipe}/reject', [App\Http\Controllers\Api\SplendidFarms\Inventory\RecipeController::class, 'reject']);
```

- [ ] **Step 8: Correr el test y confirmar que pasa**

Run: `php artisan test --filter=RecipeApprovalTest`
Expected: PASS (2 tests) — ajustando el fixture de `Employee` según lo que exista realmente (ver nota del Step 1).

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_09_09_160100_seed_recipe_approval_process.php \
        app/Services/RecipeApprovalService.php \
        app/Http/Controllers/Api/SplendidFarms/Inventory/RecipeController.php \
        app/Http/Controllers/Api/PendingApprovalController.php \
        routes/api.php \
        tests/Feature/SplendidFarms/Inventory/RecipeApprovalTest.php
git commit -m "feat: flujo de aprobación de recetas reusando el motor ApprovalProcess existente"
```

---

### Task 12: Trazabilidad de uso

**Files:**
- Modify: `app/Http/Controllers/Api/SplendidFarms/Inventory/RecipeController.php` (1 endpoint nuevo)
- Modify: `app/Http/Controllers/Api/SplendidFarms/Inventory/ProductController.php` (1 endpoint nuevo)
- Modify: `routes/api.php`
- Test: `tests/Feature/SplendidFarms/Inventory/RecipeUsageTraceabilityTest.php`

**Interfaces:**
- Consumes: tabla `produccion_empaque` con columna `recipe_id` (ya existe, migración `2026_03_29_040704_add_recipe_id_to_produccion_empaque_table.php`), `RecipeItem::product_id`.
- Produces: `GET /recetas/{recipe}/uso` → `{producciones: [...]}`; `GET /articulos/{product}/usado-en-recetas` → `{recetas: [...]}`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php
// tests/Feature/SplendidFarms/Inventory/RecipeUsageTraceabilityTest.php

namespace Tests\Feature\SplendidFarms\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRecipeFixtures;
use Tests\TestCase;

class RecipeUsageTraceabilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRecipeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecipeFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_uso_de_receta_devuelve_las_producciones_que_la_referencian(): void
    {
        $create = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(), ['X-Enterprise-Slug' => 'splendidfarms']);
        $recipeId = $create->json('data.id');

        DB::table('produccion_empaque')->insert([
            'recipe_id' => $recipeId,
            'enterprise_id' => $this->enterprise->id,
            'fecha' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/uso",
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.producciones'));
    }

    public function test_articulo_usado_en_recetas_devuelve_las_recetas_que_lo_usan(): void
    {
        $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload([
                'items' => [['product_id' => $this->productA->id, 'quantity' => 2]],
            ]), ['X-Enterprise-Slug' => 'splendidfarms']);

        $response = $this->getJson("/api/splendidfarms/inventario/catalogos/articulos/{$this->productA->id}/usado-en-recetas",
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.recetas'));
    }
}
```

Nota: quien implemente esta tarea debe confirmar las columnas reales de `produccion_empaque` (no se leyeron en esta sesión más allá de que tiene `recipe_id`) — ajustar el `insert()` del primer test si `fecha`/`enterprise_id` no son exactamente esos nombres.

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php artisan test --filter=RecipeUsageTraceabilityTest`
Expected: FAIL — endpoints no existen.

- [ ] **Step 3: Endpoint `uso` en `RecipeController`**

```php
    /**
     * Producciones/empaques que han usado esta receta.
     */
    public function uso(Recipe $recipe): JsonResponse
    {
        $producciones = DB::table('produccion_empaque')
            ->where('recipe_id', $recipe->id)
            ->orderByDesc('created_at')
            ->get(['id', 'fecha', 'created_at']);

        return response()->json(['success' => true, 'data' => ['producciones' => $producciones]]);
    }
```

- [ ] **Step 4: Endpoint `usado-en-recetas` en `ProductController`**

```php
    /**
     * Recetas que usan este artículo como ingrediente.
     */
    public function usadoEnRecetas(Product $product): JsonResponse
    {
        $recetas = \App\Models\Recipe::whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->get(['id', 'code', 'name', 'status']);

        return response()->json(['success' => true, 'data' => ['recetas' => $recetas]]);
    }
```

- [ ] **Step 5: Rutas**

En ambos bloques de recetas:

```php
                Route::get('recetas/{recipe}/uso', [App\Http\Controllers\Api\SplendidFarms\Inventory\RecipeController::class, 'uso']);
```

Y en ambos bloques de artículos (junto a la línea `articulos/{product}/stock`):

```php
                Route::get('articulos/{product}/usado-en-recetas', [App\Http\Controllers\Api\SplendidFarms\Inventory\ProductController::class, 'usadoEnRecetas']);
```

- [ ] **Step 6: Correr el test y confirmar que pasa**

Run: `php artisan test --filter=RecipeUsageTraceabilityTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/SplendidFarms/Inventory/RecipeController.php \
        app/Http/Controllers/Api/SplendidFarms/Inventory/ProductController.php \
        routes/api.php \
        tests/Feature/SplendidFarms/Inventory/RecipeUsageTraceabilityTest.php
git commit -m "feat: trazabilidad de uso de recetas (producciones que la usan / recetas que usan un artículo)"
```

---

## Fase 5 — Reestructura del frontend (Propuesta C)

Estas tareas no tienen test automatizado (el frontend no tiene framework de test configurado, ver Tech Stack) — cada una termina en un paso de verificación manual en navegador explícito y reproducible.

### Task 13: Extraer `useRecipes.js` — endpoints nuevos

**Files:**
- Modify: `sentinel-front/src/hooks/splendidfarms/inventory/catalogos/useRecipes.js`

**Interfaces:**
- Produces: `fetchVersions(recipeId)`, `restoreVersion(recipeId, versionNumber)`, `submitForApproval(recipeId)`, `approveRecipe(recipeId)`, `rejectRecipe(recipeId, reason)`, `fetchUsage(recipeId)` — se agregan al objeto retornado por `useRecipes()`, mismo patrón que `recalculateCost` (línea 164-170 del archivo actual).

- [ ] **Step 1: Agregar los métodos nuevos**

Después de `recalculateCost` (línea 170), antes del `return`:

```js
  const fetchVersions = useCallback(async (recipeId) => {
    const data = await fetchAPI(`${API_BASE}/${recipeId}/versions`, { headers: contextHeaders });
    return data.data || [];
  }, []);

  const restoreVersion = useCallback(async (recipeId, versionNumber) => {
    const data = await fetchAPI(`${API_BASE}/${recipeId}/versions/${versionNumber}/restore`, {
      method: "POST",
      headers: contextHeaders,
    });
    return data;
  }, []);

  const submitForApproval = useCallback(async (recipeId) => {
    const data = await fetchAPI(`${API_BASE}/${recipeId}/submit-for-approval`, {
      method: "POST",
      headers: contextHeaders,
    });
    return data;
  }, []);

  const approveRecipe = useCallback(async (recipeId) => {
    const data = await fetchAPI(`${API_BASE}/${recipeId}/approve`, {
      method: "POST",
      headers: contextHeaders,
    });
    return data;
  }, []);

  const rejectRecipe = useCallback(async (recipeId, reason) => {
    const data = await fetchAPI(`${API_BASE}/${recipeId}/reject`, {
      method: "POST",
      headers: contextHeaders,
      body: JSON.stringify({ reason }),
    });
    return data;
  }, []);

  const fetchUsage = useCallback(async (recipeId) => {
    const data = await fetchAPI(`${API_BASE}/${recipeId}/uso`, { headers: contextHeaders });
    return data.data || { producciones: [] };
  }, []);
```

Y en el `return` (línea 172), agregar las 6 claves nuevas.

- [ ] **Step 2: Verificación manual**

Abrir la consola del navegador en la vista de Recetas, ejecutar `window.__testFetchVersions = () => {}` no aplica — en su lugar, confirmar por Network que `GET .../recetas/{id}/versions` responde `200 {success:true, data:[]}` para una receta recién creada (aún sin usarse desde la UI, se conecta en la Task 17).

- [ ] **Step 3: Commit**

```bash
git add sentinel-front/src/hooks/splendidfarms/inventory/catalogos/useRecipes.js
git commit -m "feat: useRecipes expone versionado, aprobación y trazabilidad"
```

---

### Task 14: Extraer `constants.js` y los componentes de lista (`RecipeCard`, `RecipeGroupedGrid`, `RecipeFilters`)

Sin cambio de comportamiento — solo mover código ya escrito a archivos propios, primer paso de la reestructura.

**Files:**
- Create: `sentinel-front/src/views/splendidfarms/inventory/catalogos/recetas/constants.js`
- Create: `sentinel-front/.../recetas/components/RecipeCard.jsx`
- Create: `sentinel-front/.../recetas/components/RecipeGroupedGrid.jsx`
- Create: `sentinel-front/.../recetas/components/RecipeFilters.jsx`
- Modify: `sentinel-front/.../recetas/RecetasView.jsx`

**Interfaces:**
- Produces: `export const STATUS_CONFIG`, `RECIPE_TYPE_CONFIG`, `getReactSelectStyles`, `useIsDark` desde `constants.js`. `export const RecipeCard`, `RecipeGroupedGrid`, `RecipeFilters` — mismas props que hoy tienen inline en `RecetasView.jsx:2763-3016`.

- [ ] **Step 1: Crear `constants.js`**

Mover literalmente `STATUS_CONFIG` (líneas 49-54), `RECIPE_TYPE_CONFIG` (56-85), `getReactSelectStyles` (89-145) y `useIsDark` (148-163) del `RecetasView.jsx` actual a este archivo nuevo, con sus `export const` correspondientes y los imports que usen (`useState`, `useEffect` de React).

- [ ] **Step 2: Crear `components/RecipeCard.jsx`**

Mover literalmente el componente `RecipeCard` (líneas 2763-2902 del archivo actual) a este archivo, con `export const RecipeCard = ({ recipe, onView, onEdit, onDelete, onUseTemplate }) => { ... }` y sus imports (`motion`, iconos usados: `Layers`, `Calculator`, `Package`, `Tag`, `Sprout`, `Leaf`, `Eye`, `Copy`, `Edit`, `Trash2`, y `STATUS_CONFIG`/`RECIPE_TYPE_CONFIG` desde `../constants`, `formatMoney` desde `../../../../../utils/numberFormat`).

- [ ] **Step 3: Crear `components/RecipeGroupedGrid.jsx`**

Mover literalmente `RecipeGroupedGrid` (líneas 2906-3016), importando `RecipeCard` desde `./RecipeCard` y los iconos que usa (`ChevronDown`, `ChevronRight`, `FolderOpen`, `Sprout`).

- [ ] **Step 4: Crear `components/RecipeFilters.jsx`**

Extraer el bloque de filtros hoy inline en `RecetasView` (líneas 3213-3260 del archivo actual: `SearchBar` + los 3 `<select>`) a un componente con esta firma:

```jsx
export const RecipeFilters = ({
  search, onSearchChange,
  statusFilter, onStatusFilterChange,
  typeFilter, onTypeFilterChange,
  cultivoFilter, onCultivoFilterChange,
  cultivos,
}) => {
  // mismo JSX que RecetasView.jsx:3214-3260, usando los props en vez del estado local
};
```

- [ ] **Step 5: Actualizar `RecetasView.jsx`**

Quitar del archivo todo lo que se movió (constantes, `RecipeCard`, `RecipeGroupedGrid`, el JSX de filtros), importar `constants.js` y los 3 componentes nuevos, y reemplazar el bloque de filtros (líneas 3213-3260) por `<RecipeFilters search={search} onSearchChange={setSearch} ... />`. `RecipeFormModal` y `RecipeDetailModal` (y sus subcomponentes) **no se tocan en esta tarea** — se reemplazan en las Tasks 15-20.

- [ ] **Step 6: Verificación manual**

1. `npm run dev`, abrir Inventario → Recetas.
2. Confirmar que el grid, los filtros (buscar, estado, tipo, cultivo) y las acciones de cada tarjeta (ver/editar/eliminar/usar como plantilla) se comportan exactamente igual que antes del refactor.
3. Revisar la consola del navegador: cero warnings nuevos de React (props faltantes, keys, etc.).

- [ ] **Step 7: Commit**

```bash
git add sentinel-front/src/views/splendidfarms/inventory/catalogos/recetas/
git commit -m "refactor: extraer constants, RecipeCard, RecipeGroupedGrid y RecipeFilters de RecetasView.jsx"
```

---

### Task 15: `useRecipeEditorState` — estado del editor extraído

**Files:**
- Create: `sentinel-front/.../recetas/editor/hooks/useRecipeEditorState.js`

**Interfaces:**
- Consumes: nada externo (hook autocontenido).
- Produces: `useRecipeEditorState(recipe, products)` → `{ formData, setFormData, ingredients, addIngredient, removeIngredient, groups, createGroup, deleteGroup, addGroupItem, removeGroupItem, setGroupDefault, agroDetails, setAgroDetails, estimatedTotal, totalItemCount, groupCount, buildPayload }`. `buildPayload()` es la función que hoy vive inline en `handleSubmit` de `RecipeFormModal` (líneas 361-413 del archivo actual) — se vuelve reutilizable para que el guardado en vivo (Task 20) no dependa de un evento de submit de formulario.

- [ ] **Step 1: Escribir el hook**

Extraer literalmente la lógica de estado hoy en `RecipeFormModal` (`sentinel-front/.../recetas/RecetasView.jsx:212-709` del archivo original, antes de la Task 14): `formData`, `ingredients`, `newIngredient`, `groups`, `recipeCalibres`, y las funciones `handleAddIngredient`, `handleRemoveIngredient`, `handleCreateGroup`, `handleDeleteGroup`, `handleAddGroupItem`, `handleRemoveGroupItem`, `handleSetGroupDefault`, `estimatedTotal`, `totalItemCount`, `groupCount` — todas como estaban, pero fuera del componente, en este hook. Agregar `agroDetails` (objeto `{cultivoId, variedadId, pesoPieza}` o `null`) como estado nuevo, reemplazando los campos planos `formData.cultivoId`/`formData.variedadId`/`formData.pesoPieza` de la versión original.

Agregar `buildPayload()`:

```js
  const buildPayload = useCallback(() => {
    const fixedItems = ingredients.map((item) => ({
      productId: item.productId || item.product?.id,
      quantity: Number(item.quantity || 0),
      unitId: item.unitId || item.product?.unit?.id || null,
      wastePercentage: Number(item.wastePercentage || 0),
      costPerUnit: Number(item.costPerUnit || 0),
      isOptional: !!item.isOptional,
      soloInterno: !!item.soloInterno,
    }));

    const groupedItems = Object.entries(groups).flatMap(([groupKey, items]) =>
      items.map((item) => ({
        productId: item.productId || item.product?.id,
        quantity: Number(item.quantity || 0),
        unitId: item.unitId || item.product?.unit?.id || null,
        wastePercentage: Number(item.wastePercentage || 0),
        costPerUnit: Number(item.costPerUnit || 0),
        isOptional: !!item.isOptional,
        groupKey,
        isDefault: !!item.isDefault,
        soloInterno: !!item.soloInterno,
        calibreId: item.calibreId || null,
      })),
    );

    return {
      ...formData,
      outputQuantity: Number(formData.outputQuantity || 1),
      items: [...fixedItems, ...groupedItems],
      agroDetails: agroDetails
        ? {
            cultivoId: agroDetails.cultivoId || null,
            variedadId: agroDetails.variedadId || null,
            pesoPieza: agroDetails.pesoPieza === "" ? null : Number(agroDetails.pesoPieza),
          }
        : null,
    };
  }, [formData, ingredients, groups, agroDetails]);
```

- [ ] **Step 2: Verificación manual**

Este hook no se consume todavía (se conecta en la Task 20) — verificar solo que el archivo compila sin errores de sintaxis: `npm run build` (o el comando de build configurado en `package.json`) termina sin errores relacionados a este archivo.

- [ ] **Step 3: Commit**

```bash
git add sentinel-front/src/views/splendidfarms/inventory/catalogos/recetas/editor/hooks/useRecipeEditorState.js
git commit -m "refactor: extraer useRecipeEditorState del formulario de recetas"
```

---

### Task 16: `GeneralSection` + `OutputProductSection`

**Files:**
- Create: `sentinel-front/.../recetas/editor/sections/GeneralSection.jsx`
- Create: `sentinel-front/.../recetas/editor/sections/OutputProductSection.jsx`

**Interfaces:**
- Consumes: `formData`, `setFormData` de `useRecipeEditorState` (Task 15); `categories`, `units` (ya vienen de `useProductCategories`/`useUnitsOfMeasure`, sin cambio).
- Produces: `<GeneralSection formData={} setFormData={} categories={} />`, `<OutputProductSection formData={} setFormData={} units={} />` — componentes puros, sin estado propio.

- [ ] **Step 1: `GeneralSection.jsx`**

Extraer el bloque "Información general" del formulario original (nombre, categoría, tipo de receta, versión — sin cultivo/variedad, que ahora es de `AgroDetailsSection`), campos correspondientes a las líneas 754-888 del `RecipeFormModal` original, quitando los `<select>` de Cultivo y Variedad (que se mueven a la Task 19):

```jsx
import { formatDecimal } from "../../../../../../utils/numberFormat";

export const RECIPE_TYPE_OPTIONS = {
  campo_agricola: "Campo Agrícola",
  empaque: "Empaque",
  producto_terminado: "Producto Terminado",
  otro: "Otro",
};

export const GeneralSection = ({ formData, setFormData, categories }) => (
  <div className='space-y-4'>
    <div>
      <label className='block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5'>
        Nombre de la receta <span className='text-red-500'>*</span>
      </label>
      <input
        type='text'
        value={formData.name}
        onChange={(e) => setFormData({ ...formData, name: e.target.value })}
        className='w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-transparent text-sm'
        placeholder='Ej: Caja Elote Premium 20lb'
        required
      />
    </div>
    <div className='grid grid-cols-2 md:grid-cols-3 gap-3'>
      <div>
        <label className='block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1'>Categoría</label>
        <select
          value={formData.categoryId}
          onChange={(e) => setFormData({ ...formData, categoryId: e.target.value })}
          className='w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white rounded-lg'
        >
          <option value=''>Sin categoría</option>
          {categories.map((cat) => (
            <option key={cat.id} value={cat.id}>{cat.name}</option>
          ))}
        </select>
      </div>
      <div>
        <label className='block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1'>Tipo de receta</label>
        <select
          value={formData.recipeType}
          onChange={(e) => setFormData({ ...formData, recipeType: e.target.value })}
          className='w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white rounded-lg'
        >
          <option value=''>Sin clasificar</option>
          {Object.entries(RECIPE_TYPE_OPTIONS).map(([value, label]) => (
            <option key={value} value={value}>{label}</option>
          ))}
        </select>
      </div>
      <div>
        <label className='block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1'>Versión</label>
        <input
          type='text'
          value={formData.version}
          onChange={(e) => setFormData({ ...formData, version: e.target.value })}
          className='w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white rounded-lg'
          placeholder='1.0'
        />
      </div>
    </div>
    <div>
      <label className='block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1'>Descripción / Notas</label>
      <textarea
        value={formData.description}
        onChange={(e) => setFormData({ ...formData, description: e.target.value })}
        className='w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white rounded-lg text-sm'
        rows={2}
        placeholder='Descripción de la receta...'
      />
    </div>
  </div>
);
```

- [ ] **Step 2: `OutputProductSection.jsx`**

Extraer el bloque "Producto de salida" (líneas 890-975 del original: cantidad, unidad, peso por pieza) — **quitar el campo "Peso por pieza/caja"** de aquí (se mueve a `AgroDetailsSection` en la Task 19, ya que es un dato de empaque agrícola, no universal):

```jsx
export const OutputProductSection = ({ formData, setFormData, units }) => (
  <div className='space-y-3'>
    <p className='text-xs text-amber-600/80 dark:text-amber-400/70'>
      El artículo terminado se creará automáticamente con el nombre de la receta.
    </p>
    <div className='grid grid-cols-2 gap-3'>
      <div>
        <label className='block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1'>Cantidad de salida</label>
        <input
          type='number' step='0.01' min='0.01'
          value={formData.outputQuantity}
          onChange={(e) => setFormData({ ...formData, outputQuantity: e.target.value })}
          className='w-full px-3 py-2 text-sm border border-amber-200 dark:border-amber-800/50 bg-white dark:bg-gray-800 dark:text-white rounded-lg'
        />
      </div>
      <div>
        <label className='block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1'>Unidad de medida</label>
        <select
          value={formData.outputUnitId}
          onChange={(e) => setFormData({ ...formData, outputUnitId: e.target.value })}
          className='w-full px-3 py-2 text-sm border border-amber-200 dark:border-amber-800/50 bg-white dark:bg-gray-800 dark:text-white rounded-lg'
        >
          <option value=''>Seleccionar unidad...</option>
          {units.map((u) => (
            <option key={u.id} value={u.id}>{u.name} ({u.abbreviation})</option>
          ))}
        </select>
      </div>
    </div>
  </div>
);
```

- [ ] **Step 3: Verificación manual**

Estos dos componentes se conectan en la Task 20 — por ahora, `npm run build` compila sin errores.

- [ ] **Step 4: Commit**

```bash
git add sentinel-front/src/views/splendidfarms/inventory/catalogos/recetas/editor/sections/GeneralSection.jsx \
        sentinel-front/src/views/splendidfarms/inventory/catalogos/recetas/editor/sections/OutputProductSection.jsx
git commit -m "refactor: extraer GeneralSection y OutputProductSection del editor de recetas"
```

---

### Task 17: `ItemsSection`

**Files:**
- Create: `sentinel-front/.../recetas/editor/sections/ItemsSection.jsx`

**Interfaces:**
- Consumes: `ingredients`, `newIngredient`, handlers de `useRecipeEditorState` (Task 15); `products`.
- Produces: `<ItemsSection ingredients={} newIngredient={} onSelectProduct={} onAddIngredient={} onRemoveIngredient={} availableProducts={} />`.

- [ ] **Step 1: Extraer el bloque de artículos**

Mover literalmente el JSX de "Agregar artículo" + lista de artículos agregados (líneas 1011-1320 del `RecipeFormModal` original), incluyendo `formatProductOption`/`productToOption` (importados desde el `RecipeFilters`/utilidades compartidas creadas en la Task 14 — moverlas a un archivo `editor/productOptions.js` si aún no tienen hogar) y el `<Select>` de `react-select`, tal cual, envuelto en el nuevo componente `ItemsSection` que recibe como props lo que hoy son closures internos del modal.

- [ ] **Step 2: Verificación manual**

Se conecta en la Task 20 — por ahora, build limpio.

- [ ] **Step 3: Commit**

```bash
git add sentinel-front/src/views/splendidfarms/inventory/catalogos/recetas/editor/sections/ItemsSection.jsx
git commit -m "refactor: extraer ItemsSection del editor de recetas"
```

---

### Task 18: `GroupsSection`

**Files:**
- Create: `sentinel-front/.../recetas/editor/sections/GroupsSection.jsx`

**Interfaces:**
- Consumes: `groups`, handlers de grupos de `useRecipeEditorState` (Task 15); `products`.
- Produces: `<GroupsSection groups={} onCreateGroup={} onDeleteGroup={} onAddGroupItem={} onRemoveGroupItem={} onSetGroupDefault={} availableGroupProducts={} />`.

- [ ] **Step 1: Extraer el bloque de grupos intercambiables**

Mover literalmente el JSX de "Grupos intercambiables" (líneas 1322-1916+ del original: crear grupo, listar grupos, agregar/quitar alternativas, marcar default), conservando la lógica de auto-grupo "Caja" para pallets tal cual vive hoy — esa lógica es de negocio de empaque agrícola y **no se toca en este plan** (está fuera de alcance, ver spec sección "No-objetivos").

- [ ] **Step 2: Verificación manual**

Se conecta en la Task 20 — build limpio.

- [ ] **Step 3: Commit**

```bash
git add sentinel-front/src/views/splendidfarms/inventory/catalogos/recetas/editor/sections/GroupsSection.jsx
git commit -m "refactor: extraer GroupsSection del editor de recetas"
```

---

### Task 19: `AgroDetailsSection` — condicional de verdad

Esta es la tarea donde el diseño de la Sección 1 (spec) se vuelve real: la extensión agrícola solo se monta cuando aplica.

**Files:**
- Create: `sentinel-front/.../recetas/editor/sections/AgroDetailsSection.jsx`

**Interfaces:**
- Consumes: `agroDetails`, `setAgroDetails` de `useRecipeEditorState`; `cultivos`, `variedades`, `calibresList`, `onLoadVariedades`, `onLoadCalibres` (ya existen, sin cambio de firma respecto al `RecipeFormModal` original).
- Produces: `<AgroDetailsSection agroDetails={} setAgroDetails={} cultivos={} variedades={} ... />` — incluye cultivo, variedad, peso por pieza, y el bloque de calibres/PLUs (líneas 247-336 y demás del original).

- [ ] **Step 1: Extraer cultivo/variedad/peso + calibres/PLUs**

Mover el `<select>` de Cultivo y Variedad (líneas 790-851 del original), el campo "Peso por pieza/caja" (líneas 945-974), y todo el bloque de calibres/PLUs (`recipeCalibres`, líneas 247-336 y su JSX correspondiente en el resto del archivo original) a este componente nuevo.

- [ ] **Step 2: Montaje condicional — dónde se decide**

Este componente en sí no decide si se muestra — eso lo hace `RecipeEditorView` (Task 20) según si la receta activa tiene `agroDetails !== null` **o** si la empresa actual (`useWorkspace().enterprise.slug`) es `splendidfarms`/`splendidbyporvenir` (las 2 empresas agrícolas) — nunca para `canes-agro`. La regla exacta:

```js
const isAgriculturalEnterprise = ["splendidfarms", "splendidbyporvenir"].includes(enterpriseSlug);
const showAgroSection = isAgriculturalEnterprise; // Task 20 la usa así
```

- [ ] **Step 3: Verificación manual**

Se conecta y se verifica de punta a punta en la Task 20.

- [ ] **Step 4: Commit**

```bash
git add sentinel-front/src/views/splendidfarms/inventory/catalogos/recetas/editor/sections/AgroDetailsSection.jsx
git commit -m "refactor: extraer AgroDetailsSection, montaje condicional según empresa"
```

---

### Task 20: `RecipeLivePreview` + `RecipeEditorView` (Propuesta C)

La tarea central de la Fase 5: reemplaza `RecipeFormModal` por el layout de 2 columnas elegido.

**Files:**
- Create: `sentinel-front/.../recetas/editor/RecipeLivePreview.jsx`
- Create: `sentinel-front/.../recetas/editor/RecipeEditorView.jsx`
- Modify: `sentinel-front/.../recetas/RecetasView.jsx`

**Interfaces:**
- Consumes: `useRecipeEditorState` (Task 15), `GeneralSection`/`OutputProductSection`/`ItemsSection`/`GroupsSection`/`AgroDetailsSection` (Tasks 16-19), `RecipeCard` (Task 14, reutilizado por el preview).
- Produces: `<RecipeEditorView recipe={} isOpen={} onClose={} onSave={} categories={} products={} units={} cultivos={} variedades={} calibresList={} onLoadVariedades={} onLoadCalibres={} />` — mismas props públicas que el `RecipeFormModal` original, para que `RecetasView.jsx` cambie solo el nombre del componente que renderiza.

- [ ] **Step 1: `RecipeLivePreview.jsx`**

```jsx
import { motion } from "framer-motion";
import { Package, Calculator } from "lucide-react";
import { formatMoney } from "../../../../../../utils/numberFormat";
import { STATUS_CONFIG } from "../constants";

export const RecipeLivePreview = ({ formData, totalItemCount, estimatedTotal }) => {
  const statusCfg = STATUS_CONFIG[formData.status] || STATUS_CONFIG.draft;

  return (
    <motion.div
      layout
      className='sticky top-4 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden'
    >
      <div className='h-1 bg-amber-500' />
      <div className='p-4'>
        <div className='flex items-start justify-between gap-2 mb-3'>
          <h3 className='font-semibold text-gray-900 dark:text-white text-sm'>
            {formData.name || "Nueva receta"}
          </h3>
          <span className='px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'>
            {statusCfg.label}
          </span>
        </div>
        <div className='grid grid-cols-2 gap-2'>
          <div className='border border-dashed border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2'>
            <div className='font-mono font-semibold text-gray-900 dark:text-white'>{totalItemCount}</div>
            <div className='text-[10px] text-gray-400'>artículos</div>
          </div>
          <div className='border border-dashed border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2'>
            <div className='font-mono font-semibold text-gray-900 dark:text-white'>{formatMoney(estimatedTotal)}</div>
            <div className='text-[10px] text-gray-400'>costo estimado</div>
          </div>
        </div>
        <p className='mt-3 flex items-center gap-1.5 text-[11px] text-green-600 dark:text-green-400'>
          <span className='w-1.5 h-1.5 rounded-full bg-green-500' />
          se actualiza con cada cambio
        </p>
      </div>
    </motion.div>
  );
};
```

- [ ] **Step 2: `RecipeEditorView.jsx` — acordeón + layout de 2 columnas**

```jsx
import { useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { ChevronDown, ChevronRight, X } from "lucide-react";
import { useWorkspace } from "../../../../../../contexts/WorkspaceContext";
import { useAlert } from "../../../../../../contexts/AlertContext";
import { useRecipeEditorState } from "./hooks/useRecipeEditorState";
import { GeneralSection } from "./sections/GeneralSection";
import { OutputProductSection } from "./sections/OutputProductSection";
import { ItemsSection } from "./sections/ItemsSection";
import { GroupsSection } from "./sections/GroupsSection";
import { AgroDetailsSection } from "./sections/AgroDetailsSection";
import { RecipeLivePreview } from "./RecipeLivePreview";

const AGRICULTURAL_ENTERPRISES = ["splendidfarms", "splendidbyporvenir"];

const AccordionRow = ({ title, badge, isOpen, onToggle, children }) => (
  <div className={`border rounded-lg mb-2 overflow-hidden ${isOpen ? "border-amber-300 dark:border-amber-700" : "border-gray-200 dark:border-gray-700"}`}>
    <button
      type='button'
      onClick={onToggle}
      className={`w-full flex items-center justify-between px-4 py-2.5 text-sm font-medium ${isOpen ? "bg-amber-50 dark:bg-amber-900/20" : "bg-white dark:bg-gray-800"}`}
    >
      <span className='flex items-center gap-2 text-gray-800 dark:text-white'>
        {isOpen ? <ChevronDown className='w-4 h-4' /> : <ChevronRight className='w-4 h-4' />}
        {title}
      </span>
      {badge && <span className='text-xs text-gray-400 dark:text-gray-500'>{badge}</span>}
    </button>
    <AnimatePresence initial={false}>
      {isOpen && (
        <motion.div
          initial={{ height: 0, opacity: 0 }}
          animate={{ height: "auto", opacity: 1 }}
          exit={{ height: 0, opacity: 0 }}
          className='overflow-hidden'
        >
          <div className='p-4 border-t border-gray-100 dark:border-gray-700'>{children}</div>
        </motion.div>
      )}
    </AnimatePresence>
  </div>
);

export const RecipeEditorView = ({
  isOpen, onClose, recipe, onSave,
  categories, products, units, cultivos, variedades, calibresList,
  onLoadVariedades, onLoadCalibres,
}) => {
  const { enterprise } = useWorkspace();
  const alert = useAlert();
  const [openSection, setOpenSection] = useState("general");
  const [saving, setSaving] = useState(false);

  const showAgroSection = AGRICULTURAL_ENTERPRISES.includes(enterprise?.slug);

  const {
    formData, setFormData,
    ingredients, newIngredient, setNewIngredient, addIngredient, removeIngredient,
    groups, createGroup, deleteGroup, addGroupItem, removeGroupItem, setGroupDefault,
    agroDetails, setAgroDetails,
    estimatedTotal, totalItemCount, groupCount,
    buildPayload,
  } = useRecipeEditorState(recipe, products);

  if (!isOpen) return null;

  const handleSave = async () => {
    setSaving(true);
    try {
      await onSave(buildPayload());
      onClose();
    } catch (err) {
      alert.error(err.message || "Error al guardar la receta");
    } finally {
      setSaving(false);
    }
  };

  const toggle = (key) => setOpenSection((prev) => (prev === key ? null : key));

  return (
    <div className='fixed inset-0 z-50 bg-white dark:bg-gray-900 overflow-y-auto'>
      <div className='sticky top-0 z-10 flex items-center justify-between px-6 py-4 bg-linear-to-r from-amber-500 to-orange-500 dark:from-amber-700 dark:to-orange-700'>
        <h2 className='text-lg font-bold text-white'>{recipe ? "Editar receta" : "Nueva receta"}</h2>
        <button onClick={onClose} className='p-1.5 bg-white/20 hover:bg-white/30 rounded-lg text-white'>
          <X className='w-5 h-5' />
        </button>
      </div>

      <div className='max-w-5xl mx-auto px-6 py-6 grid grid-cols-1 lg:grid-cols-[1.15fr_0.85fr] gap-6'>
        <div>
          <AccordionRow title='Información general' isOpen={openSection === "general"} onToggle={() => toggle("general")}>
            <GeneralSection formData={formData} setFormData={setFormData} categories={categories} />
          </AccordionRow>
          <AccordionRow title='Producto de salida' isOpen={openSection === "output"} onToggle={() => toggle("output")}>
            <OutputProductSection formData={formData} setFormData={setFormData} units={units} />
          </AccordionRow>
          <AccordionRow title='Artículos' badge={`${ingredients.length} agregados`} isOpen={openSection === "items"} onToggle={() => toggle("items")}>
            <ItemsSection
              ingredients={ingredients} newIngredient={newIngredient} setNewIngredient={setNewIngredient}
              products={products} onAddIngredient={addIngredient} onRemoveIngredient={removeIngredient}
            />
          </AccordionRow>
          <AccordionRow title='Grupos intercambiables' badge={groupCount ? `${groupCount}` : null} isOpen={openSection === "groups"} onToggle={() => toggle("groups")}>
            <GroupsSection
              groups={groups} products={products} formData={formData}
              onCreateGroup={createGroup} onDeleteGroup={deleteGroup}
              onAddGroupItem={addGroupItem} onRemoveGroupItem={removeGroupItem} onSetGroupDefault={setGroupDefault}
            />
          </AccordionRow>
          {showAgroSection && (
            <AccordionRow title='Detalle agrícola' isOpen={openSection === "agro"} onToggle={() => toggle("agro")}>
              <AgroDetailsSection
                agroDetails={agroDetails} setAgroDetails={setAgroDetails}
                cultivos={cultivos} variedades={variedades} calibresList={calibresList}
                onLoadVariedades={onLoadVariedades} onLoadCalibres={onLoadCalibres}
              />
            </AccordionRow>
          )}
        </div>

        <div>
          <RecipeLivePreview formData={formData} totalItemCount={totalItemCount} estimatedTotal={estimatedTotal} />
          <button
            onClick={handleSave}
            disabled={saving || !formData.name}
            className='mt-4 w-full py-2.5 bg-amber-600 hover:bg-amber-700 disabled:opacity-40 text-white text-sm font-medium rounded-lg transition-colors'
          >
            {saving ? "Guardando..." : "Guardar receta"}
          </button>
        </div>
      </div>
    </div>
  );
};
```

- [ ] **Step 3: `RecetasView.jsx` — cambiar el editor**

Reemplazar el render de `<RecipeFormModal .../>` (líneas 3301-3318 del archivo pre-refactor) por `<RecipeEditorView .../>` con las mismas props, y borrar la definición inline de `RecipeFormModal` (ya no se usa — sus piezas viven en `editor/`).

- [ ] **Step 4: Verificación manual — receta de Splendid Farms**

1. Empresa Splendid Farms → Inventario → Recetas → Nueva Receta.
2. Confirmar que aparece la sección "Detalle agrícola" en el acordeón y que **solo una sección está expandida a la vez**.
3. Llenar Información general + 1 artículo, confirmar que `RecipeLivePreview` actualiza el conteo de artículos y el costo estimado **sin guardar**.
4. Guardar, confirmar que la receta aparece en el grid con los datos correctos.

- [ ] **Step 5: Verificación manual — receta de Canes Agro**

1. Cambiar a empresa Canes Agro → Inventario → Recetas → Nueva Receta.
2. Confirmar que **no** aparece la sección "Detalle agrícola" en absoluto (ni colapsada).
3. Crear "Mezcla Foliar NPK 20-20-20" con 2 artículos, guardar, confirmar que se guarda sin error y sin pedir cultivo/variedad.

- [ ] **Step 6: Commit**

```bash
git add sentinel-front/src/views/splendidfarms/inventory/catalogos/recetas/
git commit -m "feat: RecipeEditorView (Propuesta C) reemplaza RecipeFormModal — acordeón + preview en vivo"
```

---

### Task 21: `RecipeDetailModal` — pestañas Historial y Uso + `RecipeCompareModal`

**Files:**
- Create: `sentinel-front/.../recetas/detail/tabs/HistoryTab.jsx`
- Create: `sentinel-front/.../recetas/detail/tabs/UsageTab.jsx`
- Create: `sentinel-front/.../recetas/compare/RecipeCompareModal.jsx`
- Modify: `sentinel-front/.../recetas/detail/RecipeDetailModal.jsx` (extraído de `RecetasView.jsx:1983-2762` del original, siguiendo el mismo criterio de la Task 14 — mover tal cual, agregar 2 pestañas)
- Modify: `sentinel-front/.../recetas/RecetasView.jsx` (botón "Comparar")

**Interfaces:**
- Consumes: `fetchVersions`, `restoreVersion`, `fetchUsage` de `useRecipes` (Task 13).
- Produces: `<HistoryTab recipeId={} />`, `<UsageTab recipeId={} />`, `<RecipeCompareModal isOpen={} onClose={} recipeA={} recipeB={} />`.

- [ ] **Step 1: `HistoryTab.jsx`**

```jsx
import { useEffect, useState } from "react";
import { History, RotateCcw } from "lucide-react";
import { useRecipes } from "../../../../../../hooks/splendidfarms/inventory/catalogos";
import { useAlert } from "../../../../../../contexts/AlertContext";
import { useConfirm } from "../../../../../../contexts/ConfirmContext";

export const HistoryTab = ({ recipeId, onRestored }) => {
  const { fetchVersions, restoreVersion } = useRecipes();
  const [versions, setVersions] = useState([]);
  const [loading, setLoading] = useState(true);
  const alert = useAlert();
  const { confirmAction } = useConfirm();

  useEffect(() => {
    fetchVersions(recipeId).then(setVersions).finally(() => setLoading(false));
  }, [recipeId]);

  const handleRestore = async (versionNumber) => {
    const confirmed = await confirmAction(`¿Restaurar a la versión ${versionNumber}? Se creará una versión nueva con esos datos.`);
    if (!confirmed) return;
    try {
      await restoreVersion(recipeId, versionNumber);
      alert.success("Receta restaurada");
      onRestored?.();
    } catch (err) {
      alert.error(err.message || "Error al restaurar la versión");
    }
  };

  if (loading) return <p className='text-sm text-gray-400 py-6 text-center'>Cargando historial...</p>;
  if (versions.length === 0) return <p className='text-sm text-gray-400 py-6 text-center'>Sin cambios registrados todavía.</p>;

  return (
    <div className='space-y-2'>
      {versions.map((v) => (
        <div key={v.id} className='flex items-center justify-between px-3 py-2.5 border border-gray-200 dark:border-gray-700 rounded-lg'>
          <div className='flex items-center gap-2'>
            <History className='w-4 h-4 text-gray-400' />
            <div>
              <p className='text-sm font-medium text-gray-800 dark:text-white'>Versión {v.version_number}</p>
              <p className='text-xs text-gray-400'>{v.created_by?.name || "Sistema"} · {new Date(v.created_at).toLocaleString()}</p>
              {v.change_note && <p className='text-xs text-gray-500 italic'>{v.change_note}</p>}
            </div>
          </div>
          <button
            onClick={() => handleRestore(v.version_number)}
            className='flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-amber-700 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded-lg'
          >
            <RotateCcw className='w-3.5 h-3.5' /> Restaurar
          </button>
        </div>
      ))}
    </div>
  );
};
```

- [ ] **Step 2: `UsageTab.jsx`**

```jsx
import { useEffect, useState } from "react";
import { Factory } from "lucide-react";
import { useRecipes } from "../../../../../../hooks/splendidfarms/inventory/catalogos";

export const UsageTab = ({ recipeId }) => {
  const { fetchUsage } = useRecipes();
  const [usage, setUsage] = useState({ producciones: [] });
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchUsage(recipeId).then(setUsage).finally(() => setLoading(false));
  }, [recipeId]);

  if (loading) return <p className='text-sm text-gray-400 py-6 text-center'>Cargando trazabilidad...</p>;
  if (usage.producciones.length === 0) {
    return <p className='text-sm text-gray-400 py-6 text-center'>Esta receta no se ha usado en ninguna producción todavía.</p>;
  }

  return (
    <div className='space-y-2'>
      {usage.producciones.map((p) => (
        <div key={p.id} className='flex items-center gap-2 px-3 py-2.5 border border-gray-200 dark:border-gray-700 rounded-lg text-sm'>
          <Factory className='w-4 h-4 text-gray-400' />
          <span className='text-gray-800 dark:text-white'>Producción #{p.id}</span>
          <span className='text-gray-400 ml-auto'>{p.fecha}</span>
        </div>
      ))}
    </div>
  );
};
```

- [ ] **Step 3: `RecipeCompareModal.jsx`**

```jsx
import { motion } from "framer-motion";
import { X } from "lucide-react";
import { formatMoney } from "../../../../../../utils/numberFormat";

const diffKey = (a, b) => (a !== b ? "bg-amber-50 dark:bg-amber-900/20" : "");

export const RecipeCompareModal = ({ isOpen, onClose, recipeA, recipeB }) => {
  if (!isOpen || !recipeA || !recipeB) return null;

  const itemsA = recipeA.items || [];
  const itemsB = recipeB.items || [];
  const allProductIds = [...new Set([...itemsA, ...itemsB].map((i) => i.product?.id))];

  return (
    <div className='fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm'>
      <motion.div
        initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }}
        className='bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto'
      >
        <div className='flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800'>
          <h2 className='text-lg font-bold text-gray-900 dark:text-white'>Comparar recetas</h2>
          <button onClick={onClose} className='p-1.5 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg'>
            <X className='w-5 h-5' />
          </button>
        </div>
        <div className='grid grid-cols-2 gap-px bg-gray-100 dark:bg-gray-800'>
          {[recipeA, recipeB].map((r) => (
            <div key={r.id} className='bg-white dark:bg-gray-900 p-4'>
              <h3 className='font-semibold text-gray-900 dark:text-white'>{r.name}</h3>
              <p className='text-xs text-gray-400'>{r.code} · v{r.version}</p>
              <p className={`mt-2 text-sm font-semibold ${diffKey(recipeA.estimated_cost, recipeB.estimated_cost)}`}>
                {formatMoney(parseFloat(r.estimated_cost || 0))}
              </p>
            </div>
          ))}
        </div>
        <table className='w-full text-sm'>
          <thead>
            <tr className='text-left text-xs text-gray-400 uppercase'>
              <th className='px-4 py-2'>Artículo</th>
              <th className='px-4 py-2'>{recipeA.name}</th>
              <th className='px-4 py-2'>{recipeB.name}</th>
            </tr>
          </thead>
          <tbody>
            {allProductIds.map((productId) => {
              const itemA = itemsA.find((i) => i.product?.id === productId);
              const itemB = itemsB.find((i) => i.product?.id === productId);
              const label = itemA?.product?.name || itemB?.product?.name;
              return (
                <tr key={productId} className={`border-t border-gray-100 dark:border-gray-800 ${diffKey(itemA?.quantity, itemB?.quantity)}`}>
                  <td className='px-4 py-2 text-gray-800 dark:text-white'>{label}</td>
                  <td className='px-4 py-2 text-gray-600 dark:text-gray-300'>{itemA ? itemA.quantity : "—"}</td>
                  <td className='px-4 py-2 text-gray-600 dark:text-gray-300'>{itemB ? itemB.quantity : "—"}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </motion.div>
    </div>
  );
};
```

- [ ] **Step 4: Conectar en `RecipeDetailModal.jsx` y `RecetasView.jsx`**

En `RecipeDetailModal.jsx` (extraído en esta misma tarea desde el `RecetasView.jsx` original, líneas 1983-2762, siguiendo el mismo criterio de "mover tal cual" de la Task 14), agregar 2 pestañas nuevas junto a las existentes (Overview/Items), renderizando `<HistoryTab recipeId={recipe.id} onRestored={onRecalculate} />` y `<UsageTab recipeId={recipe.id} />`.

En `RecetasView.jsx`, agregar estado `compareSelection` (array de hasta 2 IDs), un checkbox de selección en `RecipeCard` (prop nueva `selectable`/`selected`/`onToggleSelect`, opcional — no rompe el uso existente si no se pasa), un botón "Comparar (2)" que abre `RecipeCompareModal` con las 2 recetas completas (`fetchRecipe` para cada una antes de abrir).

- [ ] **Step 5: Verificación manual**

1. Abrir el detalle de una receta con al menos 2 versiones (editarla una vez después de crearla) → pestaña Historial muestra ambas, "Restaurar" en la v1 funciona y refresca el detalle.
2. Abrir el detalle de una receta usada en una producción de prueba → pestaña Uso la lista.
3. Seleccionar 2 recetas en el grid, comparar → tabla lado a lado con diferencias resaltadas en artículos con cantidad distinta.

- [ ] **Step 6: Commit**

```bash
git add sentinel-front/src/views/splendidfarms/inventory/catalogos/recetas/
git commit -m "feat: pestañas Historial/Uso en detalle de receta + comparación de 2 recetas"
```

---

## Self-Review

**Cobertura del spec:** Sección 1 (aislamiento) → Tasks 1-4. Sección 2 (núcleo + `recipe_agro_details`) → Tasks 5-6. Sección 3 (alta Canes Agro) → Tasks 7-9. Sección 4 (versionado/aprobación/trazabilidad/comparar) → Tasks 10-13, 21. Sección 5 (reestructura + Propuesta C) → Tasks 14-21. Los 2 riesgos conocidos del spec quedan explícitos: el supuesto de backfill en las migraciones de las Tasks 1, 2, 3 y 4 (comentado en cada migración), y la dependencia de que Canes Agro tenga puestos con `can_approve` configurados antes de que el flujo de aprobación tenga aprobadores reales (Task 11, nota del Step 1).

**Puntos abiertos que el ejecutor debe resolver al llegar a la tarea** (marcados explícitamente en el texto de cada tarea, no son placeholders del plan sino dependencias de código no leído en esta sesión): estructura exacta de `Employee`/`EmployeeFactory` (Task 11), cuerpo completo de `PendingApprovalController::getInventoryApprovalProcess()` (Task 11), columnas exactas de `produccion_empaque` más allá de `recipe_id` (Task 12).

**Consistencia de tipos:** `scopeForEnterprise(int $enterpriseId)` mismo nombre/firma en `ProductCategory`, `Brand`, `UnitOfMeasure` (Tasks 1-3) y `Recipe` (Task 4) — coherente con `Product::scopeForEnterprise()` ya existente. `buildPayload()` de `useRecipeEditorState` (Task 15) es lo único que `RecipeEditorView` (Task 20) llama para guardar — su forma de salida (`agroDetails: {cultivoId, variedadId, pesoPieza} | null`) coincide con lo que `RecipeController::extractAgroDetails()` (Task 6) acepta como payload anidado.

---

**Plan completo y guardado en `docs/superpowers/plans/2026-09-09-recetas-generalization.md`. Dos opciones de ejecución:**

**1. Subagent-Driven (recomendado)** — despacho un subagente fresco por tarea, con revisión entre tareas, iteración rápida.

**2. Ejecución en esta sesión** — ejecuto las tareas por lotes con puntos de revisión, usando executing-plans.

**¿Cuál prefieres?**
