# CRM Integraciones · Dialpad — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** las llamadas concluidas de Dialpad (una sola API key administrada a nivel de empresa, sin conexión por usuario) se importan automáticamente como `CrmActividad` (`tipo='llamada'`, `fuente='dialpad'`), vinculadas por coincidencia de teléfono a Cliente/Prospecto/Contacto y por coincidencia de email a un vendedor (que determina la `empresa_id`), y quedan disponibles para revisar/clasificar en un nuevo submódulo `Integraciones > Dialpad`.

**Architecture:** una tabla nueva y global (`crm_dialpad_sync_estado`, una sola fila) que registra el estado de la última corrida; un comando programado (`crm:sincronizar-dialpad`, cada 15 min, también disparable manualmente) que pagina `GET {base_url}/call` de la API de Dialpad vía `Http` puro (testeable con `Http::fake()`, sin SDK), resuelve vendedor/entidad y crea/actualiza `CrmActividad`; un controlador de 4 endpoints (`DialpadIntegracionController`) para listar/clasificar/sincronizar/consultar estado, gateado por los permisos `sync`/`ver`/`editar` que **ya existen** en el seeder; y una vista de frontend (`DialpadView` + `DialpadClasificarModal`) que reutiliza los patrones ya establecidos por Agenda/Outlook (selector de vendedor con precedencia, `SearchableSelect`, tokens de color por submódulo).

**Tech Stack:** Laravel 12 (PHP 8.2) + `Illuminate\Support\Facades\Http` (sin SDK de Dialpad), `Illuminate\Support\Facades\Cache` (dedupe de warnings + traspaso de contadores sync manual→controlador); React 19 + Vite 7 + TailwindCSS 4, `react-select` vía `SearchableSelect`.

**Spec:** `docs/superpowers/specs/2026-08-24-crm-dialpad-integracion-design.md` (vive en el repo `sentinel-front`, ya que ahí se escribió — el contenido aplica igual a este repo backend).

## Global Constraints

- Sincronización **estrictamente unidireccional**: Dialpad → Sentinel. Ningún código de este plan escribe ni modifica nada del lado de Dialpad.
- **Una sola conexión global**, sin tabla de conexión por usuario: la API key vive en `.env` (`CRM_DIALPAD_API_KEY`, ya reservada). No hay flujo de "conectar/desconectar".
- `crm_actividades` **no recibe ninguna migración** en este plan — ya tiene `fuente` y `dialpad_call_id` desde julio 2026, sin usar hasta ahora.
- El seeder `CrmPermisosSeeder.php` **ya tiene** el submódulo `dialpad` con los permisos `sync`/`ver`/`editar` bajo el módulo `integraciones` — este plan no lo modifica. Tampoco se agrega ningún case a `App\Enums\CrmPermiso` (ese enum no es consumido por ningún controlador — ver Task 1, es dead code fuera de alcance).
- Un fallo de autenticación o red detiene el comando **completo** para esa corrida (a diferencia de Outlook, aquí solo hay una conexión) — se guarda en `crm_dialpad_sync_estado.ultimo_error`, se loguea, se reintenta en la siguiente corrida programada.
- **Rate limit (429):** se loguea y se corta la corrida sin persistir avance (`ultimo_call_id_sincronizado`/`ultimo_sync_at` no se actualizan esa corrida).
- **Re-sincronización seguras**: si un `dialpad_call_id` ya existe, se actualiza `descripcion`/`duracion_minutos` **solo si** `entidad_id` y `resultado` siguen ambos `null` — nunca se pisa una clasificación manual ya hecha.
- Ruling de diseño (no estaba en el spec, se decide aquí): el spec pide mostrar en el frontend "el número crudo si no hay match" de entidad, pero `crm_actividades` no tiene columna de teléfono y el plan no puede agregarle una (constraint de arriba). Se resuelve incluyendo el teléfono del contacto entre paréntesis dentro de la propia `descripcion` autogenerada (`"Llamada {direccion} de Dialpad ({telefono}) — {duracion}"`), que el frontend extrae con una expresión regular cuando no hay entidad vinculada. Ver Task 2 (backend, dueño del formato) y Task 4 (frontend, consumidor).
- Después de cada cambio de código: `php artisan route:clear && php artisan config:clear && php artisan view:clear`, y `php -l` sobre el archivo modificado. Nunca correr `php artisan test`/`migrate` con `--env=`.

---

### Task 1: Esquema y configuración

**Files:**
- Create: `database/migrations/2026_08_25_000000_create_crm_dialpad_sync_estado_table.php`
- Create: `app/Models/CRM/CrmDialpadSyncEstado.php`
- Modify: `config/services.php` (agrega la clave `dialpad`)
- Modify: `.env.example` (agrega `CRM_DIALPAD_BASE_URL`)
- Test: `tests/Feature/CRM/CrmDialpadSyncEstadoTest.php`

**Interfaces:**
- Produces: `CrmDialpadSyncEstado` (tabla `crm_dialpad_sync_estado`, cast `ultimo_sync_at` → `datetime`), método estático `CrmDialpadSyncEstado::obtenerSingleton(): CrmDialpadSyncEstado` (siempre devuelve la única fila, la crea si no existe). Config `config('services.dialpad.api_key')` / `config('services.dialpad.base_url')`.

- [ ] **Step 1: Migración de `crm_dialpad_sync_estado`**

```php
<?php
// database/migrations/2026_08_25_000000_create_crm_dialpad_sync_estado_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_dialpad_sync_estado', function (Blueprint $table) {
            $table->id();
            // Cursor propio (call_id del más reciente visto), no el cursor
            // opaco de paginación de Dialpad -- ver SincronizarDialpadCommand.
            $table->string('ultimo_call_id_sincronizado')->nullable();
            $table->datetime('ultimo_sync_at')->nullable();
            $table->text('ultimo_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_dialpad_sync_estado');
    }
};
```

- [ ] **Step 2: Correr la migración**

```bash
php artisan migrate
```

Expected: la tabla se crea sin error.

- [ ] **Step 3: Modelo `CrmDialpadSyncEstado`**

```php
<?php
// app/Models/CRM/CrmDialpadSyncEstado.php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una sola fila en toda la tabla -- no hay empresa_id porque
 * CRM_DIALPAD_API_KEY es una única clave compartida por todo Sentinel; la
 * empresa de cada llamada se resuelve por vendedor, nunca aquí (ver
 * SincronizarDialpadCommand). obtenerSingleton() es el único punto de
 * entrada: crea la fila la primera vez que se necesita, la reutiliza
 * después.
 */
class CrmDialpadSyncEstado extends Model
{
    use HasFactory;

    protected $table = 'crm_dialpad_sync_estado';

    protected $fillable = [
        'ultimo_call_id_sincronizado',
        'ultimo_sync_at',
        'ultimo_error',
    ];

    protected $casts = [
        'ultimo_sync_at' => 'datetime',
    ];

    public static function obtenerSingleton(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
```

- [ ] **Step 4: Config `services.php`**

Agrega en `config/services.php`, antes del cierre `];` final:

```php
    'dialpad' => [
        'api_key' => env('CRM_DIALPAD_API_KEY'),
        'base_url' => env('CRM_DIALPAD_BASE_URL', 'https://dialpad.com/api/v2'),
    ],
```

- [ ] **Step 5: Documentar `CRM_DIALPAD_BASE_URL`**

En `.env.example`, el bloque actual es:

```
CRM_DIALPAD_API_KEY=
CRM_ISOLVE_BASE_URL=
CRM_ISOLVE_API_KEY=
```

Cámbialo por:

```
CRM_DIALPAD_API_KEY=
CRM_DIALPAD_BASE_URL=https://dialpad.com/api/v2
CRM_ISOLVE_BASE_URL=
CRM_ISOLVE_API_KEY=
```

- [ ] **Step 6: Tests**

