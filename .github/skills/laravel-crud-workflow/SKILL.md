# Skill: Laravel CRUD Resource Workflow

## Descripción
Workflow estandarizado para crear nuevos recursos CRUD completos en el backend SENTINEL 3.0. Incluye migración, modelo, controller, rutas, evento broadcast y notificaciones.

## Cuándo Usar
- Al crear un nuevo recurso/entidad completo
- Al agregar un nuevo submódulo con operaciones CRUD
- Al replicar un patrón existente para una nueva tabla

## Workflow Paso a Paso

### Paso 1: Migración
```php
// php artisan make:migration create_{tabla}_table
// Ubicación: database/migrations/

Schema::create('nombre_tabla', function (Blueprint $table) {
    $table->id();
    $table->string('code', 50)->unique();           // Código auto-generado
    $table->string('name', 255);
    $table->text('description')->nullable();
    $table->boolean('is_active')->default(true);
    
    // FK a empresa (si es multi-tenant)
    $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
    
    // FKs a catálogos (nullable con nullOnDelete si es opcional)
    $table->foreignId('category_id')->constrained('product_categories')->cascadeOnDelete();
    $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
    
    // Campos numéricos con precisión
    $table->decimal('price', 12, 2)->default(0);
    $table->integer('quantity')->default(0);
    
    // Auditoría
    $table->timestamps();
    $table->softDeletes();
    
    // Índices compuestos para queries frecuentes
    $table->index(['enterprise_id', 'is_active']);
    $table->index('code');
});
```

### Paso 2: Modelo
```php
// Ubicación: app/Models/NombreModelo.php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NombreModelo extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'nombre_tabla';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'enterprise_id',
        'category_id',
        'brand_id',
        'price',
        'quantity',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'decimal:2',
    ];

    // ── Relaciones ──
    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    // ── Scopes ──
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEnterprise($query, $enterpriseId)
    {
        return $query->where('enterprise_id', $enterpriseId);
    }
}
```

### Paso 3: Controller
```php
// Ubicación: app/Http/Controllers/Api/{Empresa}/{App}/NombreController.php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\NombreModelo;
use Illuminate\Http\Request;

class NombreController extends Controller
{
    // ── Listar (paginado con filtros) ──
    public function index(Request $request)
    {
        $query = NombreModelo::with(['category:id,name,code', 'brand:id,name,code'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($request->is_active !== null, fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->category_id, fn($q, $v) => $q->where('category_id', $v))
            ->orderBy($request->sort_by ?? 'created_at', $request->sort_dir ?? 'desc');

        $data = $request->has('per_page')
            ? $query->paginate($request->per_page)
            : $query->get();

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ── Lista simple para selects ──
    public function list()
    {
        $items = NombreModelo::where('is_active', true)
            ->select('id', 'code', 'name')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $items]);
    }

    // ── Crear ──
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active'   => 'boolean',
            'category_id' => 'required|exists:product_categories,id',
            'brand_id'    => 'nullable|exists:brands,id',
            'price'       => 'required|numeric|min:0',
            'quantity'    => 'required|integer|min:0',
        ]);

        // Auto-generar código (incluir soft-deleted para evitar duplicados)
        $lastCode = NombreModelo::withTrashed()
            ->where('code', 'like', 'PRF-%')
            ->orderByRaw('CAST(SUBSTRING(code, 5) AS UNSIGNED) DESC')
            ->value('code');
        $nextNumber = $lastCode ? (int) substr($lastCode, 4) + 1 : 1;
        $validated['code'] = 'PRF-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

        $record = NombreModelo::create($validated);
        $record->load(['category:id,name,code', 'brand:id,name,code']);

        return response()->json([
            'success' => true,
            'message' => 'Registro creado exitosamente',
            'data' => $record,
        ], 201);
    }

    // ── Mostrar ──
    public function show($id)
    {
        $record = NombreModelo::with(['category:id,name,code', 'brand:id,name,code'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $record]);
    }

    // ── Actualizar ──
    public function update(Request $request, $id)
    {
        $record = NombreModelo::findOrFail($id);

        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active'   => 'boolean',
            'category_id' => 'sometimes|required|exists:product_categories,id',
            'brand_id'    => 'nullable|exists:brands,id',
            'price'       => 'sometimes|required|numeric|min:0',
            'quantity'    => 'sometimes|required|integer|min:0',
        ]);

        $record->update($validated);
        $record->load(['category:id,name,code', 'brand:id,name,code']);

        return response()->json([
            'success' => true,
            'message' => 'Registro actualizado exitosamente',
            'data' => $record,
        ]);
    }

    // ── Eliminar ──
    public function destroy($id)
    {
        $record = NombreModelo::findOrFail($id);

        // Verificar dependencias antes de eliminar
        // if ($record->children()->exists()) { ... }

        $record->delete();

        return response()->json([
            'success' => true,
            'message' => 'Registro eliminado exitosamente',
        ]);
    }
}
```

### Paso 4: Rutas
```php
// En routes/api.php, bajo el prefijo correcto:

// Splendid Farms → Inventario → Catálogos
Route::prefix('splendidfarms/inventario/catalogos')->group(function () {
    Route::get('nombre-recurso/list', [NombreController::class, 'list']);
    Route::apiResource('nombre-recurso', NombreController::class);
});
```

### Paso 5: Evento Broadcast (opcional)
```php
// app/Events/NombreModeloUpdated.php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class NombreModeloUpdated implements ShouldBroadcast
{
    public string $action;
    public array $data;

    public function __construct(string $action, $model)
    {
        $this->action = $action;
        $this->data = $model->toArray();
    }

    public function broadcastOn(): Channel
    {
        return new Channel('module.splendidfarms.inventario.catalogos');
    }

    public function broadcastAs(): string
    {
        return 'nombre-modelo.updated';
    }
}
```

## Convenciones de Código del Proyecto

| Concepto | Convención |
|----------|-----------|
| Prefijo código | `PRF-XXXXX` (prefijo de 3-4 letras + guión + número) |
| `withTrashed()` | Siempre al generar códigos auto-incrementales |
| Formato respuesta | `{ success: bool, message: string, data: mixed }` |
| Formato error | `{ status: 'error', message: string }` |
| Soft deletes | Siempre en entidades principales |
| Trait Loggable | Siempre en modelos auditables |
| Eager loading | Siempre con `select` de columnas: `with(['rel:id,name'])` |
| Validación FK | `'field_id' => 'required\|exists:table,id'` |
| Headers X- | Para logging, NO para autorización |

## Checklist de Nuevo Recurso
- [ ] Migración con índices, FKs, softDeletes
- [ ] Modelo con `Loggable`, `SoftDeletes`, `$fillable`, `$casts`, relaciones, scopes
- [ ] Controller con `index`, `list`, `store`, `show`, `update`, `destroy`
- [ ] Validación completa en `store` y `update`
- [ ] Auto-generación de código con `withTrashed()`
- [ ] Eager loading en `index` y `show`
- [ ] Rutas en `api.php` bajo prefijo correcto
- [ ] Evento broadcast si requiere tiempo real
- [ ] Verificar dependencias antes de `destroy`
