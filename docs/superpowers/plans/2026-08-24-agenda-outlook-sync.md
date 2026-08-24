# CRM Agenda ↔ Outlook: Sincronización Unidireccional (Backend) — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** cada vendedor conecta su cuenta de Outlook (Microsoft 365) desde el submódulo Integraciones del CRM, y un comando programado empuja hacia su calendario de Outlook los eventos/tareas no completados de su Agenda — nunca al revés.

**Architecture:** dos tablas nuevas (`crm_outlook_conexiones`, `crm_outlook_eventos_mapeados`); un controlador de 4 endpoints para el flujo OAuth (conectar/callback/estado/desconectar) vía `laravel/socialite` + `socialiteproviders/microsoft`; un comando programado (`agenda:sincronizar-outlook`, cada 5 min) que hace el trabajo real de push vía Microsoft Graph API con `Http::fake()`-testeable HTTP puro (sin SDK de Graph). La identidad del usuario en el callback OAuth se resuelve con un nonce propio guardado en `Cache` (no con sesión/cookie ni con Socialite `state`), porque el frontend es un SPA autenticado por Bearer token en `localStorage` — una navegación de página completa hacia Microsoft y de regreso nunca lleva ese header.

**Tech Stack:** Laravel 12 (PHP 8.2), `laravel/socialite` + `socialiteproviders/microsoft` (nuevas dependencias de este plan), `Illuminate\Support\Facades\Http` para Microsoft Graph API, cast `encrypted` de Eloquent.

**Spec:** `docs/superpowers/specs/2026-08-24-agenda-outlook-sync-design.md` (vive en el repo `sentinel-front`, ya que ahí se escribió — el contenido aplica igual a este repo backend).

## Global Constraints

- Sincronización **estrictamente unidireccional**: Sentinel → Outlook. Ningún código de este plan lee el calendario de Outlook del vendedor ni importa eventos desde allá.
- Cada usuario solo conecta/desconecta **su propia** cuenta — no existe "actuar sobre el vendedor de otro" en este subsistema. Los 4 endpoints exigen únicamente el permiso `ver` del submódulo `integraciones`/`outlook` (`tienePermisoSubmodulo($empresaId, 'integraciones', 'outlook', 'ver')`).
- `access_token`/`refresh_token` se guardan con cast `encrypted` de Eloquent; nunca se exponen en una respuesta JSON al frontend (van en `$hidden` del modelo).
- El callback OAuth (`GET /crm/integraciones/outlook/callback`) es una ruta **pública** (fuera de `auth:sanctum`, en `routes/api.php`) — Microsoft lo invoca sin ningún header de autenticación nuestro. La identidad se resuelve leyendo el nonce propio del parámetro `state` desde `Cache`, nunca desde `Auth::user()`.
- Un evento completado (`completado = true`) deja de aparecer en las consultas de sincronización y su espejo en Outlook queda intacto — nunca se cancela ni se toca.
- El borrado de un evento de Outlook (cuando se borró en Sentinel) se detecta comparando mapeos huérfanos (`crm_agenda_id IS NULL`, no una fila de mapeo eliminada por cascada) — esto exige que la FK `crm_outlook_eventos_mapeados.crm_agenda_id` sea `nullOnDelete()`, nunca `cascadeOnDelete()`. Si cascadeara, el comando jamás tendría oportunidad de llamar al `DELETE` de Graph API antes de que la fila de mapeo desapareciera, dejando el evento huérfano en Outlook para siempre.
- Todo fallo por conexión (token inválido, error de red, excepción inesperada) se aísla: nunca detiene el procesamiento de las demás conexiones en el mismo lote del comando. Todo fallo por evento individual dentro de una misma conexión también se aísla de la misma forma (mismo criterio que `EnviarRecordatoriosAgendaCommand`).
- Después de cada cambio de código: `php artisan route:clear && php artisan config:clear && php artisan view:clear`, y `php -l` sobre el archivo modificado. Nunca correr `php artisan test`/`migrate` con `--env=`.

---

### Task 1: Esquema, modelos y permisos

**Files:**
- Create: `database/migrations/2026_08_24_000000_create_crm_outlook_conexiones_table.php`
- Create: `database/migrations/2026_08_24_000001_create_crm_outlook_eventos_mapeados_table.php`
- Create: `app/Models/CRM/CrmOutlookConexion.php`
- Create: `app/Models/CRM/CrmOutlookEventoMapeado.php`
- Modify: `app/Models/CRM/CrmVendedor.php` (agrega relación `outlookConexion()`)
- Modify: `app/Models/CRM/CrmAgenda.php` (agrega relación `outlookMapeo()`)
- Modify: `app/Enums/CrmPermiso.php` (agrega el case del nuevo permiso)
- Modify: `database/seeders/CrmPermisosSeeder.php` (agrega el submódulo `outlook` bajo el módulo `integraciones`)
- Test: `tests/Feature/CRM/CrmOutlookConexionTest.php`

**Interfaces:**
- Produces: `CrmOutlookConexion` (tabla `crm_outlook_conexiones`, casts `access_token`/`refresh_token` → `encrypted`, `token_expires_at`/`ultimo_sync_at` → `datetime`), `CrmOutlookEventoMapeado` (tabla `crm_outlook_eventos_mapeados`, cast `ultima_actualizacion_enviada_at` → `datetime`), relación `CrmVendedor::outlookConexion(): HasOne`, relación `CrmAgenda::outlookMapeo(): HasOne`. Permiso `tienePermisoSubmodulo($empresaId, 'integraciones', 'outlook', 'ver')` resoluble tras correr el seeder.

- [ ] **Step 1: Migración de `crm_outlook_conexiones`**

```php
<?php
// database/migrations/2026_08_24_000000_create_crm_outlook_conexiones_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_outlook_conexiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            // unique: un vendedor solo puede tener una conexión activa a la vez.
            $table->foreignId('crm_vendedor_id')->unique()->constrained('crm_vendedores')->onDelete('cascade');
            $table->string('email_outlook');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->datetime('token_expires_at');
            $table->datetime('ultimo_sync_at')->nullable();
            $table->text('ultimo_error')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_outlook_conexiones');
    }
};
```

- [ ] **Step 2: Migración de `crm_outlook_eventos_mapeados`**

```php
<?php
// database/migrations/2026_08_24_000001_create_crm_outlook_eventos_mapeados_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_outlook_eventos_mapeados', function (Blueprint $table) {
            $table->id();
            // nullable + nullOnDelete (NO cascade): cuando se borra el evento
            // de Agenda, el mapeo debe SOBREVIVIR con crm_agenda_id = null
            // para que SincronizarOutlookCommand::borrarEliminados() pueda
            // detectarlo y borrar el evento espejo en Outlook antes de
            // borrar la fila de mapeo él mismo. Ver Global Constraints.
            $table->foreignId('crm_agenda_id')->nullable()->constrained('crm_agenda')->nullOnDelete();
            $table->foreignId('crm_outlook_conexion_id')->constrained('crm_outlook_conexiones')->onDelete('cascade');
            $table->string('outlook_event_id');
            $table->datetime('ultima_actualizacion_enviada_at');
            $table->timestamps();

            // unique permite múltiples NULL en MySQL (no se consideran
            // iguales entre sí), así que no choca con eventos ya borrados.
            $table->unique('crm_agenda_id');
            $table->index('crm_outlook_conexion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_outlook_eventos_mapeados');
    }
};
```

- [ ] **Step 3: Correr las migraciones**

```bash
php artisan migrate
```

Expected: ambas tablas se crean sin error.

- [ ] **Step 4: Modelo `CrmOutlookConexion`**