```php
<?php
// tests/Feature/CRM/CrmDialpadSyncEstadoTest.php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmDialpadSyncEstado;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class CrmDialpadSyncEstadoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    public function test_obtener_singleton_crea_la_fila_si_no_existe(): void
    {
        $this->assertDatabaseCount('crm_dialpad_sync_estado', 0);

        $estado = CrmDialpadSyncEstado::obtenerSingleton();

        $this->assertDatabaseCount('crm_dialpad_sync_estado', 1);
        $this->assertNull($estado->ultimo_call_id_sincronizado);
        $this->assertNull($estado->ultimo_sync_at);
        $this->assertNull($estado->ultimo_error);
    }

    public function test_obtener_singleton_devuelve_la_misma_fila_en_llamadas_subsecuentes(): void
    {
        $primera = CrmDialpadSyncEstado::obtenerSingleton();
        $primera->update(['ultimo_error' => 'Error de prueba']);

        $segunda = CrmDialpadSyncEstado::obtenerSingleton();

        $this->assertEquals($primera->id, $segunda->id);
        $this->assertEquals('Error de prueba', $segunda->ultimo_error);
        $this->assertDatabaseCount('crm_dialpad_sync_estado', 1);
    }

    /**
     * Guarda de regresión: este plan depende de que el seeder YA tenga estos
     * permisos (no los vuelve a crear). Si algún día alguien los quita del
     * seeder sin darse cuenta, este test lo detecta.
     */
    public function test_el_seeder_ya_tiene_los_permisos_sync_ver_editar_de_dialpad(): void
    {
        $this->seed(CrmPermisosSeeder::class);

        $modulo = Module::where('slug', 'integraciones')->first();
        $this->assertNotNull($modulo, 'El módulo integraciones debe existir.');

        $submodulo = Submodule::where('module_id', $modulo->id)->where('slug', 'dialpad')->first();
        $this->assertNotNull($submodulo, 'El submódulo dialpad debe existir.');

        foreach (['sync', 'ver', 'editar'] as $slug) {
            $this->assertNotNull(
                SubmodulePermissionType::where('submodule_id', $submodulo->id)->where('slug', $slug)->first(),
                "Falta el permiso '{$slug}' en el submódulo dialpad.",
            );
        }
    }
}
```

- [ ] **Step 7: Correr los tests**

```bash
php artisan test --filter=CrmDialpadSyncEstadoTest
```

Expected: 3 tests, 0 fallos.

- [ ] **Step 8: Verificación post-cambio y commit**

```bash
php artisan route:clear && php artisan config:clear && php artisan view:clear
php -l app/Models/CRM/CrmDialpadSyncEstado.php
git add database/migrations/2026_08_25_000000_create_crm_dialpad_sync_estado_table.php \
        app/Models/CRM/CrmDialpadSyncEstado.php config/services.php .env.example \
        tests/Feature/CRM/CrmDialpadSyncEstadoTest.php
git commit -m "feat(crm): esquema y config para la integración con Dialpad"
```

---

### Task 2: Comando programado de sincronización

**Files:**
- Create: `app/Console/Commands/SincronizarDialpadCommand.php`
- Modify: `routes/console.php` (agrega el `Schedule::command(...)`)
- Test: `tests/Feature/CRM/SincronizarDialpadCommandTest.php`

**Interfaces:**
- Consumes: `CrmDialpadSyncEstado::obtenerSingleton()` (Task 1), `CrmActividad`, `CrmVendedor`, `CrmCliente`, `CrmProspecto`, `CrmContacto` (modelos ya existentes), `config('services.dialpad.*')` (Task 1).
- Produces: comando `crm:sincronizar-dialpad`, ejecutable vía `php artisan crm:sincronizar-dialpad` y programado cada 15 minutos. Constante pública `SincronizarDialpadCommand::CACHE_ULTIMA_CORRIDA` (clave de Cache donde se guarda `['sincronizadas' => int, 'omitidas' => int]` de la corrida más reciente — la consume `DialpadIntegracionController::sincronizar()` en Task 3).

- [ ] **Step 1: El comando**

```php
<?php
// app/Console/Commands/SincronizarDialpadCommand.php

namespace App\Console\Commands;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmDialpadSyncEstado;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Importa llamadas concluidas de Dialpad (una sola API key global, sin
 * conexión por usuario) como CrmActividad (tipo='llamada', fuente='dialpad').
 * Unidireccional: solo LEE de Dialpad, nunca escribe nada allá.
 *
 * A diferencia de SincronizarOutlookCommand (que aísla fallos por conexión,
 * porque hay muchas), aquí solo existe UNA conexión global -- un fallo de
 * red o de autenticación detiene el comando completo para esa corrida.
 *
 * Nota de diseño -- "cursor" propio vs. cursor de Dialpad: dentro de UNA
 * corrida se usa el `cursor` opaco que devuelve la API de Dialpad para
 * pedir la siguiente página (ver solicitarPagina()). Entre corridas se
 * guarda algo distinto en crm_dialpad_sync_estado.ultimo_call_id_sincronizado:
 * el call_id de la llamada más reciente vista, solo con fines de
 * diagnóstico/visibilidad para un admin -- NO se usa para decidir dónde
 * detener la paginación. La API devuelve las llamadas en orden cronológico
 * inverso, así que simplemente se recorren hasta MAX_PAGINAS_POR_CORRIDA
 * páginas en cada corrida; reprocesar llamadas ya vistas en corridas
 * anteriores es intencional y barato (creación idempotente por
 * dialpad_call_id, re-sync no pisa clasificaciones manuales -- ver abajo).
 */
class SincronizarDialpadCommand extends Command
{
    protected $signature = 'crm:sincronizar-dialpad';

    protected $description = 'Importa llamadas concluidas de Dialpad como CrmActividad (tipo=llamada, fuente=dialpad)';

    /** Clave de Cache donde se guardan los contadores de la corrida más reciente, para que el disparo manual (Task 3) los pueda leer justo después de invocar Artisan::call(). */
    public const CACHE_ULTIMA_CORRIDA = 'dialpad_ultima_corrida';

    private const CACHE_PREFIX_OMITIDA = 'dialpad_call_omitida:';

    /** Límite de páginas por corrida -- acota cuánto puede tardar tanto la corrida programada como el disparo manual síncrono (ver DialpadIntegracionController::sincronizar()). */
    private const MAX_PAGINAS_POR_CORRIDA = 20;

    public function handle(): int
    {
        $estado = CrmDialpadSyncEstado::obtenerSingleton();

        $apiKey = config('services.dialpad.api_key');
        if (! $apiKey) {
            $mensaje = 'CRM_DIALPAD_API_KEY no está configurada.';
            Log::error($mensaje);
            $estado->update(['ultimo_error' => $mensaje]);
            Cache::put(self::CACHE_ULTIMA_CORRIDA, ['sincronizadas' => 0, 'omitidas' => 0], now()->addMinutes(5));

            return self::FAILURE;
        }

        $sincronizadas = 0;
        $omitidas = 0;
        $masRecienteCallId = null;
        $cursor = null;
        $pagina = 0;
        $rateLimited = false;

        try {
            do {
                $response = $this->solicitarPagina($apiKey, $cursor);

                if ($response->status() === 429) {
                    Log::warning('Rate limit de Dialpad alcanzado, se corta la corrida sin avanzar el cursor.');
                    $rateLimited = true;
                    break;
                }

                $response->throw();

                $data = $response->json() ?? [];
                $items = $data['items'] ?? [];

                foreach ($items as $llamada) {
                    if ($masRecienteCallId === null && isset($llamada['call_id'])) {
                        $masRecienteCallId = (string) $llamada['call_id'];
                    }

                    if ($this->procesarLlamada($llamada)) {
                        $sincronizadas++;
                    } else {
                        $omitidas++;
                    }
                }

                $cursor = $data['cursor'] ?? null;
                $pagina++;
            } while ($cursor && $pagina < self::MAX_PAGINAS_POR_CORRIDA);

            if (! $rateLimited) {
                $estado->update([
                    'ultimo_call_id_sincronizado' => $masRecienteCallId ?? $estado->ultimo_call_id_sincronizado,
                    'ultimo_sync_at' => now(),
                    'ultimo_error' => null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("Error al sincronizar llamadas de Dialpad: {$e->getMessage()}", ['exception' => $e]);
            $estado->update(['ultimo_error' => $e->getMessage()]);
            Cache::put(self::CACHE_ULTIMA_CORRIDA, ['sincronizadas' => $sincronizadas, 'omitidas' => $omitidas], now()->addMinutes(5));

            return self::FAILURE;
        }

        Cache::put(self::CACHE_ULTIMA_CORRIDA, ['sincronizadas' => $sincronizadas, 'omitidas' => $omitidas], now()->addMinutes(5));

        $this->info("Llamadas de Dialpad sincronizadas: {$sincronizadas}, omitidas: {$omitidas}");

        return self::SUCCESS;
    }

    private function solicitarPagina(string $apiKey, ?string $cursor)
    {
        $baseUrl = rtrim((string) config('services.dialpad.base_url'), '/');
        $query = $cursor ? ['cursor' => $cursor] : [];

        return Http::withToken($apiKey)->acceptJson()->get("{$baseUrl}/call", $query);
    }

    /**
     * Procesa una llamada del payload de Dialpad. Devuelve true si se creó o
     * actualizó una CrmActividad, false si se omitió (sin vendedor match).
     */
    private function procesarLlamada(array $llamada): bool
    {
        $callId = isset($llamada['call_id']) ? (string) $llamada['call_id'] : null;
        if (! $callId) {
            return false;
        }

        $emailAgente = $llamada['target']['email'] ?? null;
        $vendedor = $this->resolverVendedor($emailAgente);

        if (! $vendedor) {
            $this->registrarOmisionSinVendedor($callId, $emailAgente);

            return false;
        }

        $telefonoContacto = $llamada['contact']['phone'] ?? null;
        [$entidadType, $entidadId] = $this->resolverEntidad((int) $vendedor->empresa_id, $telefonoContacto);

        $direccion = ($llamada['direction'] ?? null) === 'outbound' ? 'saliente' : 'entrante';
        $duracionMinutos = isset($llamada['duration']) ? (int) round(((float) $llamada['duration']) / 60000) : null;
        $fechaActividad = isset($llamada['date_started'])
            ? Carbon::createFromTimestampMs((int) $llamada['date_started'])
            : now();

        $telefonoLabel = $telefonoContacto ?: 'número desconocido';
        $duracionLabel = $duracionMinutos !== null ? "{$duracionMinutos} min" : 'duración desconocida';
        $descripcion = "Llamada {$direccion} de Dialpad ({$telefonoLabel}) — {$duracionLabel}";

        $existente = CrmActividad::where('dialpad_call_id', $callId)->first();

        if ($existente) {
            // Re-sync: solo se actualiza si nadie la ha clasificado manualmente (Global Constraints).
            if ($existente->entidad_id === null && $existente->resultado === null) {
                $existente->update([
                    'descripcion' => $descripcion,
                    'duracion_minutos' => $duracionMinutos,
                ]);
            }

            return true;
        }

        CrmActividad::create([
            'empresa_id' => $vendedor->empresa_id,
            'tipo' => 'llamada',
            'entidad_type' => $entidadType,
            'entidad_id' => $entidadId,
            'vendedor_id' => $vendedor->id,
            'descripcion' => $descripcion,
            'fecha_actividad' => $fechaActividad,
            'duracion_minutos' => $duracionMinutos,
            'fuente' => 'dialpad',
            'dialpad_call_id' => $callId,
        ]);

        return true;
    }

    /**
     * Resuelve el vendedor por email, SIN filtrar por empresa (en este punto
     * no se sabe a cuál pertenece -- la empresa de la Actividad se resuelve
     * de la empresa_id de este vendedor, es la única fuente de verdad).
     */
    private function resolverVendedor(?string $email): ?CrmVendedor
    {
        if (! $email) {
            return null;
        }

        $vendedores = CrmVendedor::where('email', $email)->orderBy('id')->get();

        if ($vendedores->count() > 1) {
            $ids = $vendedores->pluck('id')->implode(',');
            Log::warning("Email de vendedor Dialpad duplicado entre empresas: {$email} (vendedor_ids: {$ids}). Se usa el primero.");
        }

        return $vendedores->first();
    }

    /**
     * Resuelve la entidad relacionada por teléfono, dentro de la empresa ya
     * resuelta por el vendedor. Prioridad: Cliente > Prospecto > Contacto.
     * Devuelve [entidad_type FQCN, entidad_id] o [null, null] si no hay match
     * o no hay teléfono que buscar.
     */
    private function resolverEntidad(int $empresaId, ?string $telefono): array
    {
        if (! $telefono) {
            return [null, null];
        }

        $cliente = CrmCliente::where('empresa_id', $empresaId)->where('telefono', $telefono)->first();
        if ($cliente) {
            return [CrmCliente::class, $cliente->id];
        }

        $prospecto = CrmProspecto::where('empresa_id', $empresaId)->where('telefono', $telefono)->first();
        if ($prospecto) {
            return [CrmProspecto::class, $prospecto->id];
        }

        $contacto = CrmContacto::where('empresa_id', $empresaId)->where('telefono', $telefono)->first();
        if ($contacto) {
            return [CrmContacto::class, $contacto->id];
        }

        return [null, null];
    }

    /**
     * Loguea la omisión por falta de vendedor UNA sola vez por call_id (con
     * Cache, TTL de 30 días) -- evita repetir el mismo warning en cada
     * corrida futura (cada 15 min) hasta que un admin registre al vendedor.
     */
    private function registrarOmisionSinVendedor(string $callId, ?string $email): void
    {
        $cacheKey = self::CACHE_PREFIX_OMITIDA.$callId;
        if (Cache::has($cacheKey)) {
            return;
        }

        Log::warning("Llamada de Dialpad omitida: ningún vendedor coincide con el email '{$email}' (call_id: {$callId}).");
        Cache::put($cacheKey, true, now()->addDays(30));
    }
}
```

