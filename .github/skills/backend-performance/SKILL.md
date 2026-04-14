# Skill: Backend Performance & Query Optimization

## Descripción
Optimización de rendimiento en Laravel 12 para SENTINEL 3.0. Cubre detección de N+1, eager loading, caché, indexación y optimización de queries.

## Cuándo Usar
- Al crear o modificar controllers con queries a BD
- Al agregar relaciones en modelos
- Al detectar lentitud en endpoints
- Al hacer code review de performance
- Al crear reportes o dashboards con datos agregados

## Reglas de Performance

### 1. Eager Loading Obligatorio
```php
// ❌ NUNCA: Lazy loading en colecciones (causa N+1)
$products = Product::all();
foreach ($products as $p) {
    echo $p->category->name; // N+1 query!
}

// ✅ SIEMPRE: Eager load relaciones necesarias
$products = Product::with(['category:id,name,code', 'unit:id,name,abbreviation', 'brand:id,name,code'])->get();

// ✅ Select solo columnas necesarias en relaciones
Product::with(['category:id,name', 'brand:id,name'])->select('id', 'name', 'category_id', 'brand_id')->get();
```

### 2. Paginación en Listados
```php
// ❌ NUNCA en listados grandes
$records = Model::all();

// ✅ Paginación para listados
$records = Model::with('relations')
    ->when($search, fn($q) => $q->where('name', 'like', "%{$search}%"))
    ->orderBy('created_at', 'desc')
    ->paginate($perPage ?? 15);

// ✅ Sin paginación SOLO para selects/dropdowns (endpoint `list`)
$items = Model::where('is_active', true)
    ->select('id', 'code', 'name')
    ->orderBy('name')
    ->get();
```

### 3. Query Optimization
```php
// ❌ Subqueries innecesarias
$products = Product::all()->filter(fn($p) => $p->stock->sum('quantity') > 0);

// ✅ Usar withCount, withSum, withExists
$products = Product::withSum('stock', 'quantity')
    ->having('stock_sum_quantity', '>', 0)
    ->get();

// ✅ Chunk para procesos masivos
Product::where('is_active', true)->chunk(200, function ($products) {
    foreach ($products as $product) {
        // Proceso
    }
});

// ✅ Usar pluck para listas simples
$ids = Product::where('is_active', true)->pluck('id');
$names = Brand::active()->pluck('name', 'id');
```

### 4. Caché Estratégico
```php
// ✅ Cachear catálogos estáticos  
$categories = Cache::remember('product_categories', 3600, function () {
    return ProductCategory::with('children')->whereNull('parent_id')->get();
});

// ✅ Invalidar caché al modificar
public function store(Request $request) {
    $category = ProductCategory::create($data);
    Cache::forget('product_categories');
    return response()->json(['success' => true, 'data' => $category]);
}

// ✅ Tags para invalidación grupal (si usa Redis)
Cache::tags(['inventory', 'products'])->remember('key', 3600, fn() => ...);
Cache::tags(['inventory'])->flush(); // Invalida todo inventario
```

### 5. Índices de BD
```php
// ✅ En migraciones, siempre indexar:
$table->index('enterprise_id');              // FKs
$table->index('is_active');                  // Filtros frecuentes
$table->index('code');                       // Búsquedas por código
$table->index(['enterprise_id', 'is_active']); // Queries compuestas
$table->fullText(['name', 'description']);   // Búsquedas de texto

// ✅ Índice compuesto para queries multi-tenant
$table->index(['enterprise_id', 'created_at']);
```

### 6. Prevención de Memory Leaks
```php
// ❌ Cargar todo en memoria
$allRecords = HugeModel::all(); // 100K+ registros

// ✅ Usar cursor para streaming
foreach (HugeModel::cursor() as $record) {
    // Procesa uno a la vez, bajo consumo de memoria
}

// ✅ LazyCollection para transformaciones
HugeModel::lazy()->each(function ($record) {
    // Bajo consumo de memoria
});
```

### 7. Response Optimization
```php
// ✅ Usar API Resources para transformación consistente
// ✅ Seleccionar solo campos necesarios
// ✅ Comprimir respuestas grandes
// ✅ Usar when() para campos condicionales en Resources

return response()->json([
    'success' => true,
    'data' => $products,
    'meta' => [
        'total' => $products->total(),
        'per_page' => $products->perPage(),
        'current_page' => $products->currentPage(),
    ]
]);
```

### 8. Patrones Anti-N+1 en SENTINEL
```php
// Patrón estándar del proyecto para index():
public function index(Request $request)
{
    $query = Model::with(['relation1:id,name', 'relation2:id,name'])
        ->when($request->search, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        })
        ->when($request->is_active !== null, fn($q) => $q->where('is_active', $request->boolean('is_active')))
        ->orderBy($request->sort_by ?? 'created_at', $request->sort_dir ?? 'desc');

    $data = $request->has('per_page') 
        ? $query->paginate($request->per_page) 
        : $query->get();

    return response()->json(['success' => true, 'data' => $data]);
}
```

## Checklist de Performance para Code Review
- [ ] ¿Tiene eager loading las relaciones usadas en la respuesta?
- [ ] ¿El index() usa paginación?
- [ ] ¿Se seleccionan solo las columnas necesarias en with()?
- [ ] ¿Los filtros usan `when()` para queries condicionales?
- [ ] ¿Se evita `Model::all()` excepto en endpoint `list()`?
- [ ] ¿Las migraciones tienen índices en FKs y campos de búsqueda?
- [ ] ¿Se usa chunk/cursor para procesos de más de 1000 registros?
- [ ] ¿La respuesta incluye meta de paginación cuando aplica?