```php
<?php
// app/Models/CRM/CrmOutlookConexion.php

namespace App\Models\CRM;

use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una fila por vendedor con su cuenta de Outlook conectada. access_token y
 * refresh_token nunca deben llegar al frontend -- van en $hidden además del
 * cast encrypted (defensa en profundidad si algún día alguien serializa el
 * modelo completo por error).
 */
class CrmOutlookConexion extends Model
{
    use HasFactory;

    protected $table = 'crm_outlook_conexiones';

    protected $fillable = [
        'empresa_id',
        'crm_vendedor_id',
        'email_outlook',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'ultimo_sync_at',
        'ultimo_error',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'access_token'     => 'encrypted',
        'refresh_token'    => 'encrypted',
        'token_expires_at' => 'datetime',
        'ultimo_sync_at'   => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(CrmVendedor::class, 'crm_vendedor_id');
    }

    public function eventosMapeados(): HasMany
    {
        return $this->hasMany(CrmOutlookEventoMapeado::class, 'crm_outlook_conexion_id');
    }
}
```

- [ ] **Step 5: Modelo `CrmOutlookEventoMapeado`**

```php
<?php
// app/Models/CRM/CrmOutlookEventoMapeado.php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmOutlookEventoMapeado extends Model
{
    use HasFactory;

    protected $table = 'crm_outlook_eventos_mapeados';

    protected $fillable = [
        'crm_agenda_id',
        'crm_outlook_conexion_id',
        'outlook_event_id',
        'ultima_actualizacion_enviada_at',
    ];

    protected $casts = [
        'ultima_actualizacion_enviada_at' => 'datetime',
    ];

    public function agenda(): BelongsTo
    {
        return $this->belongsTo(CrmAgenda::class, 'crm_agenda_id');
    }

    public function conexion(): BelongsTo
    {
        return $this->belongsTo(CrmOutlookConexion::class, 'crm_outlook_conexion_id');
    }
}
```

- [ ] **Step 6: Relación en `CrmVendedor`**

Modifica `app/Models/CRM/CrmVendedor.php`: agrega el import `use Illuminate\Database\Eloquent\Relations\HasOne;` junto a los demás imports de `Relations\*`, y agrega este método junto a las demás relaciones (después de `agenda()`):

```php
    public function outlookConexion(): HasOne
    {
        return $this->hasOne(CrmOutlookConexion::class, 'crm_vendedor_id');
    }
```

- [ ] **Step 7: Relación en `CrmAgenda`**

Modifica `app/Models/CRM/CrmAgenda.php`: agrega el import `use Illuminate\Database\Eloquent\Relations\HasOne;` junto a los demás imports de `Relations\*`, y agrega este método junto a las demás relaciones (después de `entidad()`):

```php
    public function outlookMapeo(): HasOne
    {
        return $this->hasOne(CrmOutlookEventoMapeado::class, 'crm_agenda_id');
    }
```

- [ ] **Step 8: Enum `CrmPermiso`**

En `app/Enums/CrmPermiso.php`, agrega el nuevo case junto al de Dialpad:

```php
    // --- Integraciones ---
    case INTEGRACIONES_DIALPAD = 'crm.integraciones.dialpad';
    case INTEGRACIONES_OUTLOOK_VER = 'crm.integraciones.outlook.ver';
```

- [ ] **Step 9: Seeder — nuevo submódulo `outlook`**

En `database/seeders/CrmPermisosSeeder.php`, el bloque actual es:

```php
        $this->crearModulo($app, 'integraciones', 'Integraciones', 'Plug', 11, [
            ['slug' => 'dialpad', 'name' => 'Dialpad', 'icon' => 'Phone', 'order' => 1, 'permisos' => [
                ['slug' => 'sync',   'name' => 'Sincronizar llamadas', 'order' => 1],
                ['slug' => 'ver',    'name' => 'Ver llamadas',         'order' => 2],
                ['slug' => 'editar', 'name' => 'Clasificar llamadas',  'order' => 3],
            ]],
        ]);
```

Cámbialo por (agrega el submódulo `outlook` a la misma lista):

```php
        $this->crearModulo($app, 'integraciones', 'Integraciones', 'Plug', 11, [
            ['slug' => 'dialpad', 'name' => 'Dialpad', 'icon' => 'Phone', 'order' => 1, 'permisos' => [
                ['slug' => 'sync',   'name' => 'Sincronizar llamadas', 'order' => 1],
                ['slug' => 'ver',    'name' => 'Ver llamadas',         'order' => 2],
                ['slug' => 'editar', 'name' => 'Clasificar llamadas',  'order' => 3],
            ]],
            ['slug' => 'outlook', 'name' => 'Outlook', 'icon' => 'Calendar', 'order' => 2, 'permisos' => [
                ['slug' => 'ver', 'name' => 'Conectar y ver estado de Outlook', 'order' => 1],
            ]],
        ]);
```

- [ ] **Step 10: Correr el seeder y verificar**

```bash
php artisan db:seed --class=CrmPermisosSeeder
```

Expected: corre sin error (usa `firstOrCreate`/`updateOrCreate`, es idempotente).

- [ ] **Step 11: Escribir los tests**

```php
<?php
// tests/Feature/CRM/CrmOutlookConexionTest.php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmOutlookConexion;
use App\Models\CRM\CrmOutlookEventoMapeado;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class CrmOutlookConexionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    private function crearConexion(): CrmOutlookConexion
    {
        return CrmOutlookConexion::create([
            'empresa_id' => $this->enterprise->id,
            'crm_vendedor_id' => $this->vendedor->id,
            'email_outlook' => 'vendedor@outlook.com',
            'access_token' => 'token-de-acceso-plano',
            'refresh_token' => 'token-de-refresco-plano',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function crearEventoAgenda(): CrmAgenda
    {
        return CrmAgenda::create([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'tipo' => 'tarea',
            'titulo' => 'Evento de prueba',
            'fecha_inicio' => now()->addDay(),
            'fecha_fin' => now()->addDay()->addHour(),
        ]);
    }

    public function test_access_token_y_refresh_token_se_guardan_cifrados_en_la_bd(): void
    {
        $conexion = $this->crearConexion();

        $crudo = DB::table('crm_outlook_conexiones')->where('id', $conexion->id)->first();
        $this->assertNotEquals('token-de-acceso-plano', $crudo->access_token);
        $this->assertNotEquals('token-de-refresco-plano', $crudo->refresh_token);

        $conexion->refresh();
        $this->assertEquals('token-de-acceso-plano', $conexion->access_token);
        $this->assertEquals('token-de-refresco-plano', $conexion->refresh_token);
    }

    public function test_access_token_no_aparece_al_serializar_el_modelo(): void
    {
        $conexion = $this->crearConexion();
        $array = $conexion->toArray();

        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('refresh_token', $array);
    }

    public function test_seeder_crea_el_submodulo_outlook_con_permiso_ver(): void
    {
        $this->seed(CrmPermisosSeeder::class);

        $modulo = Module::where('slug', 'integraciones')->first();
        $this->assertNotNull($modulo, 'El módulo integraciones debe existir (ya lo crea Dialpad).');

        $submodulo = Submodule::where('module_id', $modulo->id)->where('slug', 'outlook')->first();
        $this->assertNotNull($submodulo);

        $permiso = SubmodulePermissionType::where('submodule_id', $submodulo->id)->where('slug', 'ver')->first();
        $this->assertNotNull($permiso);
    }

    public function test_borrar_la_conexion_borra_en_cascada_sus_eventos_mapeados(): void
    {
        $conexion = $this->crearConexion();
        $evento = $this->crearEventoAgenda();

        $mapeo = CrmOutlookEventoMapeado::create([
            'crm_agenda_id' => $evento->id,
            'crm_outlook_conexion_id' => $conexion->id,
            'outlook_event_id' => 'AAMkAGI1',
            'ultima_actualizacion_enviada_at' => now(),
        ]);

        $conexion->delete();

        $this->assertDatabaseMissing('crm_outlook_eventos_mapeados', ['id' => $mapeo->id]);
    }

    public function test_borrar_el_evento_de_agenda_deja_el_mapeo_vivo_con_crm_agenda_id_nulo(): void
    {
        $conexion = $this->crearConexion();
        $evento = $this->crearEventoAgenda();

        $mapeo = CrmOutlookEventoMapeado::create([
            'crm_agenda_id' => $evento->id,
            'crm_outlook_conexion_id' => $conexion->id,
            'outlook_event_id' => 'AAMkAGI1',
            'ultima_actualizacion_enviada_at' => now(),
        ]);

        $evento->delete();
        $mapeo->refresh();

        $this->assertNull($mapeo->crm_agenda_id);
        $this->assertDatabaseHas('crm_outlook_eventos_mapeados', ['id' => $mapeo->id]);
    }
}
```