- [ ] **Step 2: Programar el comando**

En `routes/console.php`, agrega al final (después de `agenda:sincronizar-outlook`):

```php
// Sincronización de llamadas Dialpad (CRM): unidireccional, cada 15 min es
// suficiente para que las llamadas recientes aparezcan sin sobrecargar la
// API de Dialpad con corridas más frecuentes.
Schedule::command('crm:sincronizar-dialpad')->everyFifteenMinutes();
```

- [ ] **Step 3: Tests**

```php
<?php
// tests/Feature/CRM/SincronizarDialpadCommandTest.php

namespace Tests\Feature\CRM;

use App\Console\Commands\SincronizarDialpadCommand;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmDialpadSyncEstado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class SincronizarDialpadCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        config([
            'services.dialpad.api_key' => 'fake-api-key',
            'services.dialpad.base_url' => 'https://dialpad.test/api/v2',
        ]);
        // El vendedor de fixtures no tiene email por defecto -- las llamadas
        // de prueba deben poder resolverlo.
        $this->vendedor->update(['email' => 'juan.perez@example.com']);
    }

    private function llamadaFake(array $overrides = []): array
    {
        return array_merge([
            'call_id' => 'call-1',
            'direction' => 'inbound',
            'duration' => 240000, // 4 minutos en ms
            'date_started' => now()->subMinutes(10)->valueOf(),
            'target' => ['email' => 'juan.perez@example.com'],
            'contact' => ['phone' => '6621234567'],
        ], $overrides);
    }

    public function test_llamada_con_vendedor_y_contacto_match_crea_actividad_completa(): void
    {
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Cliente Fake',
            'telefono' => '6621234567',
            'estatus' => 'activo',
        ]);

        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([
                'items' => [$this->llamadaFake()],
                'cursor' => null,
            ], 200),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $this->assertDatabaseHas('crm_actividades', [
            'dialpad_call_id' => 'call-1',
            'fuente' => 'dialpad',
            'tipo' => 'llamada',
            'vendedor_id' => $this->vendedor->id,
            'entidad_type' => CrmCliente::class,
            'entidad_id' => $cliente->id,
            'duracion_minutos' => 4,
        ]);
    }

    public function test_llamada_con_vendedor_pero_sin_contacto_crea_actividad_sin_entidad(): void
    {
        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([
                'items' => [$this->llamadaFake(['call_id' => 'call-2'])],
                'cursor' => null,
            ], 200),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $this->assertDatabaseHas('crm_actividades', [
            'dialpad_call_id' => 'call-2',
            'entidad_type' => null,
            'entidad_id' => null,
        ]);
    }

    public function test_llamada_sin_vendedor_en_ninguna_empresa_se_omite(): void
    {
        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([
                'items' => [$this->llamadaFake([
                    'call_id' => 'call-3',
                    'target' => ['email' => 'nadie@example.com'],
                ])],
                'cursor' => null,
            ], 200),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $this->assertDatabaseMissing('crm_actividades', ['dialpad_call_id' => 'call-3']);
    }

    public function test_resync_de_llamada_ya_clasificada_manualmente_no_pisa_la_clasificacion(): void
    {
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Cliente Fake',
            'telefono' => '6621234567',
            'estatus' => 'activo',
        ]);

        $actividad = CrmActividad::create([
            'empresa_id' => $this->enterprise->id,
            'tipo' => 'llamada',
            'entidad_type' => CrmCliente::class,
            'entidad_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id,
            'descripcion' => 'Descripción original clasificada',
            'fecha_actividad' => now()->subHour(),
            'duracion_minutos' => 2,
            'resultado' => 'Cliente interesado',
            'fuente' => 'dialpad',
            'dialpad_call_id' => 'call-4',
        ]);

        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([
                'items' => [$this->llamadaFake(['call_id' => 'call-4', 'duration' => 600000])],
                'cursor' => null,
            ], 200),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $actividad->refresh();
        $this->assertEquals('Descripción original clasificada', $actividad->descripcion);
        $this->assertEquals(2, $actividad->duracion_minutos);
        $this->assertEquals('Cliente interesado', $actividad->resultado);
    }

    public function test_resync_de_llamada_sin_clasificar_actualiza_descripcion_y_duracion(): void
    {
        $actividad = CrmActividad::create([
            'empresa_id' => $this->enterprise->id,
            'tipo' => 'llamada',
            'vendedor_id' => $this->vendedor->id,
            'descripcion' => 'Llamada entrante de Dialpad (número desconocido) — 2 min',
            'fecha_actividad' => now()->subHour(),
            'duracion_minutos' => 2,
            'fuente' => 'dialpad',
            'dialpad_call_id' => 'call-5',
        ]);

        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([
                'items' => [$this->llamadaFake(['call_id' => 'call-5', 'duration' => 600000])],
                'cursor' => null,
            ], 200),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $actividad->refresh();
        $this->assertEquals(10, $actividad->duracion_minutos);
        $this->assertStringContainsString('10 min', $actividad->descripcion);
    }

    public function test_rate_limit_corta_la_corrida_sin_avanzar_el_cursor(): void
    {
        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([], 429),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $estado = CrmDialpadSyncEstado::obtenerSingleton();
        $this->assertNull($estado->ultimo_sync_at);
        $this->assertNull($estado->ultimo_call_id_sincronizado);
        $this->assertDatabaseCount('crm_actividades', 0);
    }

    public function test_api_key_invalida_falla_el_comando_completo_y_guarda_el_error(): void
    {
        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(1);

        $estado = CrmDialpadSyncEstado::obtenerSingleton();
        $this->assertNotNull($estado->ultimo_error);
    }

    public function test_los_contadores_de_la_corrida_quedan_en_cache_para_el_disparo_manual(): void
    {
        Http::fake([
            'dialpad.test/api/v2/call*' => Http::response([
                'items' => [
                    $this->llamadaFake(['call_id' => 'call-6']),
                    $this->llamadaFake(['call_id' => 'call-7', 'target' => ['email' => 'nadie@example.com']]),
                ],
                'cursor' => null,
            ], 200),
        ]);

        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $contadores = Cache::get(SincronizarDialpadCommand::CACHE_ULTIMA_CORRIDA);
        $this->assertEquals(['sincronizadas' => 1, 'omitidas' => 1], $contadores);
    }
}
```

