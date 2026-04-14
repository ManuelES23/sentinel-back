# Skill: Code Audit & Security

## Descripción
Auditoría de código y seguridad para SENTINEL 3.0. Cubre OWASP Top 10, validación, sanitización, autenticación y prácticas seguras para Laravel + React.

## Cuándo Usar
- Al hacer code review de seguridad
- Al crear endpoints que reciben input del usuario
- Al implementar autenticación o autorización
- Al manejar archivos subidos (uploads)
- Al crear queries dinámicas
- Al exponer datos sensibles en responses

## Reglas de Seguridad

### 1. Validación de Input (Backend)
```php
// ✅ SIEMPRE validar TODOS los inputs en el controller
public function store(Request $request)
{
    $validated = $request->validate([
        'name'        => 'required|string|max:255',
        'email'       => 'required|email|unique:users,email',
        'code'        => 'required|string|max:50|unique:brands,code',
        'description' => 'nullable|string|max:1000',
        'quantity'    => 'required|integer|min:0|max:999999',
        'price'       => 'required|numeric|min:0|max:9999999.99',
        'is_active'   => 'boolean',
        'category_id' => 'required|exists:product_categories,id',
        'brand_id'    => 'nullable|exists:brands,id',
        'date'        => 'required|date|after_or_equal:today',
        'image'       => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
    ]);

    // Usar SOLO datos validados
    $record = Model::create($validated);
}

// ❌ NUNCA usar input sin validar
$record = Model::create($request->all()); // PELIGROSO
$record = Model::create($request->only(['name', 'code'])); // Sin validación
```

### 2. SQL Injection Prevention
```php
// ✅ Usar Eloquent ORM (query builder parametrizado)
$products = Product::where('name', 'like', "%{$search}%")->get();

// ✅ Bindings en queries raw
DB::select('SELECT * FROM products WHERE enterprise_id = ?', [$enterpriseId]);

// ❌ NUNCA concatenar input en queries
DB::select("SELECT * FROM products WHERE name = '$name'"); // SQL INJECTION
$query->whereRaw("name = '$input'"); // SQL INJECTION

// ✅ Si necesitas whereRaw, usar bindings
$query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]);
```

### 3. XSS Prevention (Frontend)
```jsx
// ✅ React escapa automáticamente en JSX
<p>{userInput}</p>  // Seguro: React escapa HTML

// ❌ NUNCA usar dangerouslySetInnerHTML con input del usuario
<div dangerouslySetInnerHTML={{ __html: userInput }} />  // XSS

// ✅ Si necesitas HTML dinámico, sanitizar con DOMPurify
import DOMPurify from 'dompurify';
<div dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(htmlContent) }} />

// ❌ NUNCA construir URLs con input sin sanitizar
window.location.href = userInput; // Open Redirect
```

### 4. Autenticación y Autorización
```php
// ✅ Proteger rutas con middleware
Route::middleware('auth:sanctum')->group(function () {
    // Todas las rutas protegidas aquí
});

// ✅ Verificar permisos en controller
public function update(Request $request, $id)
{
    $record = Model::findOrFail($id);
    
    // Verificar que el usuario tiene acceso a esta empresa
    $enterpriseSlug = $request->header('X-Enterprise-Slug');
    $this->authorizeEnterprise($enterpriseSlug);
    
    // Verificar ownership si aplica
    if ($record->enterprise_id !== $request->user()->enterprise_id) {
        return response()->json(['status' => 'error', 'message' => 'No autorizado'], 403);
    }
}

// ✅ Usar findOrFail para evitar enumeration
$record = Model::findOrFail($id); // 404 automático si no existe

// ❌ NUNCA exponer IDs secuenciales sin verificar acceso
$record = Model::find($id); // Podría acceder a datos de otra empresa
```