- [ ] **Step 12: Correr los tests**

```bash
php artisan test --filter=CrmOutlookConexionTest
```

Expected: 5 tests, 0 fallos.

- [ ] **Step 13: Verificación post-cambio y commit**

```bash
php artisan route:clear && php artisan config:clear && php artisan view:clear
php -l app/Models/CRM/CrmOutlookConexion.php
php -l app/Models/CRM/CrmOutlookEventoMapeado.php
php -l app/Models/CRM/CrmVendedor.php
php -l app/Models/CRM/CrmAgenda.php
php -l database/seeders/CrmPermisosSeeder.php
git add database/migrations/2026_08_24_000000_create_crm_outlook_conexiones_table.php \
        database/migrations/2026_08_24_000001_create_crm_outlook_eventos_mapeados_table.php \
        app/Models/CRM/CrmOutlookConexion.php app/Models/CRM/CrmOutlookEventoMapeado.php \
        app/Models/CRM/CrmVendedor.php app/Models/CRM/CrmAgenda.php \
        app/Enums/CrmPermiso.php database/seeders/CrmPermisosSeeder.php \
        tests/Feature/CRM/CrmOutlookConexionTest.php
git commit -m "feat(crm): esquema, modelos y permisos para Outlook (Agenda sync)"
```

---

### Task 2: Flujo OAuth (conectar / callback / estado / desconectar)

**Files:**
- Create: `app/Http/Controllers/Api/CRM/OutlookIntegracionController.php`
- Modify: `app/Providers/AppServiceProvider.php` (registra el driver `microsoft` de Socialite)
- Modify: `config/services.php` (agrega la clave `microsoft`)
- Modify: `.env.example` (documenta las variables nuevas)
- Modify: `routes/crm.php` (agrega `estado`/`conectar`/`desconectar`, dentro de `auth:sanctum`)
- Modify: `routes/api.php` (agrega `callback`, **fuera** de `auth:sanctum`)
- Test: `tests/Feature/CRM/OutlookIntegracionControllerTest.php`

**Interfaces:**
- Consumes: `CrmOutlookConexion` (Task 1), `tienePermisoSubmodulo()` (`App\Traits\CRM\VerificaPermisoSubmodulo`), `getEmpresaId()` (`App\Traits\CRM\FiltraPorEmpresa`), `jsonSuccess()` (`CrmBaseController`).
- Produces: `GET /crm/integraciones/outlook/estado` → `{conectado, email, ultimoSync, ultimoError}`; `GET /crm/integraciones/outlook/conectar` → `{url}`; `GET /crm/integraciones/outlook/callback` (público, redirige al frontend); `DELETE /crm/integraciones/outlook/desconectar`.

- [ ] **Step 1: Instalar las dependencias**

```bash
composer require laravel/socialite socialiteproviders/microsoft
```

Expected: se instalan sin conflicto de versiones (ambos soportan Laravel 12 / PHP 8.2).

- [ ] **Step 2: Registrar el driver de Socialite**

Reemplaza el contenido de `app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider as MicrosoftSocialiteProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // socialiteproviders/microsoft no se auto-registra: hay que
        // enganchar el driver 'microsoft' al evento que dispara Socialite
        // la primera vez que se le pide un driver que no conoce nativamente.
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('microsoft', MicrosoftSocialiteProvider::class);
        });
    }
}
```

- [ ] **Step 3: Config `services.php`**

Agrega en `config/services.php`, antes del cierre `];` final:

```php
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        // URL base del frontend a donde el callback redirige de vuelta tras
        // el consentimiento de Microsoft (ver OutlookIntegracionController).
        'frontend_url' => env('FRONTEND_URL', env('APP_URL')),
    ],
```

- [ ] **Step 4: Documentar las variables de entorno**

Agrega en `.env.example`, después de `APP_URL=http://localhost`:

```
FRONTEND_URL=http://localhost:5173

# Azure AD App Registration (ver docs/superpowers/specs/2026-08-24-agenda-outlook-sync-design.md
# Sección 2) -- permisos delegados Calendars.ReadWrite + offline_access.
# MICROSOFT_REDIRECT_URI debe coincidir EXACTAMENTE con el Redirect URI
# configurado en el App Registration de Azure.
MICROSOFT_CLIENT_ID=
MICROSOFT_CLIENT_SECRET=
MICROSOFT_REDIRECT_URI=http://localhost:8000/api/crm/integraciones/outlook/callback
```

- [ ] **Step 5: Controlador `OutlookIntegracionController`**