- [ ] **Step 4: Correr los tests**

```bash
php artisan test --filter=SincronizarDialpadCommandTest
```

Expected: 8 tests, 0 fallos.

- [ ] **Step 5: Verificación post-cambio y commit**

```bash
php artisan route:clear && php artisan config:clear && php artisan view:clear
php -l app/Console/Commands/SincronizarDialpadCommand.php
git add app/Console/Commands/SincronizarDialpadCommand.php routes/console.php \
        tests/Feature/CRM/SincronizarDialpadCommandTest.php
git commit -m "feat(crm): comando de sincronización de llamadas Dialpad"
```

---

### Task 3: Endpoints backend (listar / clasificar / sincronizar / estado)

**Files:**
- Create: `app/Http/Controllers/Api/CRM/DialpadIntegracionController.php`
- Modify: `routes/crm.php` (reemplaza el placeholder de comentario "INTEGRACIONES · Dialpad sync manual" por las 4 rutas)
- Test: `tests/Feature/CRM/DialpadIntegracionControllerTest.php`

**Interfaces:**
- Consumes: `CrmActividad`, `CrmVendedor`, `CrmCliente`, `CrmProspecto`, `CrmContacto` (modelos existentes), `CrmDialpadSyncEstado::obtenerSingleton()` (Task 1), `SincronizarDialpadCommand::CACHE_ULTIMA_CORRIDA` (Task 2), `tienePermisoSubmodulo()` / `getEmpresaId()` (traits ya existentes).
- Produces: `GET /crm/integraciones/dialpad/llamadas` → lista paginada `{data, meta}` de `CrmActividad` con `entidad_tipo` (alias corto) agregado y `vendedor`/`entidad` cargados; `PATCH /crm/integraciones/dialpad/llamadas/{actividad}/clasificar`; `POST /crm/integraciones/dialpad/sincronizar` → `{sincronizadas, omitidas}`; `GET /crm/integraciones/dialpad/estado` → `{ultimoSync, ultimoError}`.

- [ ] **Step 1: Controlador `DialpadIntegracionController`**

```php
<?php
// app/Http/Controllers/Api/CRM/DialpadIntegracionController.php

namespace App\Http\Controllers\Api\CRM;

use App\Console\Commands\SincronizarDialpadCommand;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmDialpadSyncEstado;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Listado/clasificación de llamadas importadas de Dialpad (CrmActividad con
 * fuente='dialpad') + disparo manual del comando de sincronización +
 * consulta de estado. Sin conexión por usuario -- una sola API key global
 * (ver SincronizarDialpadCommand) -- por eso estado() no depende de qué
 * usuario pregunta, solo de que tenga permiso 'ver' en la empresa actual.
 *
 * A diferencia de ActividadController::TIPOS, aquí solo se permite vincular
 * a cliente/prospecto/contacto (no oportunidad/empresa_externa) porque esas
 * son las únicas entidades contra las que el comando de sincronización
 * compara el teléfono del contacto (ver SincronizarDialpadCommand::resolverEntidad()).
 */
class DialpadIntegracionController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    protected const TIPOS = [
        'cliente' => CrmCliente::class,
        'prospecto' => CrmProspecto::class,
        'contacto' => CrmContacto::class,
    ];

    /** GET /crm/integraciones/dialpad/llamadas?vendedor_id=&sin_clasificar=&desde=&hasta= */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'dialpad', 'ver'),
            403,
            'No tienes permiso para ver las llamadas de Dialpad.',
        );

        $validated = $request->validate([
            'vendedor_id' => 'nullable|integer',
            'sin_clasificar' => 'nullable|boolean',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
        ]);

        $vendedorId = $this->resolverFiltroVendedorId(
            $empresaId,
            isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null,
        );

        $query = CrmActividad::with(['vendedor:id,nombre', 'entidad'])
            ->where('empresa_id', $empresaId)
            ->where('fuente', 'dialpad')
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->when($validated['sin_clasificar'] ?? null, fn ($q) => $q->whereNull('entidad_id'))
            ->when($validated['desde'] ?? null, fn ($q, $desde) => $q->where('fecha_actividad', '>=', $desde))
            ->when($validated['hasta'] ?? null, fn ($q, $hasta) => $q->where('fecha_actividad', '<=', $hasta))
            ->orderByDesc('fecha_actividad');

        $perPage = (int) $request->query('per_page', 25);
        $paginated = $query->paginate($perPage);

        $items = collect($paginated->items())->map(function ($a) {
            $a->entidad_tipo = array_search($a->entidad_type, self::TIPOS, true) ?: null;

            return $a;
        });

        return response()->json([
            'success' => true,
            'message' => 'Operación exitosa',
            'data' => $items,
            'meta' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /** PATCH /crm/integraciones/dialpad/llamadas/{actividad}/clasificar */
    public function clasificar(Request $request, CrmActividad $actividad): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'dialpad', 'editar'),
            403,
            'No tienes permiso para clasificar llamadas de Dialpad.',
        );

        if ((int) $actividad->empresa_id !== $empresaId || $actividad->fuente !== 'dialpad') {
            abort(404, 'Llamada no encontrada.');
        }

        $validated = $request->validate([
            'entidad_tipo' => ['nullable', Rule::in(array_keys(self::TIPOS))],
            'entidad_id' => 'nullable|integer',
            'resultado' => 'nullable|string',
        ]);

        $entidadType = null;
        $entidadId = null;

        if (! empty($validated['entidad_tipo'])) {
            abort_unless(! empty($validated['entidad_id']), 422, 'Falta entidad_id.');

            $modelClass = self::TIPOS[$validated['entidad_tipo']];
            $entidad = $modelClass::where('empresa_id', $empresaId)->find($validated['entidad_id']);
            abort_unless($entidad, 404, 'La entidad relacionada no existe o no pertenece a la empresa.');

            $entidadType = $modelClass;
            $entidadId = $validated['entidad_id'];
        }

        $actividad->update([
            'entidad_type' => $entidadType,
            'entidad_id' => $entidadId,
            'resultado' => array_key_exists('resultado', $validated) ? $validated['resultado'] : $actividad->resultado,
        ]);

        $actividad->load(['vendedor:id,nombre', 'entidad']);
        $actividad->entidad_tipo = array_search($actividad->entidad_type, self::TIPOS, true) ?: null;

        return $this->jsonSuccess($actividad, 'Llamada clasificada correctamente');
    }

    /** POST /crm/integraciones/dialpad/sincronizar */
    public function sincronizar(): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'dialpad', 'sync'),
            403,
            'No tienes permiso para sincronizar llamadas de Dialpad.',
        );

        Artisan::call('crm:sincronizar-dialpad');

        $resultado = Cache::get(SincronizarDialpadCommand::CACHE_ULTIMA_CORRIDA, ['sincronizadas' => 0, 'omitidas' => 0]);

        return $this->jsonSuccess($resultado);
    }

    /** GET /crm/integraciones/dialpad/estado */
    public function estado(): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'dialpad', 'ver'),
            403,
            'No tienes permiso para ver el estado de Dialpad.',
        );

        $estado = CrmDialpadSyncEstado::obtenerSingleton();

        return $this->jsonSuccess([
            'ultimoSync' => $estado->ultimo_sync_at?->toIso8601String(),
            'ultimoError' => $estado->ultimo_error,
        ]);
    }

    /**
     * Precedencia por vendedor (mismo patrón que AgendaController /
     * PresupuestoController), adaptada a un filtro OPCIONAL sobre un
     * listado (no a "una sola fila obligatoria"): quien tiene sync o editar
     * es gerencia y puede filtrar por cualquier vendedor o ver todos (null
     * = sin filtro); quien solo tiene ver:
     *   - si no pide ningún vendedor_id, se le fuerza a su propio vendedor
     *     (o -1 si no tiene un vendedor propio vinculado -- un id que
     *     ninguna fila real tendrá nunca, para que la query devuelva vacío
     *     en vez de "todas" por accidente);
     *   - si pide explícitamente el vendedor_id de alguien más, 403.
     */
    private function resolverFiltroVendedorId(int $empresaId, ?int $vendedorIdSolicitado): ?int
    {
        $esGerencia = $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'dialpad', 'sync')
            || $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'dialpad', 'editar');

        if ($esGerencia) {
            return $vendedorIdSolicitado;
        }

        $vendedorPropioId = CrmVendedor::where('empresa_id', $empresaId)
            ->where('user_id', Auth::id())
            ->value('id');

        if ($vendedorIdSolicitado !== null) {
            abort_unless(
                $vendedorPropioId !== null && (int) $vendedorPropioId === $vendedorIdSolicitado,
                403,
                'No puedes ver las llamadas de otro vendedor.',
            );
        }

        return $vendedorPropioId !== null ? (int) $vendedorPropioId : -1;
    }
}
```