### 5. Multi-Tenant Data Isolation
```php
// ✅ SIEMPRE filtrar por enterprise_id en queries multi-tenant
$records = Model::where('enterprise_id', $request->user()->enterprise_id)
    ->get();

// ✅ Scope global si el modelo es multi-tenant
protected static function booted()
{
    static::addGlobalScope('enterprise', function ($query) {
        if (auth()->check()) {
            $query->where('enterprise_id', auth()->user()->enterprise_id);
        }
    });
}

// ❌ NUNCA confiar solo en el header X-Enterprise-Slug para filtrar datos
// El header es para logging, la autorización debe validarse server-side
```

### 6. File Upload Security
```php
// ✅ Validar tipo, tamaño y extensión
$request->validate([
    'image' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048', // 2MB max
    'document' => 'nullable|file|mimes:pdf,doc,docx,xlsx|max:10240',   // 10MB max
]);

// ✅ Almacenar con nombre hasheado (no el original)
$path = $request->file('image')->store('products', 'public');

// ❌ NUNCA usar el nombre original del archivo
$name = $request->file('image')->getClientOriginalName(); // Podría ser malicioso
$request->file('image')->move(public_path(), $name); // Path traversal

// ✅ Verificar que es realmente una imagen
$request->file('image')->isValid();
$mimeType = $request->file('image')->getMimeType(); // Verificar server-side
```

### 7. Mass Assignment Protection
```php
// ✅ Definir $fillable explícitamente en cada modelo
protected $fillable = ['name', 'code', 'is_active'];

// ❌ NUNCA usar $guarded = [] (permite todo)
protected $guarded = []; // PELIGROSO

// ✅ Usar $validated del request, no $request->all()
$record = Model::create($request->validated());
```

### 8. Rate Limiting
```php
// ✅ En RouteServiceProvider o bootstrap
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

// ✅ Rate limiting específico para login
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip());
});
```

### 9. Sensitive Data Exposure
```php
// ✅ Ocultar campos sensibles en respuestas JSON
protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

// ✅ No loggear datos sensibles
ActivityLog::log(
    oldValues: Arr::except($oldValues, ['password', 'token']),
    newValues: Arr::except($newValues, ['password', 'token'])
);

// ✅ Usar $casts para encriptar datos sensibles
protected $casts = [
    'api_key' => 'encrypted',
    'password' => 'hashed',
];
```

### 10. Frontend Security
```jsx
// ✅ Token en memoria, no en localStorage accesible
// SENTINEL usa localStorage para token (aceptable con Sanctum SPA guard)

// ✅ Validar datos antes de enviar al backend
const validateForm = (data) => {
  const errors = {};
  if (!data.name?.trim()) errors.name = 'Nombre requerido';
  if (data.name?.length > 255) errors.name = 'Máximo 255 caracteres';
  if (data.price && isNaN(data.price)) errors.price = 'Debe ser número';
  if (data.price < 0) errors.price = 'No puede ser negativo';
  return errors;
};

// ✅ Sanitizar búsquedas
const sanitizeSearch = (input) => input.replace(/[<>\"'&]/g, '');

// ✅ Limitar reintentos en llamadas API
const MAX_RETRIES = 3;
```

## Checklist de Seguridad para Code Review

### Backend
- [ ] ¿Todos los inputs validados con `$request->validate()`?
- [ ] ¿Se usa `$validated` en lugar de `$request->all()`?
- [ ] ¿FKs validadas con `exists:table,id`?
- [ ] ¿Uploads validados: tipo, tamaño, extensión?
- [ ] ¿Queries usan Eloquent o bindings (no concatenación)?
- [ ] ¿Datos filtrados por enterprise_id en queries multi-tenant?
- [ ] ¿`findOrFail()` usado en lugar de `find()`?
- [ ] ¿Campos sensibles en `$hidden` del modelo?
- [ ] ¿`$fillable` definido (no `$guarded = []`)?
- [ ] ¿Endpoint público justificado (checador)?

### Frontend
- [ ] ¿No hay `dangerouslySetInnerHTML` con input del usuario?
- [ ] ¿Validación de formulario antes de enviar?
- [ ] ¿Headers Authorization incluidos en peticiones?
- [ ] ¿Errores 401 redirigen a login?
- [ ] ¿No se exponen tokens/secrets en console.log?
- [ ] ¿URLs no se construyen con input sin sanitizar?