```php
<?php
// app/Http/Controllers/Api/CRM/OutlookIntegracionController.php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmOutlookConexion;
use App\Models\CRM\CrmVendedor;
use App\Models\Enterprise;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * Conectar/desconectar la cuenta de Outlook de un vendedor para la
 * sincronización unidireccional Agenda -> Outlook (el trabajo real de push
 * lo hace SincronizarOutlookCommand -- este controlador solo administra la
 * conexión).
 *
 * A diferencia del resto del CRM, aquí no existe "actuar sobre el vendedor
 * de otro": cada usuario solo conecta su propia cuenta, así que los 4
 * endpoints solo exigen el permiso 'ver' del submódulo integraciones/outlook.
 *
 * Nota de arquitectura -- por qué un nonce propio y no el state/sesión de
 * Socialite: el frontend autentica con un Bearer token guardado en
 * localStorage (no hay cookie de sesión), así que una navegación de página
 * completa hacia Microsoft y de regreso NUNCA lleva el header Authorization.
 * Por eso:
 *   1. conectar() SÍ es un endpoint autenticado normal (llamado por
 *      fetchAPI con el Bearer token) que solo devuelve la URL de
 *      consentimiento como JSON -- la navegación real la hace el frontend
 *      con window.location.href.
 *   2. Esa URL lleva como parámetro `state` un nonce propio (no el de
 *      Socialite), generado aquí y guardado en Cache junto con el
 *      user_id/empresa_id, con TTL corto.
 *   3. callback() es una ruta PÚBLICA (routes/api.php, fuera de
 *      auth:sanctum) porque Microsoft redirige sin ningún Bearer token.
 *      Resuelve la identidad leyendo el nonce del Cache, nunca de
 *      Auth::user().
 */
class OutlookIntegracionController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const CACHE_PREFIX = 'outlook_connect_nonce:';
    private const NONCE_TTL_MINUTOS = 10;

    private const SCOPES = [
        'openid', 'profile', 'email', 'offline_access',
        'https://graph.microsoft.com/Calendars.ReadWrite',
    ];

    /** GET /crm/integraciones/outlook/estado */
    public function estado(): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'outlook', 'ver'),
            403,
            'No tienes permiso para ver la integración con Outlook.',
        );

        $conexion = $this->conexionDelUsuarioActual($empresaId);

        return $this->jsonSuccess([
            'conectado' => (bool) $conexion,
            'email' => $conexion?->email_outlook,
            'ultimoSync' => $conexion?->ultimo_sync_at?->toIso8601String(),
            'ultimoError' => $conexion?->ultimo_error,
        ]);
    }

    /** GET /crm/integraciones/outlook/conectar */
    public function conectar(): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'outlook', 'ver'),
            403,
            'No tienes permiso para conectar Outlook.',
        );

        $vendedor = CrmVendedor::where('empresa_id', $empresaId)
            ->where('user_id', Auth::id())
            ->first();

        abort_unless($vendedor, 422, 'No tienes un perfil de vendedor en esta empresa; no hay nada que conectar.');

        $nonce = Str::random(40);
        Cache::put(self::CACHE_PREFIX.$nonce, [
            'user_id' => Auth::id(),
            'empresa_id' => $empresaId,
        ], now()->addMinutes(self::NONCE_TTL_MINUTOS));

        $url = Socialite::driver('microsoft')
            ->stateless()
            ->scopes(self::SCOPES)
            ->with(['state' => $nonce])
            ->redirect()
            ->getTargetUrl();

        return $this->jsonSuccess(['url' => $url]);
    }

    /** GET /crm/integraciones/outlook/callback -- ruta pública, ver routes/api.php */
    public function callback(Request $request): RedirectResponse
    {
        $nonce = $request->query('state');
        $contexto = $nonce ? Cache::pull(self::CACHE_PREFIX.$nonce) : null;

        if (! $contexto) {
            return $this->redirigirAlFrontend(null, 'error');
        }

        try {
            $microsoftUser = Socialite::driver('microsoft')->stateless()->user();
        } catch (\Throwable) {
            return $this->redirigirAlFrontend($contexto['empresa_id'] ?? null, 'error');
        }

        $vendedor = CrmVendedor::where('empresa_id', $contexto['empresa_id'])
            ->where('user_id', $contexto['user_id'])
            ->first();

        if (! $vendedor) {
            return $this->redirigirAlFrontend($contexto['empresa_id'], 'error');
        }

        CrmOutlookConexion::updateOrCreate(
            ['crm_vendedor_id' => $vendedor->id],
            [
                'empresa_id' => $contexto['empresa_id'],
                'email_outlook' => $microsoftUser->getEmail(),
                'access_token' => $microsoftUser->token,
                'refresh_token' => $microsoftUser->refreshToken,
                'token_expires_at' => now()->addSeconds($microsoftUser->expiresIn ?? 3600),
            ],
        );

        return $this->redirigirAlFrontend($contexto['empresa_id'], 'ok');
    }

    /** DELETE /crm/integraciones/outlook/desconectar */
    public function desconectar(): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'outlook', 'ver'),
            403,
            'No tienes permiso para desconectar Outlook.',
        );

        $conexion = $this->conexionDelUsuarioActual($empresaId);
        $conexion?->delete();

        return $this->jsonSuccess(null, 'Cuenta de Outlook desconectada.');
    }

    private function conexionDelUsuarioActual(int $empresaId): ?CrmOutlookConexion
    {
        $vendedor = CrmVendedor::where('empresa_id', $empresaId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $vendedor) {
            return null;
        }

        return CrmOutlookConexion::where('crm_vendedor_id', $vendedor->id)->first();
    }

    private function redirigirAlFrontend(?int $empresaId, string $resultado): RedirectResponse
    {
        $frontendUrl = rtrim(config('services.microsoft.frontend_url'), '/');
        $enterpriseSlug = $empresaId ? Enterprise::where('id', $empresaId)->value('slug') : null;

        if (! $enterpriseSlug) {
            return redirect()->away("{$frontendUrl}/inicio?outlook={$resultado}");
        }

        return redirect()->away("{$frontendUrl}/{$enterpriseSlug}/crm/integraciones/outlook?outlook={$resultado}");
    }
}
```

- [ ] **Step 6: Rutas autenticadas (`routes/crm.php`)**

Agrega, dentro del `Route::middleware('auth:sanctum')->prefix('crm')->group(...)` ya existente, junto al bloque de AGENDA:

```php
    // -------------------------------------------------
    // INTEGRACIONES · OUTLOOK
    // Conectar/desconectar la cuenta de Outlook propia y consultar su
    // estado. El callback público vive en routes/api.php, FUERA de
    // auth:sanctum -- ver nota en OutlookIntegracionController::callback().
    // -------------------------------------------------
    Route::get('integraciones/outlook/estado', [
        App\Http\Controllers\Api\CRM\OutlookIntegracionController::class, 'estado'
    ]);
    Route::get('integraciones/outlook/conectar', [
        App\Http\Controllers\Api\CRM\OutlookIntegracionController::class, 'conectar'
    ]);
    Route::delete('integraciones/outlook/desconectar', [
        App\Http\Controllers\Api\CRM\OutlookIntegracionController::class, 'desconectar'
    ]);
```

- [ ] **Step 7: Ruta pública del callback (`routes/api.php`)**

En `routes/api.php`, justo antes de `require __DIR__.'/crm.php';`, agrega:

```php
// Callback público de OAuth Outlook -- Microsoft redirige aquí sin ningún
// Bearer token, así que esta ruta vive fuera de auth:sanctum. La identidad
// se resuelve por el nonce propio del parámetro `state`, no por Auth::user()
// -- ver OutlookIntegracionController::callback().
Route::get('crm/integraciones/outlook/callback', [
    App\Http\Controllers\Api\CRM\OutlookIntegracionController::class, 'callback'
]);

require __DIR__.'/crm.php';
```

- [ ] **Step 8: Tests**