- [ ] **Step 2: Rutas (`routes/crm.php`)**

El bloque actual es:

```php
    // -------------------------------------------------
    // INTEGRACIONES
    // Dialpad sync manual
    // -------------------------------------------------

```

Cámbialo por:

```php
    // -------------------------------------------------
    // INTEGRACIONES · DIALPAD
    // Listado/clasificación de llamadas importadas + sync manual bajo
    // demanda. Sin conexión por usuario -- una sola API key global.
    // -------------------------------------------------
    Route::get('integraciones/dialpad/llamadas', [
        App\Http\Controllers\Api\CRM\DialpadIntegracionController::class, 'index'
    ]);
    Route::patch('integraciones/dialpad/llamadas/{actividad}/clasificar', [
        App\Http\Controllers\Api\CRM\DialpadIntegracionController::class, 'clasificar'
    ]);
    Route::post('integraciones/dialpad/sincronizar', [
        App\Http\Controllers\Api\CRM\DialpadIntegracionController::class, 'sincronizar'
    ]);
    Route::get('integraciones/dialpad/estado', [
        App\Http\Controllers\Api\CRM\DialpadIntegracionController::class, 'estado'
    ]);

```

- [ ] **Step 3: Tests**

```php
<?php
// tests/Feature/CRM/DialpadIntegracionControllerTest.php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmDialpadSyncEstado;
use App\Models\CRM\CrmVendedor;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class DialpadIntegracionControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function otorgarPermisoDialpad(array $slugs): void
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $this->enterprise->id, 'slug' => 'crm'],
            ['name' => 'CRM Comercial', 'description' => 'CRM Comercial', 'path' => '/'.$this->enterprise->slug.'/crm', 'is_active' => true],
        );
        $modulo = Module::firstOrCreate(
            ['application_id' => $app->id, 'slug' => 'integraciones'],
            ['name' => 'Integraciones', 'order' => 1, 'is_active' => true],
        );
        $submodulo = Submodule::firstOrCreate(
            ['module_id' => $modulo->id, 'slug' => 'dialpad'],
            ['name' => 'Dialpad', 'order' => 1, 'is_active' => true],
        );

        foreach ($slugs as $i => $slug) {
            $tipo = SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $submodulo->id, 'slug' => $slug],
                ['name' => ucfirst($slug), 'order' => $i + 1, 'is_active' => true],
            );

            UserSubmodulePermission::create([
                'user_id' => $this->actingUser->id,
                'submodule_id' => $submodulo->id,
                'permission_type_id' => $tipo->id,
                'is_granted' => true,
            ]);
        }
    }

    private function crearLlamada(array $overrides = []): CrmActividad
    {
        return CrmActividad::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'tipo' => 'llamada',
            'vendedor_id' => $this->vendedor->id,
            'descripcion' => 'Llamada entrante de Dialpad (6621234567) — 4 min',
            'fecha_actividad' => now(),
            'duracion_minutos' => 4,
            'fuente' => 'dialpad',
            'dialpad_call_id' => 'call-'.uniqid(),
        ], $overrides));
    }

    public function test_listar_sin_permiso_responde_403(): void
    {
        $response = $this->getJson('/api/crm/integraciones/dialpad/llamadas', $this->crmHeaders());
        $response->assertStatus(403);
    }

    public function test_ver_only_sin_vendedor_id_queda_forzado_a_su_propio_vendedor(): void
    {
        $this->otorgarPermisoDialpad(['ver']);

        // Vincula el vendedor de fixtures al usuario autenticado para que
        // "su propio vendedor" sea $this->vendedor.
        $this->vendedor->update(['user_id' => $this->actingUser->id]);

        $propia = $this->crearLlamada(['vendedor_id' => $this->vendedor->id]);
        $otroVendedor = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Otro vendedor',
            'email' => 'otro@example.com',
        ]);
        $this->crearLlamada(['vendedor_id' => $otroVendedor->id]);

        $response = $this->getJson('/api/crm/integraciones/dialpad/llamadas', $this->crmHeaders());

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $propia->id);
    }

    public function test_ver_only_pidiendo_el_vendedor_de_otro_responde_403(): void
    {
        $this->otorgarPermisoDialpad(['ver']);
        $this->vendedor->update(['user_id' => $this->actingUser->id]);

        $otroVendedor = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Otro vendedor',
            'email' => 'otro@example.com',
        ]);

        $response = $this->getJson(
            '/api/crm/integraciones/dialpad/llamadas?vendedor_id='.$otroVendedor->id,
            $this->crmHeaders(),
        );

        $response->assertStatus(403);
    }

    public function test_gerencia_ve_las_llamadas_de_todos_los_vendedores(): void
    {
        $this->otorgarPermisoDialpad(['sync', 'ver', 'editar']);

        $otroVendedor = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Otro vendedor',
            'email' => 'otro@example.com',
        ]);
        $this->crearLlamada(['vendedor_id' => $this->vendedor->id]);
        $this->crearLlamada(['vendedor_id' => $otroVendedor->id]);

        $response = $this->getJson('/api/crm/integraciones/dialpad/llamadas', $this->crmHeaders());

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_clasificar_actualiza_entidad_y_resultado(): void
    {
        $this->otorgarPermisoDialpad(['editar']);
        $llamada = $this->crearLlamada();
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Cliente Fake',
            'telefono' => '6621234567',
            'estatus' => 'activo',
        ]);

        $response = $this->patchJson(
            "/api/crm/integraciones/dialpad/llamadas/{$llamada->id}/clasificar",
            ['entidad_tipo' => 'cliente', 'entidad_id' => $cliente->id, 'resultado' => 'Interesado'],
            $this->crmHeaders(),
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('crm_actividades', [
            'id' => $llamada->id,
            'entidad_type' => CrmCliente::class,
            'entidad_id' => $cliente->id,
            'resultado' => 'Interesado',
        ]);
    }

    public function test_clasificar_sin_permiso_editar_responde_403(): void
    {
        $llamada = $this->crearLlamada();

        $response = $this->patchJson(
            "/api/crm/integraciones/dialpad/llamadas/{$llamada->id}/clasificar",
            ['resultado' => 'Interesado'],
            $this->crmHeaders(),
        );

        $response->assertStatus(403);
    }

    public function test_sincronizar_sin_permiso_sync_responde_403(): void
    {
        $response = $this->postJson('/api/crm/integraciones/dialpad/sincronizar', [], $this->crmHeaders());
        $response->assertStatus(403);
    }

    public function test_estado_devuelve_la_forma_correcta(): void
    {
        $this->otorgarPermisoDialpad(['ver']);
        CrmDialpadSyncEstado::obtenerSingleton()->update([
            'ultimo_sync_at' => now(),
            'ultimo_error' => null,
        ]);

        $response = $this->getJson('/api/crm/integraciones/dialpad/estado', $this->crmHeaders());

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['ultimoSync', 'ultimoError']]);
    }
}
```

- [ ] **Step 4: Correr los tests**

```bash
php artisan test --filter=DialpadIntegracionControllerTest
```

Expected: 8 tests, 0 fallos.

- [ ] **Step 5: Verificación post-cambio y commit**