```php
<?php
// tests/Feature/CRM/OutlookIntegracionControllerTest.php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\CRM\CrmOutlookConexion;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class OutlookIntegracionControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    /** Crea el árbol Application/Module/Submodule/PermissionType y otorga 'ver' al actingUser. */
    private function otorgarPermisoOutlook(): void
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
            ['module_id' => $modulo->id, 'slug' => 'outlook'],
            ['name' => 'Outlook', 'order' => 1, 'is_active' => true],
        );
        $tipo = SubmodulePermissionType::firstOrCreate(
            ['submodule_id' => $submodulo->id, 'slug' => 'ver'],
            ['name' => 'Ver', 'order' => 1, 'is_active' => true],
        );

        UserSubmodulePermission::create([
            'user_id' => $this->actingUser->id,
            'submodule_id' => $submodulo->id,
            'permission_type_id' => $tipo->id,
            'is_granted' => true,
        ]);
    }

    private function crearVendedorPropio(): \App\Models\CRM\CrmVendedor
    {
        return \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor propio',
        ]);
    }

    public function test_estado_sin_permiso_responde_403(): void
    {
        $response = $this->getJson('/api/crm/integraciones/outlook/estado', $this->crmHeaders());
        $response->assertStatus(403);
    }

    public function test_estado_sin_conexion_reporta_no_conectado(): void
    {
        $this->otorgarPermisoOutlook();
        $this->crearVendedorPropio();

        $response = $this->getJson('/api/crm/integraciones/outlook/estado', $this->crmHeaders());

        $response->assertStatus(200);
        $response->assertJsonPath('data.conectado', false);
    }

    public function test_conectar_sin_vendedor_propio_responde_422(): void
    {
        $this->otorgarPermisoOutlook();

        $response = $this->getJson('/api/crm/integraciones/outlook/conectar', $this->crmHeaders());

        $response->assertStatus(422);
    }

    public function test_conectar_devuelve_la_url_de_consentimiento_y_guarda_el_nonce(): void
    {
        $this->otorgarPermisoOutlook();
        $this->crearVendedorPropio();

        $providerMock = \Mockery::mock(AbstractProvider::class);
        $providerMock->shouldReceive('stateless')->once()->andReturnSelf();
        $providerMock->shouldReceive('scopes')->once()->andReturnSelf();
        $providerMock->shouldReceive('with')->once()->andReturnSelf();
        $providerMock->shouldReceive('redirect')->once()->andReturn(
            new RedirectResponse('https://login.microsoftonline.com/fake-consent-url')
        );
        Socialite::shouldReceive('driver')->once()->with('microsoft')->andReturn($providerMock);

        $response = $this->getJson('/api/crm/integraciones/outlook/conectar', $this->crmHeaders());

        $response->assertStatus(200);
        $response->assertJsonPath('data.url', 'https://login.microsoftonline.com/fake-consent-url');
    }

    public function test_callback_con_nonce_valido_crea_la_conexion_y_redirige_a_ok(): void
    {
        $vendedor = $this->crearVendedorPropio();

        $nonce = 'nonce-de-prueba';
        Cache::put('outlook_connect_nonce:'.$nonce, [
            'user_id' => $this->actingUser->id,
            'empresa_id' => $this->enterprise->id,
        ], now()->addMinutes(10));

        $socialiteUser = new SocialiteUser();
        $socialiteUser->token = 'access-token-fake';
        $socialiteUser->refreshToken = 'refresh-token-fake';
        $socialiteUser->expiresIn = 3600;
        $socialiteUser->email = 'vendedor@outlook.com';

        $providerMock = \Mockery::mock(AbstractProvider::class);
        $providerMock->shouldReceive('stateless')->once()->andReturnSelf();
        $providerMock->shouldReceive('user')->once()->andReturn($socialiteUser);
        Socialite::shouldReceive('driver')->once()->with('microsoft')->andReturn($providerMock);

        // Ruta pública -- request sin Sanctum::actingAs (Auth::user() no se usa aquí).
        $response = $this->get('/api/crm/integraciones/outlook/callback?state='.$nonce);

        $response->assertRedirect();
        $this->assertStringContainsString('outlook=ok', $response->headers->get('Location'));
        $this->assertDatabaseHas('crm_outlook_conexiones', [
            'crm_vendedor_id' => $vendedor->id,
            'email_outlook' => 'vendedor@outlook.com',
        ]);

        // El nonce se consume: una segunda llamada con el mismo state ya no encuentra contexto.
        $this->assertNull(Cache::get('outlook_connect_nonce:'.$nonce));
    }

    public function test_callback_con_nonce_invalido_redirige_a_error_sin_crear_nada(): void
    {
        $response = $this->get('/api/crm/integraciones/outlook/callback?state=nonce-que-no-existe');

        $response->assertRedirect();
        $this->assertStringContainsString('outlook=error', $response->headers->get('Location'));
        $this->assertDatabaseCount('crm_outlook_conexiones', 0);
    }

    public function test_desconectar_borra_la_conexion_del_usuario_actual(): void
    {
        $this->otorgarPermisoOutlook();
        $vendedor = $this->crearVendedorPropio();

        CrmOutlookConexion::create([
            'empresa_id' => $this->enterprise->id,
            'crm_vendedor_id' => $vendedor->id,
            'email_outlook' => 'vendedor@outlook.com',
            'access_token' => 'a',
            'refresh_token' => 'b',
            'token_expires_at' => now()->addHour(),
        ]);

        $response = $this->deleteJson('/api/crm/integraciones/outlook/desconectar', [], $this->crmHeaders());

        $response->assertStatus(200);
        $this->assertDatabaseCount('crm_outlook_conexiones', 0);
    }
}
```

- [ ] **Step 9: Correr los tests**

```bash
php artisan test --filter=OutlookIntegracionControllerTest
```

Expected: 7 tests, 0 fallos.

- [ ] **Step 10: Verificación post-cambio y commit**

```bash
php artisan route:clear && php artisan config:clear && php artisan view:clear
php -l app/Http/Controllers/Api/CRM/OutlookIntegracionController.php
php -l app/Providers/AppServiceProvider.php
git add composer.json composer.lock app/Http/Controllers/Api/CRM/OutlookIntegracionController.php \
        app/Providers/AppServiceProvider.php config/services.php .env.example \
        routes/crm.php routes/api.php tests/Feature/CRM/OutlookIntegracionControllerTest.php
git commit -m "feat(crm): flujo OAuth conectar/callback/estado/desconectar para Outlook"
```

---

### Task 3: Comando programado de sincronización

**Files:**
- Create: `app/Console/Commands/SincronizarOutlookCommand.php`
- Modify: `routes/console.php` (agrega el `Schedule::command(...)`)
- Test: `tests/Feature/CRM/SincronizarOutlookCommandTest.php`

**Interfaces:**
- Consumes: `CrmOutlookConexion`, `CrmOutlookEventoMapeado`, `CrmAgenda::outlookMapeo()` (Task 1).
- Produces: comando `agenda:sincronizar-outlook`, ejecutable vía `php artisan agenda:sincronizar-outlook` y programado cada 5 minutos.

- [ ] **Step 1: El comando**

```php
<?php
// app/Console/Commands/SincronizarOutlookCommand.php

namespace App\Console\Commands;

use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmOutlookConexion;
use App\Models\CRM\CrmOutlookEventoMapeado;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Empuja hacia el calendario de Outlook de cada vendedor conectado los
 * eventos/tareas de Agenda pendientes (unidireccional: Sentinel -> Outlook,
 * jamás al revés). Un fallo en una conexión, o en un evento puntual dentro
 * de una conexión, nunca detiene el resto del lote (mismo criterio que
 * EnviarRecordatoriosAgendaCommand).
 *
 * Deliberadamente NO toca AgendaController::destroy() ni ningún endpoint ya
 * construido -- solo LEE el estado resultante de crm_agenda, manteniendo la
 * integración completamente desacoplada del código de Agenda ya probado.
 */
class SincronizarOutlookCommand extends Command
{
    protected $signature = 'agenda:sincronizar-outlook';

    protected $description = 'Empuja los eventos de Agenda pendientes hacia el calendario de Outlook de cada vendedor conectado (unidireccional)';

    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    public function handle(): int
    {
        $conexiones = CrmOutlookConexion::all();
        $procesadas = 0;

        foreach ($conexiones as $conexion) {
            try {
                if (! $this->asegurarTokenVigente($conexion)) {
                    continue; // el error ya quedó guardado en ultimo_error dentro del helper
                }

                $this->crearYActualizar($conexion);
                $this->borrarEliminados($conexion);

                $conexion->update(['ultimo_sync_at' => now(), 'ultimo_error' => null]);
                $procesadas++;
            } catch (\Throwable $e) {
                Log::error("Error al sincronizar Outlook para conexión #{$conexion->id}: {$e->getMessage()}", ['exception' => $e]);
                $conexion->update(['ultimo_error' => $e->getMessage()]);
            }
        }

        $this->info("Conexiones de Outlook sincronizadas: {$procesadas}/{$conexiones->count()}");

        return self::SUCCESS;
    }

    private function asegurarTokenVigente(CrmOutlookConexion $conexion): bool
    {
        if ($conexion->token_expires_at->subMinutes(5)->isFuture()) {
            return true;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $conexion->refresh_token,
            'scope' => 'https://graph.microsoft.com/Calendars.ReadWrite offline_access',
        ]);

        if (! $response->successful()) {
            Log::error("No se pudo refrescar el token de Outlook para conexión #{$conexion->id}: {$response->body()}");
            $conexion->update(['ultimo_error' => 'No se pudo refrescar el token de acceso. Reconecta tu cuenta de Outlook.']);

            return false;
        }

        $data = $response->json();
        $conexion->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $conexion->refresh_token,
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return true;
    }

    private function crearYActualizar(CrmOutlookConexion $conexion): void
    {
        $eventos = CrmAgenda::where('empresa_id', $conexion->empresa_id)
            ->where('vendedor_id', $conexion->crm_vendedor_id)
            ->where('completado', false)
            ->where('fecha_inicio', '>=', now())
            ->with('outlookMapeo')
            ->get();

        foreach ($eventos as $evento) {
            try {
                $mapeo = $evento->outlookMapeo;

                // Nota: EnviarRecordatoriosAgendaCommand también hace un
                // update() sobre este mismo evento al marcar el recordatorio
                // como enviado, lo que bumpea updated_at aunque nada visible
                // haya cambiado -- esto puede disparar un PATCH redundante
                // (mismo contenido) en la siguiente corrida. Aceptado: es
                // inofensivo, solo reenvía el mismo contenido.
                if ($mapeo && $mapeo->ultima_actualizacion_enviada_at->gte($evento->updated_at)) {
                    continue;
                }

                $payload = [
                    'subject' => $evento->titulo,
                    'body' => ['contentType' => 'text', 'content' => $evento->descripcion ?? ''],
                    'start' => ['dateTime' => $evento->fecha_inicio->toIso8601String(), 'timeZone' => 'America/Mazatlan'],
                    'end' => ['dateTime' => $evento->fecha_fin->toIso8601String(), 'timeZone' => 'America/Mazatlan'],
                ];

                if ($mapeo) {
                    $response = $this->graphRequest($conexion)->patch(self::GRAPH_BASE."/me/events/{$mapeo->outlook_event_id}", $payload);
                    if ($this->esRateLimit($response)) {
                        continue;
                    }
                    $response->throw();
                    $mapeo->update(['ultima_actualizacion_enviada_at' => now()]);
                } else {
                    $response = $this->graphRequest($conexion)->post(self::GRAPH_BASE.'/me/events', $payload);
                    if ($this->esRateLimit($response)) {
                        continue;
                    }
                    $response->throw();

                    CrmOutlookEventoMapeado::create([
                        'crm_agenda_id' => $evento->id,
                        'crm_outlook_conexion_id' => $conexion->id,
                        'outlook_event_id' => $response->json('id'),
                        'ultima_actualizacion_enviada_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error("Error al sincronizar el evento de agenda #{$evento->id} hacia Outlook: {$e->getMessage()}", ['exception' => $e]);
            }
        }
    }

    private function borrarEliminados(CrmOutlookConexion $conexion): void
    {
        $mapeos = CrmOutlookEventoMapeado::where('crm_outlook_conexion_id', $conexion->id)
            ->whereNull('crm_agenda_id')
            ->get();

        foreach ($mapeos as $mapeo) {
            try {
                $response = $this->graphRequest($conexion)->delete(self::GRAPH_BASE."/me/events/{$mapeo->outlook_event_id}");
                if ($this->esRateLimit($response)) {
                    continue;
                }
                // 404 = ya no existe del lado de Outlook (el usuario lo
                // borró manualmente allá) -- se trata igual que un borrado
                // exitoso, se limpia el mapeo.
                if (! $response->successful() && $response->status() !== 404) {
                    $response->throw();
                }
                $mapeo->delete();
            } catch (\Throwable $e) {
                Log::error("Error al borrar el evento de Outlook {$mapeo->outlook_event_id}: {$e->getMessage()}", ['exception' => $e]);
            }
        }
    }

    private function graphRequest(CrmOutlookConexion $conexion)
    {
        return Http::withToken($conexion->access_token)->acceptJson();
    }

    private function esRateLimit(Response $response): bool
    {
        if ($response->status() === 429) {
            Log::warning('Rate limit de Microsoft Graph alcanzado, se reintenta en la siguiente corrida.');

            return true;
        }

        return false;
    }
}
```

- [ ] **Step 2: Programar el comando**

En `routes/console.php`, agrega junto a los demás `Schedule::command(...)` (después de `agenda:enviar-recordatorios`):

```php
Schedule::command('agenda:sincronizar-outlook')->everyFiveMinutes();
```

- [ ] **Step 3: Tests**

```php
<?php
// tests/Feature/CRM/SincronizarOutlookCommandTest.php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmOutlookConexion;
use App\Models\CRM\CrmOutlookEventoMapeado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class SincronizarOutlookCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    private function crearConexion(array $overrides = []): CrmOutlookConexion
    {
        return CrmOutlookConexion::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'crm_vendedor_id' => $this->vendedor->id,
            'email_outlook' => 'vendedor@outlook.com',
            'access_token' => 'access-token-vigente',
            'refresh_token' => 'refresh-token-vigente',
            'token_expires_at' => now()->addHour(),
        ], $overrides));
    }

    private function crearEvento(array $overrides = []): CrmAgenda
    {
        return CrmAgenda::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'tipo' => 'tarea',
            'titulo' => 'Llamar a cliente',
            'fecha_inicio' => now()->addDay(),
            'fecha_fin' => now()->addDay()->addHour(),
            'completado' => false,
        ], $overrides));
    }

    public function test_evento_nuevo_se_crea_en_outlook(): void
    {
        $conexion = $this->crearConexion();
        $evento = $this->crearEvento();

        Http::fake([
            'graph.microsoft.com/v1.0/me/events' => Http::response(['id' => 'outlook-evt-1'], 201),
        ]);

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        $this->assertDatabaseHas('crm_outlook_eventos_mapeados', [
            'crm_agenda_id' => $evento->id,
            'crm_outlook_conexion_id' => $conexion->id,
            'outlook_event_id' => 'outlook-evt-1',
        ]);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/me/events'));
    }

    public function test_evento_editado_se_actualiza_con_patch(): void
    {
        $conexion = $this->crearConexion();
        $evento = $this->crearEvento();

        CrmOutlookEventoMapeado::create([
            'crm_agenda_id' => $evento->id,
            'crm_outlook_conexion_id' => $conexion->id,
            'outlook_event_id' => 'outlook-evt-1',
            'ultima_actualizacion_enviada_at' => now()->subDay(), // anterior a updated_at del evento
        ]);

        Http::fake([
            'graph.microsoft.com/v1.0/me/events/outlook-evt-1' => Http::response(['id' => 'outlook-evt-1'], 200),
        ]);

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_contains($request->url(), '/me/events/outlook-evt-1'));
    }

    public function test_evento_borrado_en_sentinel_se_borra_en_outlook_y_el_mapeo_desaparece(): void
    {
        $conexion = $this->crearConexion();
        $evento = $this->crearEvento();

        $mapeo = CrmOutlookEventoMapeado::create([
            'crm_agenda_id' => $evento->id,
            'crm_outlook_conexion_id' => $conexion->id,
            'outlook_event_id' => 'outlook-evt-1',
            'ultima_actualizacion_enviada_at' => now(),
        ]);

        $evento->delete(); // nullOnDelete deja crm_agenda_id = null en el mapeo

        Http::fake([
            'graph.microsoft.com/v1.0/me/events/outlook-evt-1' => Http::response([], 204),
        ]);

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        $this->assertDatabaseMissing('crm_outlook_eventos_mapeados', ['id' => $mapeo->id]);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), '/me/events/outlook-evt-1'));
    }

    public function test_evento_completado_no_genera_ninguna_llamada_a_graph(): void
    {
        $this->crearConexion();
        $this->crearEvento(['completado' => true]);

        Http::fake(); // cualquier request no esperado hace fallar assertNothingSent

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_un_fallo_de_conexion_no_detiene_el_procesamiento_de_las_demas(): void
    {
        $conexionRota = $this->crearConexion(['token_expires_at' => now()->subHour()]); // fuerza refresh
        $otroVendedorUser = \App\Models\User::factory()->create();
        $otroVendedor = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $otroVendedorUser->id,
            'nombre' => 'Otro vendedor',
        ]);
        $conexionSana = $this->crearConexion([
            'crm_vendedor_id' => $otroVendedor->id,
            'email_outlook' => 'otro@outlook.com',
        ]);
        $eventoDelSano = $this->crearEvento(['vendedor_id' => $otroVendedor->id]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400),
            'graph.microsoft.com/v1.0/me/events' => Http::response(['id' => 'outlook-evt-sano'], 201),
        ]);

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        $conexionRota->refresh();
        $this->assertNotNull($conexionRota->ultimo_error);

        $this->assertDatabaseHas('crm_outlook_eventos_mapeados', [
            'crm_agenda_id' => $eventoDelSano->id,
            'crm_outlook_conexion_id' => $conexionSana->id,
        ]);
    }

    public function test_token_expirado_se_refresca_antes_de_sincronizar(): void
    {
        $conexion = $this->crearConexion(['token_expires_at' => now()->subMinute()]);
        $this->crearEvento();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'access-token-refrescado',
                'refresh_token' => 'refresh-token-refrescado',
                'expires_in' => 3600,
            ], 200),
            'graph.microsoft.com/v1.0/me/events' => Http::response(['id' => 'outlook-evt-1'], 201),
        ]);

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        $conexion->refresh();
        $this->assertEquals('access-token-refrescado', $conexion->access_token);
        $this->assertEquals('refresh-token-refrescado', $conexion->refresh_token);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'login.microsoftonline.com')
            && ($request['grant_type'] ?? null) === 'refresh_token');
    }

    public function test_rate_limit_429_no_interrumpe_el_resto_del_lote(): void
    {
        $this->crearConexion();
        $evento1 = $this->crearEvento(['titulo' => 'Evento 1']);
        $evento2 = $this->crearEvento(['titulo' => 'Evento 2']);

        Http::fake([
            'graph.microsoft.com/v1.0/me/events' => Http::sequence()
                ->push(['error' => 'rate limited'], 429)
                ->push(['id' => 'outlook-evt-2'], 201),
        ]);

        $this->artisan('agenda:sincronizar-outlook')->assertExitCode(0);

        // El primero se saltó (429), el segundo sí se creó -- el lote no se detuvo.
        $this->assertDatabaseMissing('crm_outlook_eventos_mapeados', ['crm_agenda_id' => $evento1->id]);
        $this->assertDatabaseHas('crm_outlook_eventos_mapeados', ['crm_agenda_id' => $evento2->id]);
    }
}
```