```bash
php artisan route:clear && php artisan config:clear && php artisan view:clear
php -l app/Http/Controllers/Api/CRM/DialpadIntegracionController.php
git add app/Http/Controllers/Api/CRM/DialpadIntegracionController.php routes/crm.php \
        tests/Feature/CRM/DialpadIntegracionControllerTest.php
git commit -m "feat(crm): endpoints de listar/clasificar/sincronizar/estado para Dialpad"
```

---

### Task 4: Frontend — vista, modal de clasificación y registro en ModuleLoader

> Este task vive en el repo `sentinel-front` (worktree separado del backend). Los 3 tasks anteriores viven en `sentinel-back`.

**Files:**
- Modify: `src/index.css` (agrega los tokens `--color-crm-integraciones-dialpad-start`/`-end`)
- Create: `src/hooks/crm/integraciones/useDialpadLlamadas.js`
- Modify: `src/hooks/crm/integraciones/index.js` (agrega el export nombrado)
- Create: `src/components/crm/integraciones/DialpadClasificarModal.jsx`
- Create: `src/components/crm/integraciones/index.js`
- Create: `src/views/crm/integraciones/dialpad/DialpadView.jsx`
- Create: `src/views/crm/integraciones/dialpad/index.js`
- Modify: `src/components/workspace/ModuleLoader.jsx` (registra `crm/integraciones/dialpad`)

**Interfaces:**
- Consumes: `GET/PATCH/POST /crm/integraciones/dialpad/*` (Task 3), `useCrmContext()`, `useCrmCatalogos({cargarVendedores:true})`, `useWorkspace().hasSubmodulePermission(submoduleId, slug)`, `SearchableSelect`/`Card`/`DynamicIcon`/`LoadingScreen` de `components/sistema`.
- Produces: hook `useDialpadLlamadas()` → `{llamadas, loading, error, estado, fetchLlamadas, fetchEstado, clasificar, sincronizar}`; componentes `DialpadView`, `DialpadClasificarModal`.

- [ ] **Step 1: Tokens de color**

En `src/index.css`, el bloque actual termina en:

```css
  /* Integraciones · Outlook: identidad de marca de Microsoft Outlook
     (azul Office) — distingue esta conexión de los demás módulos CRM. */
  --color-crm-integraciones-start: #0078D4;
  --color-crm-integraciones-end: #106EBE;
}
```

Agrega, antes del cierre `}`:

```css
  /* Integraciones · Outlook: identidad de marca de Microsoft Outlook
     (azul Office) — distingue esta conexión de los demás módulos CRM. */
  --color-crm-integraciones-start: #0078D4;
  --color-crm-integraciones-end: #106EBE;

  /* Integraciones · Dialpad: identidad de marca de Dialpad (verde-teal) --
     distingue esta conexión de Outlook (azul) dentro del mismo módulo
     Integraciones. */
  --color-crm-integraciones-dialpad-start: #00C48C;
  --color-crm-integraciones-dialpad-end: #00A876;
}
```

- [ ] **Step 2: Hook `useDialpadLlamadas`**

```javascript
// src/hooks/crm/integraciones/useDialpadLlamadas.js
import { useState, useCallback } from "react";
import { fetchAPI } from "../../../services/api";
import { useCrmContext } from "../useCrmContext";

/**
 * useDialpadLlamadas
 * Listado/clasificación de llamadas importadas de Dialpad (CrmActividad con
 * fuente='dialpad') + estado de sincronización + disparo manual.
 * Endpoints:
 *   GET   /api/crm/integraciones/dialpad/llamadas?vendedor_id=&sin_clasificar=&desde=&hasta=
 *   PATCH /api/crm/integraciones/dialpad/llamadas/{id}/clasificar
 *   POST  /api/crm/integraciones/dialpad/sincronizar
 *   GET   /api/crm/integraciones/dialpad/estado
 */
export const useDialpadLlamadas = () => {
  const [llamadas, setLlamadas] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [estado, setEstado] = useState({ ultimoSync: null, ultimoError: null });

  const { getBaseUrl, getContextHeaders } = useCrmContext();
  const BASE_URL = getBaseUrl("integraciones/dialpad");
  const contextHeaders = getContextHeaders("integraciones", "dialpad");

  const fetchLlamadas = useCallback(
    async ({ vendedorId, sinClasificar, desde, hasta } = {}) => {
      try {
        setLoading(true);
        setError(null);

        const query = {};
        if (vendedorId != null && vendedorId !== "") query.vendedor_id = vendedorId;
        if (sinClasificar) query.sin_clasificar = 1;
        if (desde) query.desde = desde;
        if (hasta) query.hasta = hasta;

        const qs = new URLSearchParams(query).toString();
        const response = await fetchAPI(`${BASE_URL}/llamadas${qs ? `?${qs}` : ""}`, {
          headers: contextHeaders,
        });
        setLlamadas(response.data || []);
        return response;
      } catch (err) {
        console.error("Error al cargar las llamadas de Dialpad:", err);
        setError(err.message);
        setLlamadas([]);
        return null;
      } finally {
        setLoading(false);
      }
      // eslint-disable-next-line react-hooks/exhaustive-deps
    },
    [BASE_URL],
  );

  const fetchEstado = useCallback(async () => {
    try {
      const response = await fetchAPI(`${BASE_URL}/estado`, { headers: contextHeaders });
      setEstado(response.data || { ultimoSync: null, ultimoError: null });
      return response;
    } catch (err) {
      console.error("Error al obtener el estado de Dialpad:", err);
      return null;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [BASE_URL]);

  const clasificar = useCallback(
    async (actividadId, payload) => {
      const response = await fetchAPI(`${BASE_URL}/llamadas/${actividadId}/clasificar`, {
        method: "PATCH",
        headers: contextHeaders,
        body: JSON.stringify(payload),
      });
      setLlamadas((prev) =>
        prev.map((l) => (String(l.id) === String(actividadId) ? response.data : l)),
      );
      return response;
      // eslint-disable-next-line react-hooks/exhaustive-deps
    },
    [BASE_URL],
  );

  const sincronizar = useCallback(async () => {
    const response = await fetchAPI(`${BASE_URL}/sincronizar`, {
      method: "POST",
      headers: contextHeaders,
    });
    await fetchEstado();
    return response;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [BASE_URL, fetchEstado]);

  return { llamadas, loading, error, estado, fetchLlamadas, fetchEstado, clasificar, sincronizar };
};

export default useDialpadLlamadas;
```

- [ ] **Step 3: Registrar el hook en el índice**

El archivo `src/hooks/crm/integraciones/index.js` actual es:

```javascript
export { useOutlookIntegracion, default } from "./useOutlookIntegracion";
```

Cámbialo por (se agrega la nueva línea; la existente NO se toca, para no romper el `default` que ya expone Outlook):

```javascript
export { useOutlookIntegracion, default } from "./useOutlookIntegracion";
export { useDialpadLlamadas } from "./useDialpadLlamadas";
```

- [ ] **Step 4: `DialpadClasificarModal`**

```jsx
// src/components/crm/integraciones/DialpadClasificarModal.jsx
import { useState, useEffect, useMemo } from "react";
import { createPortal } from "react-dom";
import { motion, AnimatePresence } from "framer-motion";
import { X, Loader2 } from "lucide-react";

import { fetchAPI } from "../../../services/api";
import { useCrmContext } from "../../../hooks/crm/useCrmContext";
import { SearchableSelect } from "../../sistema";

const ACCENT = "#00A876";

/**
 * Mapa propio (no el de components/crm/agenda/tipos.js): las opciones
 * válidas para clasificar una llamada de Dialpad son otras --
 * cliente/prospecto/contacto, sin oportunidad/empresa_externa -- mismo
 * orden de prioridad que usa el comando de sincronización para matchear
 * por teléfono. Reutilizar el mapa de Agenda agregaría "Contacto" también
 * al selector de Agenda, un efecto colateral fuera de alcance.
 */
const ENDPOINT_POR_ENTIDAD_DIALPAD = {
  cliente: "clientes",
  prospecto: "prospectos",
  contacto: "contactos",
};

const LABEL_POR_ENTIDAD_DIALPAD = {
  cliente: "Cliente",
  prospecto: "Prospecto",
  contacto: "Contacto",
};

export const DialpadClasificarModal = ({ isOpen, onClose, onSave, llamada }) => {
  const { getContextHeaders } = useCrmContext();

  const [entidadTipo, setEntidadTipo] = useState("");
  const [entidadId, setEntidadId] = useState("");
  const [resultado, setResultado] = useState("");
  const [opcionesEntidad, setOpcionesEntidad] = useState([]);
  const [cargandoEntidades, setCargandoEntidades] = useState(false);
  const [guardando, setGuardando] = useState(false);

  useEffect(() => {
    if (!isOpen) return;
    setEntidadTipo(llamada?.entidadTipo || "");
    setEntidadId(llamada?.entidadId ? String(llamada.entidadId) : "");
    setResultado(llamada?.resultado || "");
  }, [isOpen, llamada]);

  useEffect(() => {
    if (!entidadTipo) {
      setOpcionesEntidad([]);
      return;
    }
    const endpoint = ENDPOINT_POR_ENTIDAD_DIALPAD[entidadTipo];
    if (!endpoint) return;

    setCargandoEntidades(true);
    fetchAPI(`/crm/${endpoint}?per_page=100`, {
      headers: getContextHeaders("integraciones", "dialpad"),
    })
      .then((r) => setOpcionesEntidad(r?.data || []))
      .catch(() => setOpcionesEntidad([]))
      .finally(() => setCargandoEntidades(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [entidadTipo]);

  const opcionesEntidadSelect = useMemo(
    () => opcionesEntidad.map((e) => ({ value: e.id, label: e.nombre || `#${e.id}` })),
    [opcionesEntidad],
  );

  const opcionesTipoEntidad = useMemo(
    () =>
      Object.keys(ENDPOINT_POR_ENTIDAD_DIALPAD).map((k) => ({
        value: k,
        label: LABEL_POR_ENTIDAD_DIALPAD[k],
      })),
    [],
  );

  const handleSubmit = async (e) => {
    e.preventDefault();
    setGuardando(true);
    try {
      await onSave({
        entidad_tipo: entidadTipo || null,
        entidad_id: entidadTipo ? Number(entidadId) || null : null,
        resultado: resultado || null,
      });
    } finally {
      setGuardando(false);
    }
  };

  if (!isOpen) return null;

  return createPortal(
    <AnimatePresence>
      <div className='fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm'>
        <motion.div
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          exit={{ opacity: 0, scale: 0.95 }}
          className='bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col max-h-[90vh]'
        >
          <div className='flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700 bg-linear-to-r from-crm-integraciones-dialpad-start to-crm-integraciones-dialpad-end'>
            <h2 className='text-2xl font-bold text-white'>Clasificar llamada</h2>
            <button
              onClick={onClose}
              className='p-2 hover:bg-white/20 rounded-lg transition-colors text-white'
              aria-label='Cerrar'
            >
              <X className='w-6 h-6' />
            </button>
          </div>

          <form onSubmit={handleSubmit} className='p-6 space-y-5 overflow-y-auto flex-1'>
            <div className='grid grid-cols-1 md:grid-cols-2 gap-4'>
              <div>
                <label className='block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2'>
                  Vincular con
                </label>
                <SearchableSelect
                  options={opcionesTipoEntidad}
                  value={entidadTipo}
                  onChange={(value) => {
                    setEntidadTipo(value || "");
                    setEntidadId("");
                  }}
                  placeholder='Tipo de entidad...'
                  isClearable
                  accent={ACCENT}
                />
              </div>
              <div>
                <label className='block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2'>
                  &nbsp;
                </label>
                <SearchableSelect
                  options={opcionesEntidadSelect}
                  value={entidadId}
                  onChange={(value) => setEntidadId(value)}
                  placeholder='Busca...'
                  isDisabled={!entidadTipo}
                  isLoading={cargandoEntidades}
                  accent={ACCENT}
                />
              </div>
            </div>

            <div>
              <label className='block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2'>
                Resultado (opcional)
              </label>
              <textarea
                rows={3}
                value={resultado}
                onChange={(e) => setResultado(e.target.value)}
                placeholder='Ej: Cliente interesado, dar seguimiento la próxima semana...'
                className='w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-transparent dark:bg-gray-700 dark:text-white resize-none'
              />
            </div>
          </form>

          <div className='flex items-center justify-end gap-3 p-6 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/50'>
            <button
              type='button'
              onClick={onClose}
              disabled={guardando}
              className='px-6 py-2.5 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors font-medium disabled:opacity-50'
            >
              Cancelar
            </button>
            <button
              onClick={handleSubmit}
              disabled={guardando}
              className='inline-flex items-center gap-2 px-6 py-2.5 bg-linear-to-r from-crm-integraciones-dialpad-start to-crm-integraciones-dialpad-end text-white rounded-lg transition-all font-medium disabled:opacity-50 shadow-lg shadow-emerald-600/20'
            >
              {guardando && <Loader2 className='w-4 h-4 animate-spin' />}
              {guardando ? "Guardando..." : "Guardar"}
            </button>
          </div>
        </motion.div>
      </div>
    </AnimatePresence>,
    document.body,
  );
};

export default DialpadClasificarModal;
```

```javascript
// src/components/crm/integraciones/index.js
export { DialpadClasificarModal, default } from "./DialpadClasificarModal";
```

- [ ] **Step 5: `DialpadView`**

```jsx
// src/views/crm/integraciones/dialpad/DialpadView.jsx
import { useState, useEffect, useCallback, useMemo } from "react";
import { motion } from "framer-motion";
import {
  Phone,
  PhoneIncoming,
  PhoneOutgoing,
  RefreshCw,
  AlertTriangle,
  CheckCircle2,
  Tag,
} from "lucide-react";

import { useDialpadLlamadas } from "../../../../hooks/crm/integraciones";
import { useCrmCatalogos } from "../../../../hooks/crm/useCrmCatalogos";
import { useWorkspace } from "../../../../contexts/WorkspaceContext";
import { useAlert } from "../../../../contexts/AlertContext";
import { Card, SearchableSelect, DynamicIcon, LoadingScreen } from "../../../../components/sistema";
import { DialpadClasificarModal } from "../../../../components/crm/integraciones";

const ACCENT = "#00A876";

/**
 * El backend embebe el teléfono del contacto entre paréntesis dentro de
 * `descripcion` (ver SincronizarDialpadCommand::procesarLlamada -- no hay
 * columna de teléfono en crm_actividades). Cuando la llamada SÍ tiene una
 * entidad vinculada se usa su nombre; si no, se extrae el teléfono de ahí.
 */
const extraerTelefono = (descripcion) => {
  const match = /\(([^)]+)\)/.exec(descripcion || "");
  return match?.[1] || "Número desconocido";
};