- [ ] **Step 4: Correr los tests**

```bash
php artisan test --filter=SincronizarOutlookCommandTest
```

Expected: 7 tests, 0 fallos.

- [ ] **Step 5: Verificación post-cambio y commit**

```bash
php artisan route:clear && php artisan config:clear && php artisan view:clear
php -l app/Console/Commands/SincronizarOutlookCommand.php
git add app/Console/Commands/SincronizarOutlookCommand.php routes/console.php \
        tests/Feature/CRM/SincronizarOutlookCommandTest.php
git commit -m "feat(crm): comando programado agenda:sincronizar-outlook"
```

---

### Task 4: Frontend — vista y conexión (submódulo Integraciones/Outlook)

> Este task modifica el repo **sentinel-front**, no sentinel-back. Si se ejecuta en un worktree separado, debe ser un worktree de `sentinel-front`.

**Files:**
- Modify: `src/index.css` (color de marca `crm-integraciones`)
- Create: `src/hooks/crm/integraciones/useOutlookIntegracion.js`
- Create: `src/hooks/crm/integraciones/index.js`
- Create: `src/views/crm/integraciones/outlook/OutlookView.jsx`
- Create: `src/views/crm/integraciones/outlook/index.js`
- Modify: `src/components/workspace/ModuleLoader.jsx` (registra `crm/integraciones/outlook` para las 5 empresas)

**Interfaces:**
- Consumes: `GET/DELETE /crm/integraciones/outlook/*` (Task 2), `useCrmContext()` (`getBaseUrl`, `getContextHeaders`), `useWorkspace()` (`hasSubmodulePermission`), `useAuth()`, `useAlert()`.
- Produces: `useOutlookIntegracion()` → `{estado, loading, fetchEstado, conectar, desconectar}`; `OutlookView` (export nombrado), registrado en `ModuleLoader.jsx` bajo la clave `${empresa}/crm/integraciones/outlook`.

- [ ] **Step 1: Color de marca**

En `src/index.css`, dentro del bloque `@theme` existente (junto a `--color-crm-agenda-*`), agrega:

```css
  --color-crm-integraciones-start: #0078D4;
  --color-crm-integraciones-end: #106EBE;
```

- [ ] **Step 2: Hook `useOutlookIntegracion`**

```js
// src/hooks/crm/integraciones/useOutlookIntegracion.js
import { useState, useCallback, useEffect } from "react";
import { fetchAPI } from "../../../services/api";
import { useCrmContext } from "../useCrmContext";

/**
 * useOutlookIntegracion
 * Estado de conexión de la cuenta de Outlook del vendedor autenticado.
 * Endpoints:
 *   GET    /api/crm/integraciones/outlook/estado
 *   GET    /api/crm/integraciones/outlook/conectar  -> {url} (navegación completa)
 *   DELETE /api/crm/integraciones/outlook/desconectar
 */
export const useOutlookIntegracion = () => {
  const [estado, setEstado] = useState({
    conectado: false,
    email: null,
    ultimoSync: null,
    ultimoError: null,
  });
  const [loading, setLoading] = useState(true);

  const { getBaseUrl, getContextHeaders } = useCrmContext();
  const BASE_URL = getBaseUrl("integraciones/outlook");
  const contextHeaders = getContextHeaders("integraciones", "outlook");

  const fetchEstado = useCallback(async () => {
    try {
      setLoading(true);
      const response = await fetchAPI(`${BASE_URL}/estado`, { headers: contextHeaders });
      setEstado(
        response.data || { conectado: false, email: null, ultimoSync: null, ultimoError: null },
      );
      return response;
    } catch (err) {
      console.error("Error al obtener el estado de Outlook:", err);
      return null;
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [BASE_URL]);

  useEffect(() => {
    fetchEstado();
  }, [fetchEstado]);

  const conectar = useCallback(async () => {
    const response = await fetchAPI(`${BASE_URL}/conectar`, { headers: contextHeaders });
    if (response?.data?.url) {
      window.location.href = response.data.url;
    }
    return response;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [BASE_URL]);

  const desconectar = useCallback(async () => {
    const response = await fetchAPI(`${BASE_URL}/desconectar`, {
      method: "DELETE",
      headers: contextHeaders,
    });
    await fetchEstado();
    return response;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [BASE_URL, fetchEstado]);

  return { estado, loading, fetchEstado, conectar, desconectar };
};

export default useOutlookIntegracion;
```

- [ ] **Step 3: Barrel del hook**

```js
// src/hooks/crm/integraciones/index.js
export { useOutlookIntegracion, default } from "./useOutlookIntegracion";
```

- [ ] **Step 4: Vista `OutlookView`**

```jsx
// src/views/crm/integraciones/outlook/OutlookView.jsx
import { useEffect, useState } from "react";
import { motion } from "framer-motion";
import { Calendar, CheckCircle2, AlertTriangle, Unlink, Link2 } from "lucide-react";

import { useOutlookIntegracion } from "../../../../hooks/crm/integraciones";
import { useWorkspace } from "../../../../contexts/WorkspaceContext";
import { useAuth } from "../../../../contexts/AuthContext";
import { useAlert } from "../../../../contexts/AlertContext";
import { useConfirm } from "../../../../contexts/ConfirmContext";
import { Card, LoadingScreen } from "../../../../components/sistema";

const ACCENT = "#0078D4";

export function OutlookView() {
  const { submodule } = useWorkspace();
  const { user } = useAuth();
  const alert = useAlert();
  const { confirmDelete } = useConfirm();
  const { estado, loading, conectar, desconectar } = useOutlookIntegracion();
  const [desconectando, setDesconectando] = useState(false);

  // Al volver del callback de Microsoft, el backend redirige aquí con
  // ?outlook=ok|error -- se lee una vez, se muestra el toast y se limpia
  // el parámetro de la URL (sin recargar la página).
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const resultado = params.get("outlook");
    if (!resultado) return;

    if (resultado === "ok") {
      alert.success("Tu cuenta de Outlook se conectó correctamente.");
    } else {
      alert.error("No se pudo conectar tu cuenta de Outlook. Intenta de nuevo.");
    }

    params.delete("outlook");
    const nuevaUrl =
      window.location.pathname + (params.toString() ? `?${params.toString()}` : "");
    window.history.replaceState({}, "", nuevaUrl);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleConectar = async () => {
    try {
      await conectar();
    } catch (err) {
      console.error("Error al iniciar la conexión con Outlook:", err);
      alert.error("No se pudo iniciar la conexión con Outlook.");
    }
  };

  const handleDesconectar = async () => {
    const confirmado = await confirmDelete(
      "¿Desconectar tu cuenta de Outlook? Sentinel dejará de enviar tus eventos de Agenda a tu calendario.",
    );
    if (!confirmado) return;

    try {
      setDesconectando(true);
      await desconectar();
      alert.success("Cuenta de Outlook desconectada.");
    } catch (err) {
      console.error("Error al desconectar Outlook:", err);
      alert.error("No se pudo desconectar la cuenta de Outlook.");
    } finally {
      setDesconectando(false);
    }
  };

  if (loading) {
    return <LoadingScreen />;
  }

  return (
    <div className="p-4 md:p-6 space-y-6">
      <motion.div initial={{ opacity: 0, y: -10 }} animate={{ opacity: 1, y: 0 }}>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
          <Calendar style={{ color: ACCENT }} className="w-6 h-6" />
          {submodule?.name || "Outlook"}
        </h1>
        <p className="text-gray-500 dark:text-gray-400 mt-1">
          Conecta tu cuenta de Outlook para que tus eventos de Agenda aparezcan en tu calendario.
          Es unidireccional: Sentinel envía tus eventos a Outlook, nunca al revés.
        </p>
      </motion.div>

      <Card className="p-6 max-w-xl">
        {!user ? null : estado.conectado ? (
          <div className="space-y-4">
            <div className="flex items-center gap-3">
              <CheckCircle2 className="w-8 h-8 text-green-600 dark:text-green-400 shrink-0" />
              <div>
                <p className="font-semibold text-gray-900 dark:text-white">Conectado</p>
                <p className="text-sm text-gray-500 dark:text-gray-400">{estado.email}</p>
              </div>
            </div>

            {estado.ultimoSync && (
              <p className="text-sm text-gray-500 dark:text-gray-400">
                Última sincronización: {new Date(estado.ultimoSync).toLocaleString("es-MX")}
              </p>
            )}

            {estado.ultimoError && (
              <div className="flex items-start gap-2 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-3">
                <AlertTriangle className="w-5 h-5 text-red-600 dark:text-red-400 shrink-0 mt-0.5" />
                <p className="text-sm text-red-700 dark:text-red-300">{estado.ultimoError}</p>
              </div>
            )}

            <button
              onClick={handleDesconectar}
              disabled={desconectando}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-red-300 dark:border-red-800 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors disabled:opacity-50"
            >
              <Unlink className="w-4 h-4" />
              {desconectando ? "Desconectando..." : "Desconectar"}
            </button>
          </div>
        ) : (
          <div className="space-y-4">
            <div className="flex items-center gap-3">
              <Calendar style={{ color: ACCENT }} className="w-8 h-8 shrink-0" />
              <div>
                <p className="font-semibold text-gray-900 dark:text-white">No conectado</p>
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  Conecta tu cuenta para empezar a sincronizar tu Agenda.
                </p>
              </div>
            </div>

            <button
              onClick={handleConectar}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-white transition-colors"
              style={{ backgroundColor: ACCENT }}
            >
              <Link2 className="w-4 h-4" />
              Conectar con Outlook
            </button>
          </div>
        )}
      </Card>
    </div>
  );
}

export default OutlookView;
```

- [ ] **Step 5: Barrel de la vista**

```js
// src/views/crm/integraciones/outlook/index.js
export { OutlookView, default } from "./OutlookView";
```

- [ ] **Step 6: Registrar en `ModuleLoader.jsx`**

Agrega, junto al bloque de Agenda existente (mismo array de 5 empresas):

```js
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

- [ ] **Step 7: Build y lint**

```bash
npm run build
npm run lint
```

Expected: ambos sin errores nuevos.

- [ ] **Step 8: Verificación manual en navegador**

No hay backend real de Microsoft disponible para un test automatizado del consentimiento -- verificar a mano:
1. Con un usuario que tenga permiso `ver` en Integraciones/Outlook y un `CrmVendedor` propio, entrar a `/{empresa}/crm/integraciones/outlook` y confirmar que se ve el estado "No conectado" con el botón "Conectar con Outlook".
2. Confirmar que el botón "Conectar" dispara una petición a `/conectar` (Network tab) y que, si el backend real de Azure aún no está configurado, la navegación falla de forma visible (no silenciosa) -- aceptable en este punto del desarrollo; la configuración real del App Registration de Azure es un paso de infraestructura fuera de este plan.
3. Confirmar que el estado "Conectado" (forzando una fila en `crm_outlook_conexiones` vía tinker) muestra el correo, la fecha de última sincronización si existe, y el botón "Desconectar" con su confirmación.

- [ ] **Step 9: Commit**

```bash
git add src/index.css src/hooks/crm/integraciones src/views/crm/integraciones \
        src/components/workspace/ModuleLoader.jsx
git commit -m "feat(crm): vista de conexión Outlook en Integraciones"
```

---

## Self-Review

**1. Cobertura del spec:**
- Sección 1 (modelo de datos) → Task 1.
- Sección 2 (flujo OAuth) → Task 2 (incluyendo el ajuste de arquitectura del nonce propio, necesario porque el frontend es Bearer-token-only sin sesión de cookie — el spec asumía implícitamente que `auth:sanctum` normal bastaba en el callback, lo cual no es cierto para una navegación de página completa sin JS; documentado como Global Constraint y en el docblock del controlador).
- Sección 3 (comando programado) → Task 3, incluyendo el ajuste de `nullOnDelete()` en vez de `cascadeOnDelete()` para permitir la detección de borrados (necesario para que el paso 3 de la Sección 3 del spec sea siquiera posible).
- Sección 4 (frontend) → Task 4. Se usó el nombre `OutlookView` en vez del genérico `IntegracionesView` del spec, siguiendo al pie de la letra el patrón de nombrado ya establecido (`crm/agenda/agenda` → `AgendaView`) — mismo comportamiento, nombre más preciso.
- Sección 5 (casos borde y pruebas) → cubiertos por los tests de las Tasks 1-3 (idempotencia, rate limit, aislamiento de fallos, cascadas) y la verificación manual de Task 4.

**2. Placeholders:** ninguno — cada paso trae código completo, sin TBD/TODO.

**3. Consistencia de tipos:** `tienePermisoSubmodulo($empresaId, 'integraciones', 'outlook', 'ver')` se usa igual en Task 2 (controlador) y Task 2 (tests); `CrmOutlookConexion`/`CrmOutlookEventoMapeado` con los mismos nombres de columna en Tasks 1-3; `OutlookView`/`useOutlookIntegracion` con los mismos nombres en Task 4.