export function DialpadView() {
  const { submodule, hasSubmodulePermission } = useWorkspace();
  const alert = useAlert();

  const puedeSync = hasSubmodulePermission(submodule?.id, "sync");
  const puedeEditar = hasSubmodulePermission(submodule?.id, "editar");
  const puedeElegirVendedor = puedeSync || puedeEditar;

  const { llamadas, loading, error, estado, fetchLlamadas, fetchEstado, clasificar, sincronizar } =
    useDialpadLlamadas();
  const { vendedores, loading: catalogosLoading } = useCrmCatalogos({
    cargarVendedores: true,
    cargarRegiones: false,
  });

  const [vendedorId, setVendedorId] = useState("");
  const [sinClasificar, setSinClasificar] = useState(false);
  const [desde, setDesde] = useState("");
  const [hasta, setHasta] = useState("");
  const [sincronizando, setSincronizando] = useState(false);
  const [modalOpen, setModalOpen] = useState(false);
  const [seleccionada, setSeleccionada] = useState(null);

  const cargar = useCallback(() => {
    fetchLlamadas({ vendedorId: vendedorId || undefined, sinClasificar, desde, hasta });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [vendedorId, sinClasificar, desde, hasta]);

  useEffect(() => {
    cargar();
  }, [cargar]);

  useEffect(() => {
    fetchEstado();
  }, [fetchEstado]);

  const opcionesVendedor = useMemo(
    () => vendedores.map((v) => ({ value: v.id, label: v.nombre })),
    [vendedores],
  );

  const handleSincronizar = async () => {
    try {
      setSincronizando(true);
      const response = await sincronizar();
      const { sincronizadas = 0, omitidas = 0 } = response?.data || {};
      alert.success(`${sincronizadas} sincronizadas, ${omitidas} omitidas.`);
      cargar();
    } catch (err) {
      console.error("Error al sincronizar Dialpad:", err);
      alert.error(err.message || "No se pudo sincronizar con Dialpad.");
    } finally {
      setSincronizando(false);
    }
  };

  const handleClasificar = (llamada) => {
    setSeleccionada(llamada);
    setModalOpen(true);
  };

  const handleGuardarClasificacion = async (payload) => {
    try {
      await clasificar(seleccionada.id, payload);
      alert.success("Llamada clasificada correctamente");
      setModalOpen(false);
    } catch (err) {
      alert.error(err.message || "Error al clasificar la llamada");
      throw err;
    }
  };

  if ((loading && llamadas.length === 0 && !error) || catalogosLoading) {
    return <LoadingScreen message='Cargando llamadas de Dialpad...' />;
  }

  return (
    <div className='p-4 md:p-6 space-y-6'>
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        className='flex flex-col md:flex-row md:items-center md:justify-between gap-4'
      >
        <div className='flex items-center gap-4'>
          <div className='p-3 bg-linear-to-br from-crm-integraciones-dialpad-start to-crm-integraciones-dialpad-end rounded-xl shadow-lg shadow-crm-integraciones-dialpad-start/25'>
            <DynamicIcon name={submodule?.icon || "Phone"} className='w-8 h-8 text-white' />
          </div>
          <div>
            <h1 className='text-2xl font-bold text-gray-900 dark:text-white'>
              {submodule?.name || "Dialpad"}
            </h1>
            <p className='text-gray-500 dark:text-gray-400 mt-1'>
              Llamadas importadas automáticamente desde Dialpad.
            </p>
          </div>
        </div>

        {puedeSync && (
          <button
            onClick={handleSincronizar}
            disabled={sincronizando}
            className='inline-flex items-center gap-2 px-4 py-2.5 text-sm bg-linear-to-r from-crm-integraciones-dialpad-start to-crm-integraciones-dialpad-end text-white rounded-xl shadow-lg shadow-crm-integraciones-dialpad-start/25 disabled:opacity-50'
          >
            <RefreshCw className={`w-4 h-4 ${sincronizando ? "animate-spin" : ""}`} />
            {sincronizando ? "Sincronizando..." : "Sincronizar"}
          </button>
        )}
      </motion.div>

      {(estado.ultimoSync || estado.ultimoError) && (
        <div className='flex flex-col md:flex-row gap-3'>
          {estado.ultimoSync && (
            <div className='flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400'>
              <CheckCircle2 className='w-4 h-4 text-green-600 dark:text-green-400' />
              Última sincronización: {new Date(estado.ultimoSync).toLocaleString("es-MX")}
            </div>
          )}
          {estado.ultimoError && (
            <div className='flex items-start gap-2 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-3'>
              <AlertTriangle className='w-5 h-5 text-red-600 dark:text-red-400 shrink-0 mt-0.5' />
              <p className='text-sm text-red-700 dark:text-red-300'>{estado.ultimoError}</p>
            </div>
          )}
        </div>
      )}

      {error && (
        <div className='p-4 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm'>
          {error}
        </div>
      )}

      <Card className='p-4'>
        <div className='flex flex-col md:flex-row gap-3 md:items-center md:flex-wrap'>
          {puedeElegirVendedor && (
            <div className='md:w-64'>
              <SearchableSelect
                options={opcionesVendedor}
                value={vendedorId}
                onChange={(value) => setVendedorId(value || "")}
                placeholder='Todos los vendedores'
                isClearable
                accent={ACCENT}
              />
            </div>
          )}
          <label className='flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300'>
            <input
              type='checkbox'
              checked={sinClasificar}
              onChange={(e) => setSinClasificar(e.target.checked)}
              className='rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500'
            />
            Sin clasificar
          </label>
          <input
            type='date'
            value={desde}
            onChange={(e) => setDesde(e.target.value)}
            className='px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm'
          />
          <input
            type='date'
            value={hasta}
            onChange={(e) => setHasta(e.target.value)}
            className='px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm'
          />
        </div>
      </Card>

      <Card className='p-4'>
        {llamadas.length === 0 ? (
          <div className='p-12 text-center'>
            <Phone className='w-12 h-12 text-gray-300 dark:text-gray-600 mx-auto mb-4' />
            <h3 className='text-lg font-medium text-gray-500 dark:text-gray-400'>
              No hay llamadas de Dialpad en este rango
            </h3>
          </div>
        ) : (
          <div className='divide-y divide-gray-100 dark:divide-gray-700'>
            {llamadas.map((llamada) => {
              const esSaliente = (llamada.descripcion || "").toLowerCase().includes("saliente");
              const DireccionIcon = esSaliente ? PhoneOutgoing : PhoneIncoming;
              const contacto = llamada.entidad?.nombre || extraerTelefono(llamada.descripcion);

              return (
                <div key={llamada.id} className='flex items-center justify-between gap-4 py-3 flex-wrap'>
                  <div className='flex items-center gap-3 min-w-0'>
                    <DireccionIcon
                      className={`w-5 h-5 shrink-0 ${esSaliente ? "text-blue-500" : "text-emerald-500"}`}
                    />
                    <div className='min-w-0'>
                      <p className='font-medium text-gray-900 dark:text-white truncate'>{contacto}</p>
                      <p className='text-sm text-gray-500 dark:text-gray-400'>
                        {llamada.vendedor?.nombre || "—"} ·{" "}
                        {llamada.duracionMinutos != null ? `${llamada.duracionMinutos} min` : "—"} ·{" "}
                        {new Date(llamada.fechaActividad).toLocaleString("es-MX")}
                      </p>
                    </div>
                  </div>
                  {puedeEditar && (
                    <button
                      onClick={() => handleClasificar(llamada)}
                      className='inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-emerald-300 dark:border-emerald-800 text-emerald-700 dark:text-emerald-400 rounded-lg hover:bg-emerald-50 dark:hover:bg-emerald-900/20 transition-colors shrink-0'
                    >
                      <Tag className='w-4 h-4' />
                      Clasificar
                    </button>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </Card>

      <DialpadClasificarModal
        isOpen={modalOpen}
        onClose={() => setModalOpen(false)}
        onSave={handleGuardarClasificacion}
        llamada={seleccionada}
      />
    </div>
  );
}

export default DialpadView;
```

```javascript
// src/views/crm/integraciones/dialpad/index.js
export { default as DialpadView } from "./DialpadView";
```

- [ ] **Step 6: Registrar en `ModuleLoader.jsx`**

En `src/components/workspace/ModuleLoader.jsx`, busca el bloque que termina así (registra `${emp}/crm/integraciones/outlook`):

```javascript
  // CRM · Integraciones · Outlook (conectar cuenta, sync unidireccional de Agenda)
  ...Object.fromEntries(
    [
      "grupoesplendido",
      "splendidfarms",
      "splendidbyporvenir",
      "splendid-logistic",
      "canes-agro",
    ].map((emp) => [
      `${emp}/crm/integraciones/outlook`,
      lazy(() =>
        import("../../views/crm/integraciones/outlook").then((module) => ({
          default: module.OutlookView,
        })),
      ),
    ]),
  ),
```

Justo después del `),` que cierra ese bloque (antes del siguiente comentario, `// CRM · Catálogos ...`), agrega:

```javascript
  // CRM · Integraciones · Dialpad (sync + clasificación de llamadas)
  ...Object.fromEntries(
    [
      "grupoesplendido",
      "splendidfarms",
      "splendidbyporvenir",
      "splendid-logistic",
      "canes-agro",
    ].map((emp) => [
      `${emp}/crm/integraciones/dialpad`,
      lazy(() =>
        import("../../views/crm/integraciones/dialpad").then((module) => ({
          default: module.DialpadView,
        })),
      ),
    ]),
  ),
```

- [ ] **Step 7: Verificación post-cambio**

```bash
npm run build
npm run lint
```

Expected: build sin errores; lint sin errores nuevos (los falsos positivos conocidos de `no-unused-vars` en JSX ya documentados en `CLAUDE.md` son aceptables).

- [ ] **Step 8: Verificación manual en navegador**

No hay forma de simular la API real de Dialpad sin credenciales reales (igual que Outlook) — verificar manualmente que:
1. El submódulo `Integraciones > Dialpad` aparece en el sidebar para un usuario con permiso `ver` otorgado (submodule access + permission type, ambos, vía el panel de administración de permisos).
2. La vista carga sin error (lista vacía, estado sin sincronizar).
3. El botón "Sincronizar" (si el usuario tiene `sync`) llama al endpoint y muestra el toast, aunque falle por falta de `CRM_DIALPAD_API_KEY` real en dev — el error debe mostrarse con `alert.error()`, nunca un throw sin capturar.

- [ ] **Step 9: Commit**

```bash
git add src/index.css src/hooks/crm/integraciones/useDialpadLlamadas.js \
        src/hooks/crm/integraciones/index.js \
        src/components/crm/integraciones/DialpadClasificarModal.jsx \
        src/components/crm/integraciones/index.js \
        src/views/crm/integraciones/dialpad/DialpadView.jsx \
        src/views/crm/integraciones/dialpad/index.js \
        src/components/workspace/ModuleLoader.jsx
git commit -m "feat(crm): vista y modal de clasificación para Integraciones > Dialpad"
```

---

## Nota para quien ejecute este plan

Tasks 1-3 viven en el repo `sentinel-back`; Task 4 vive en `sentinel-front`. Si se ejecuta con `subagent-driven-development`, cada repo necesita su propio worktree (ver `superpowers:using-git-worktrees`), igual que se hizo para el plan de Agenda↔Outlook. Task 4 depende de que Task 3 ya esté mergeada/disponible (los endpoints que consume el hook), pero puede implementarse y buildearse de forma aislada — el `npm run build`/`npm run lint` de Task 4 no requiere que el backend esté corriendo.
