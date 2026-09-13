# CRM Dashboard — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** construir el módulo `Dashboard` del CRM Comercial (el único de los 11 módulos del seeder sin implementar) — 7 endpoints de métricas ejecutivas (KPIs, pipeline por etapa, cotizaciones por estado, funnel de conversión, actividad, cumplimiento de metas, ranking de vendedores) y una vista de resumen ejecutivo que los consume, con selector de periodo y el mismo patrón de permisos `ver`/`ejecutivo` que ya usa Presupuestos.

**Architecture:** `DashboardResumenService` (agregación pura, sin estado, un método por endpoint) inyectado en `DashboardController` (extiende `CrmBaseController`, aplica `FiltraPorEmpresa` + `VerificaPermisoSubmodulo`, resuelve el alcance de datos `ver`/`ejecutivo` una sola vez por request vía un método privado `contexto()`). Sin migraciones nuevas — toda la agregación cruza tablas que ya existen (`CrmOportunidad`, `CrmCotizacion`, `CrmProspecto`, `CrmCliente`, `CrmActividad`, `CrmPresupuesto`, `CrmVendedor`). Frontend: `DashboardView.jsx` + `useCrmDashboard.js` (7 llamadas en paralelo vía `Promise.allSettled`), reutilizando `CrmKpiStrip` para las tarjetas simples y markup custom para los paneles con barra de progreso, siguiendo el patrón ya establecido por `PresupuestosView`.

**Tech Stack:** Laravel 12 (PHP 8.2), `Carbon` para rangos de fecha, SQLite en memoria en tests (`phpunit.xml`) / MySQL en producción — toda query debe ser portable entre ambos; React 19 + Vite 7 + TailwindCSS 4 + `recharts` 3.8.1 + `framer-motion`.

**Spec:** `docs/superpowers/specs/2026-09-09-crm-dashboard-design.md` (vive en el repo `sentinel-front`, ya que ahí se escribió — el contenido aplica igual a este repo backend).

## Global Constraints

- **Sin migraciones nuevas.** Las 7 tablas fuente ya existen con todas las columnas necesarias.
- **Semántica de `?int $vendedorId` en todo método de `DashboardResumenService`** (excepto `rankingVendedores`, que no lo recibe): `null` = sin filtro (alcance `ejecutivo`, agregado de equipo completo); `0` = filtro que nunca matchea ningún registro real (IDs autoincrement arrancan en 1) — se usa para un usuario `ver`-only sin fila `CrmVendedor` propia, así el servicio devuelve ceros de forma natural sin una rama especial por método; cualquier entero positivo = ese vendedor específico. Todo método aplica `when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))` (o el `whereHas('oportunidad', ...)` equivalente para `CrmCotizacion`, que no tiene `vendedor_id` propio) — **nunca** tratar `0` como "sin filtro".
- **`periodo` válido:** `mes_actual|mes_anterior|trimestre|anio` (default `mes_actual`). Se valida en el controller con `Request::validate(['periodo' => 'nullable|in:...'])` → `422` automático de Laravel. `DashboardResumenService::resolverRangoFechas()` además lanza `\InvalidArgumentException` en su rama `default` como defensa en profundidad (no debería alcanzarse nunca en producción porque el controller ya validó antes).
- **`rankingVendedores()` es el único de los 7 sin `vendedorId`** — siempre agregado de equipo por definición, exige el permiso `ejecutivo` (403 con solo `ver`).
- **KPIs mixtos, decisión explícita:** dentro de `kpis()`, "oportunidades abiertas" y "cotizaciones pendientes" son **snapshots del estado actual** (ignoran `periodo` — no tiene sentido decir "oportunidades abiertas del trimestre pasado", son las abiertas ahora); "clientes nuevos" y "% cumplimiento de meta" sí respetan `periodo`. El endpoint sigue aceptando `periodo` en su firma (se usa para las 2 métricas que sí lo requieren).
- **No hay factories de modelos CRM** (`database/factories/CRM/` no existe pese a que los modelos declaran `HasFactory`). Todo test usa `Model::create([...])` directo + el trait `tests/Concerns/CreatesCrmFixtures.php`, igual que el resto de tests CRM — no crear un factory nuevo aquí.
- **`CrmKpiStrip` es 100% presentacional** (label/valor/icon), sin soporte de barra de progreso. Para el panel de cumplimiento de metas (que sí necesita barra), usar markup custom (como ya hace `PresupuestosView` con su `BarraProgreso`) — no forzar `CrmKpiStrip` ahí.
- **Sin suite de tests de React** en el proyecto — verificación de frontend es manual guiada + `npm run lint` + `npm run build`.
- Después de tocar `routes/crm.php`: `php artisan route:clear && php artisan config:clear && php artisan view:clear`, y `php -l` sobre cada archivo PHP modificado. **Nunca** correr `php artisan test`/`migrate` con `--env=`.

---

### Task 1: `DashboardResumenService` — esqueleto y `resolverRangoFechas()`

**Files:**
- Create: `app/Services/CRM/DashboardResumenService.php`
- Test: `tests/Feature/CRM/DashboardResumenServiceTest.php`

**Interfaces:**
- Produces: `DashboardResumenService::resolverRangoFechas(string $periodo): array{inicio: Carbon, fin: Carbon}` (público, consumido por los 7 métodos que se agregan en las Tasks 2-8, y directamente testeado aquí).

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\CRM;

use App\Services\CRM\DashboardResumenService;
use Carbon\Carbon;
use Tests\TestCase;

class DashboardResumenServiceTest extends TestCase
{
    public function test_resuelve_rango_de_mes_actual(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 10, 0, 0));
        $service = new DashboardResumenService();

        $rango = $service->resolverRangoFechas('mes_actual');

        $this->assertSame('2026-09-01 00:00:00', $rango['inicio']->toDateTimeString());
        $this->assertSame('2026-09-30 23:59:59', $rango['fin']->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_resuelve_rango_de_mes_anterior(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 10, 0, 0));
        $service = new DashboardResumenService();

        $rango = $service->resolverRangoFechas('mes_anterior');

        $this->assertSame('2026-08-01 00:00:00', $rango['inicio']->toDateTimeString());
        $this->assertSame('2026-08-31 23:59:59', $rango['fin']->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_resuelve_rango_de_mes_anterior_cruzando_de_anio(): void
    {
        // Caso límite: el mes anterior a enero es diciembre del año pasado.
        Carbon::setTestNow(Carbon::create(2026, 1, 15, 10, 0, 0));
        $service = new DashboardResumenService();

        $rango = $service->resolverRangoFechas('mes_anterior');

        $this->assertSame('2025-12-01 00:00:00', $rango['inicio']->toDateTimeString());
        $this->assertSame('2025-12-31 23:59:59', $rango['fin']->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_resuelve_rango_de_trimestre(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 10, 0, 0));
        $service = new DashboardResumenService();

        $rango = $service->resolverRangoFechas('trimestre');

        $this->assertSame('2026-07-01 00:00:00', $rango['inicio']->toDateTimeString());
        $this->assertSame('2026-09-30 23:59:59', $rango['fin']->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_resuelve_rango_de_anio(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 10, 0, 0));
        $service = new DashboardResumenService();

        $rango = $service->resolverRangoFechas('anio');

        $this->assertSame('2026-01-01 00:00:00', $rango['inicio']->toDateTimeString());
        $this->assertSame('2026-12-31 23:59:59', $rango['fin']->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_periodo_invalido_lanza_excepcion(): void
    {
        $service = new DashboardResumenService();

        $this->expectException(\InvalidArgumentException::class);

        $service->resolverRangoFechas('siglo');
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: FAIL — `Class "App\Services\CRM\DashboardResumenService" not found`.

- [ ] **Step 3: Implementación mínima**

```php
<?php

namespace App\Services\CRM;

use Carbon\Carbon;

/**
 * Agrega métricas ejecutivas del CRM (pipeline, cotizaciones, funnel de
 * conversión, actividad, cumplimiento de metas, ranking de vendedores) para
 * el módulo Dashboard. Sin estado, sin efectos secundarios -- cada método
 * recibe el contexto de empresa/vendedor/periodo ya resuelto por el
 * controller y devuelve un array serializable.
 *
 * No importa PresupuestoResumenService a propósito -- aunque
 * cumplimientoMetas() calcula algo conceptualmente parecido a
 * resumenMensual(), cada service queda independiente por módulo para no
 * acoplar Dashboard a cambios futuros en Presupuestos (spec, "Fuera de
 * alcance").
 */
class DashboardResumenService
{
    /**
     * Resuelve un periodo predefinido al rango [inicio, fin] correspondiente,
     * anclado a "ahora" (Carbon::now(), respeta Carbon::setTestNow() en tests).
     *
     * @return array{inicio: Carbon, fin: Carbon}
     */
    public function resolverRangoFechas(string $periodo): array
    {
        $hoy = Carbon::now();

        return match ($periodo) {
            'mes_actual' => [
                'inicio' => $hoy->copy()->startOfMonth(),
                'fin' => $hoy->copy()->endOfMonth(),
            ],
            'mes_anterior' => [
                'inicio' => $hoy->copy()->subMonthNoOverflow()->startOfMonth(),
                'fin' => $hoy->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'trimestre' => [
                'inicio' => $hoy->copy()->startOfQuarter(),
                'fin' => $hoy->copy()->endOfQuarter(),
            ],
            'anio' => [
                'inicio' => $hoy->copy()->startOfYear(),
                'fin' => $hoy->copy()->endOfYear(),
            ],
            default => throw new \InvalidArgumentException("Periodo inválido: {$periodo}"),
        };
    }
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
php artisan route:clear && php artisan config:clear && php artisan view:clear
php -l app/Services/CRM/DashboardResumenService.php
git add app/Services/CRM/DashboardResumenService.php tests/Feature/CRM/DashboardResumenServiceTest.php
git commit -m "feat(crm-dashboard): DashboardResumenService::resolverRangoFechas()

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: `pipeline()` — oportunidades por etapa

**Files:**
- Modify: `app/Services/CRM/DashboardResumenService.php`
- Modify: `tests/Feature/CRM/DashboardResumenServiceTest.php`

**Interfaces:**
- Consumes: `resolverRangoFechas()` (Task 1).
- Produces: `DashboardResumenService::pipeline(int $empresaId, ?int $vendedorId, string $periodo): array<int, array{etapa: string, total: int, monto: float}>` — siempre las 6 etapas de `CrmOportunidad::ORDEN_ETAPAS`, en cero si no hay datos.

- [ ] **Step 1: Escribir los tests que fallan**

Agregar `use App\Models\CRM\CrmOportunidad;`, `use App\Models\Enterprise;`, `use Illuminate\Foundation\Testing\RefreshDatabase;`, `use Tests\Concerns\CreatesCrmFixtures;` a los imports del archivo, cambiar la clase para usar los traits, y agregar `setUp()`:

```php
class DashboardResumenServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    // ... los 6 tests de resolverRangoFechas() ya escritos en la Task 1 se quedan igual ...

    public function test_pipeline_agrupa_por_etapa_dentro_del_periodo_y_filtra_fuera(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'A', 'monto_esperado' => 1000, 'etapa' => 'calificado',
            'fecha_cierre_esperada' => '2026-09-20',
        ]);
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'B', 'monto_esperado' => 500, 'etapa' => 'calificado',
            'fecha_cierre_esperada' => '2026-09-25',
        ]);
        CrmOportunidad::create([
            // Fuera del periodo (octubre) -- no debe contar en mes_actual.
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'C', 'monto_esperado' => 9999, 'etapa' => 'calificado',
            'fecha_cierre_esperada' => '2026-10-05',
        ]);

        $service = new DashboardResumenService();
        $porEtapa = collect($service->pipeline($this->enterprise->id, $this->vendedor->id, 'mes_actual'))
            ->keyBy('etapa');

        $this->assertCount(6, $porEtapa);
        $this->assertSame(2, $porEtapa['calificado']['total']);
        $this->assertSame(1500.0, $porEtapa['calificado']['monto']);
        $this->assertSame(0, $porEtapa['prospecto']['total']);
        $this->assertSame(0.0, $porEtapa['prospecto']['monto']);

        Carbon::setTestNow();
    }

    public function test_pipeline_ignora_datos_de_otra_empresa(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $otraEmpresa = Enterprise::create([
            'name' => 'Otra Empresa', 'slug' => 'otra-empresa-dashboard-'.uniqid(),
            'description' => 'Aislamiento', 'is_active' => true,
        ]);
        $vendedorAjeno = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Vendedor ajeno',
        ]);
        CrmOportunidad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'nombre' => 'Ajena', 'monto_esperado' => 5000, 'etapa' => 'calificado',
            'fecha_cierre_esperada' => '2026-09-20',
        ]);

        $service = new DashboardResumenService();
        $porEtapa = collect($service->pipeline($this->enterprise->id, null, 'mes_actual'))->keyBy('etapa');

        $this->assertSame(0, $porEtapa['calificado']['total']);

        Carbon::setTestNow();
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: FAIL — `Call to undefined method App\Services\CRM\DashboardResumenService::pipeline()`.

- [ ] **Step 3: Implementación mínima**

Agregar a `app/Services/CRM/DashboardResumenService.php` (import `use App\Models\CRM\CrmOportunidad;` arriba):

```php
    /**
     * @return array<int, array{etapa: string, total: int, monto: float}>
     */
    public function pipeline(int $empresaId, ?int $vendedorId, string $periodo): array
    {
        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        $query = CrmOportunidad::where('empresa_id', $empresaId)
            ->whereBetween('fecha_cierre_esperada', [$inicio->toDateString(), $fin->toDateString()])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId));

        $filas = $query->selectRaw('etapa, COUNT(*) as total, SUM(monto_esperado) as monto')
            ->groupBy('etapa')
            ->get()
            ->keyBy('etapa');

        return collect(array_keys(CrmOportunidad::ORDEN_ETAPAS))
            ->map(fn ($etapa) => [
                'etapa' => $etapa,
                'total' => (int) ($filas[$etapa]->total ?? 0),
                'monto' => (float) ($filas[$etapa]->monto ?? 0),
            ])
            ->values()
            ->all();
    }
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
php -l app/Services/CRM/DashboardResumenService.php
git add app/Services/CRM/DashboardResumenService.php tests/Feature/CRM/DashboardResumenServiceTest.php
git commit -m "feat(crm-dashboard): DashboardResumenService::pipeline()

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: `cotizaciones()` — cotizaciones por estado

**Files:**
- Modify: `app/Services/CRM/DashboardResumenService.php`
- Modify: `tests/Feature/CRM/DashboardResumenServiceTest.php`

**Interfaces:**
- Consumes: `resolverRangoFechas()`.
- Produces: `DashboardResumenService::cotizaciones(int $empresaId, ?int $vendedorId, string $periodo): array<int, array{estado: string, total: int, monto: float}>` — siempre los 5 estados (`borrador`, `enviado`, `aprobado`, `rechazado`, `superado`), en cero si no hay datos. Filtra por vendedor vía `whereHas('oportunidad', ...)` porque `CrmCotizacion` no tiene `vendedor_id` propio (solo lo hereda de su `oportunidad`).

- [ ] **Step 1: Escribir los tests que fallan**

Agregar a `tests/Feature/CRM/DashboardResumenServiceTest.php` (import `use App\Models\CRM\CrmCotizacion;`):

```php
    public function test_cotizaciones_agrupa_por_estado_y_filtra_por_vendedor_via_oportunidad(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        $oportunidadPropia = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Op propia', 'monto_esperado' => 2000, 'etapa' => 'propuesta',
        ]);
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidadPropia->id,
            'folio' => 'COT-1', 'estado' => 'aprobado', 'fecha_emision' => '2026-09-10', 'total' => 1200,
        ]);
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidadPropia->id,
            'folio' => 'COT-2', 'estado' => 'aprobado', 'fecha_emision' => '2026-09-12', 'total' => 800,
        ]);

        $otroVendedor = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Otro vendedor',
        ]);
        $oportunidadAjena = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $otroVendedor->id,
            'nombre' => 'Op ajena', 'monto_esperado' => 5000, 'etapa' => 'propuesta',
        ]);
        CrmCotizacion::create([
            // De otro vendedor -- no debe contar cuando se filtra por $this->vendedor.
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidadAjena->id,
            'folio' => 'COT-3', 'estado' => 'aprobado', 'fecha_emision' => '2026-09-14', 'total' => 9999,
        ]);

        $service = new DashboardResumenService();
        $porEstado = collect($service->cotizaciones($this->enterprise->id, $this->vendedor->id, 'mes_actual'))
            ->keyBy('estado');

        $this->assertCount(5, $porEstado);
        $this->assertSame(2, $porEstado['aprobado']['total']);
        $this->assertSame(2000.0, $porEstado['aprobado']['monto']);
        $this->assertSame(0, $porEstado['rechazado']['total']);

        Carbon::setTestNow();
    }

    public function test_cotizaciones_sin_vendedor_agrega_todo_el_equipo(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        $otroVendedor = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Otro vendedor',
        ]);
        $oportunidadAjena = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $otroVendedor->id,
            'nombre' => 'Op ajena', 'monto_esperado' => 5000, 'etapa' => 'propuesta',
        ]);
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidadAjena->id,
            'folio' => 'COT-4', 'estado' => 'enviado', 'fecha_emision' => '2026-09-14', 'total' => 300,
        ]);

        $service = new DashboardResumenService();
        $porEstado = collect($service->cotizaciones($this->enterprise->id, null, 'mes_actual'))->keyBy('estado');

        $this->assertSame(1, $porEstado['enviado']['total']);

        Carbon::setTestNow();
    }
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: FAIL — `Call to undefined method ...::cotizaciones()`.

- [ ] **Step 3: Implementación mínima**

Agregar (import `use App\Models\CRM\CrmCotizacion;`):

```php
    /**
     * @return array<int, array{estado: string, total: int, monto: float}>
     */
    public function cotizaciones(int $empresaId, ?int $vendedorId, string $periodo): array
    {
        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        $query = CrmCotizacion::where('empresa_id', $empresaId)
            ->whereBetween('fecha_emision', [$inicio->toDateString(), $fin->toDateString()])
            ->when(
                $vendedorId !== null,
                fn ($q) => $q->whereHas('oportunidad', fn ($qq) => $qq->where('vendedor_id', $vendedorId)),
            );

        $filas = $query->selectRaw('estado, COUNT(*) as total, SUM(total) as monto')
            ->groupBy('estado')
            ->get()
            ->keyBy('estado');

        $estados = ['borrador', 'enviado', 'aprobado', 'rechazado', 'superado'];

        return collect($estados)
            ->map(fn ($estado) => [
                'estado' => $estado,
                'total' => (int) ($filas[$estado]->total ?? 0),
                'monto' => (float) ($filas[$estado]->monto ?? 0),
            ])
            ->values()
            ->all();
    }
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
php -l app/Services/CRM/DashboardResumenService.php
git add app/Services/CRM/DashboardResumenService.php tests/Feature/CRM/DashboardResumenServiceTest.php
git commit -m "feat(crm-dashboard): DashboardResumenService::cotizaciones()

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 4: `funnelConversion()` — prospecto → cliente

**Files:**
- Modify: `app/Services/CRM/DashboardResumenService.php`
- Modify: `tests/Feature/CRM/DashboardResumenServiceTest.php`

**Interfaces:**
- Consumes: `resolverRangoFechas()`.
- Produces: `DashboardResumenService::funnelConversion(int $empresaId, ?int $vendedorId, string $periodo): array{prospectosCreados: int, clientesConvertidos: int, tasaConversion: float}` — `prospectosCreados` = `CrmProspecto` creados en el periodo; `clientesConvertidos` = `CrmCliente` creados en el periodo cuyo `prospecto_id` no es null (vino de un prospecto, sin importar cuándo se creó ESE prospecto); `tasaConversion` = porcentaje redondeado a 1 decimal, `0.0` si `prospectosCreados` es 0.

- [ ] **Step 1: Escribir los tests que fallan**

Agregar (imports `use App\Models\CRM\CrmCliente;`, `use App\Models\CRM\CrmProspecto;`):

```php
    public function test_funnel_conversion_calcula_prospectos_clientes_y_tasa(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'P1']);
        CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'P2']);
        CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'P3']);
        CrmCliente::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'C1', 'prospecto_id' => null]);
        // Solo este cuenta como "convertido" -- tiene prospecto_id.
        $prospectoConvertido = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'P4']);
        CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'C2', 'prospecto_id' => $prospectoConvertido->id,
        ]);

        $service = new DashboardResumenService();
        $resultado = $service->funnelConversion($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame(4, $resultado['prospectosCreados']);
        $this->assertSame(1, $resultado['clientesConvertidos']);
        $this->assertSame(25.0, $resultado['tasaConversion']);

        Carbon::setTestNow();
    }

    public function test_funnel_conversion_sin_prospectos_devuelve_tasa_cero(): void
    {
        $service = new DashboardResumenService();
        $resultado = $service->funnelConversion($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame(0, $resultado['prospectosCreados']);
        $this->assertSame(0, $resultado['clientesConvertidos']);
        $this->assertSame(0.0, $resultado['tasaConversion']);
    }
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: FAIL — `Call to undefined method ...::funnelConversion()`.

- [ ] **Step 3: Implementación mínima**

Agregar (imports `use App\Models\CRM\CrmCliente;`, `use App\Models\CRM\CrmProspecto;`):

```php
    /**
     * @return array{prospectosCreados: int, clientesConvertidos: int, tasaConversion: float}
     */
    public function funnelConversion(int $empresaId, ?int $vendedorId, string $periodo): array
    {
        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        $prospectosCreados = CrmProspecto::where('empresa_id', $empresaId)
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->count();

        $clientesConvertidos = CrmCliente::where('empresa_id', $empresaId)
            ->whereNotNull('prospecto_id')
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->count();

        $tasaConversion = $prospectosCreados > 0
            ? round(($clientesConvertidos / $prospectosCreados) * 100, 1)
            : 0.0;

        return [
            'prospectosCreados' => $prospectosCreados,
            'clientesConvertidos' => $clientesConvertidos,
            'tasaConversion' => $tasaConversion,
        ];
    }
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: PASS (12 tests).

- [ ] **Step 5: Commit**

```bash
php -l app/Services/CRM/DashboardResumenService.php
git add app/Services/CRM/DashboardResumenService.php tests/Feature/CRM/DashboardResumenServiceTest.php
git commit -m "feat(crm-dashboard): DashboardResumenService::funnelConversion()

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 5: `actividad()` — por tipo y por día

**Files:**
- Modify: `app/Services/CRM/DashboardResumenService.php`
- Modify: `tests/Feature/CRM/DashboardResumenServiceTest.php`

**Interfaces:**
- Consumes: `resolverRangoFechas()`.
- Produces: `DashboardResumenService::actividad(int $empresaId, ?int $vendedorId, string $periodo): array{porTipo: array<int, array{tipo: string, total: int}>, porDia: array<int, array{fecha: string, total: int}>}`. `porDia` viene ordenado ascendente por fecha, agrupado con `DATE(fecha_actividad)` vía `DB::raw` (portable SQLite/MySQL — agrupar por alias de `SELECT` no es portable entre motores).

- [ ] **Step 1: Escribir los tests que fallan**

Agregar (import `use App\Models\CRM\CrmActividad;`):

```php
    public function test_actividad_agrupa_por_tipo_y_por_dia(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'descripcion' => 'Llamada 1', 'fecha_actividad' => '2026-09-10 09:00:00',
        ]);
        CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'descripcion' => 'Llamada 2', 'fecha_actividad' => '2026-09-10 15:00:00',
        ]);
        CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'correo', 'descripcion' => 'Correo 1', 'fecha_actividad' => '2026-09-12 09:00:00',
        ]);

        $service = new DashboardResumenService();
        $resultado = $service->actividad($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $porTipo = collect($resultado['porTipo'])->keyBy('tipo');
        $this->assertSame(2, $porTipo['llamada']['total']);
        $this->assertSame(1, $porTipo['correo']['total']);

        $porDia = collect($resultado['porDia']);
        $this->assertCount(2, $porDia);
        $this->assertSame('2026-09-10', $porDia->first()['fecha']);
        $this->assertSame(2, $porDia->first()['total']);
        $this->assertSame('2026-09-12', $porDia->last()['fecha']);

        Carbon::setTestNow();
    }

    public function test_actividad_sin_datos_devuelve_arrays_vacios(): void
    {
        $service = new DashboardResumenService();
        $resultado = $service->actividad($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame([], $resultado['porTipo']);
        $this->assertSame([], $resultado['porDia']);
    }
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: FAIL — `Call to undefined method ...::actividad()`.

- [ ] **Step 3: Implementación mínima**

Agregar (imports `use App\Models\CRM\CrmActividad;`, `use Illuminate\Support\Facades\DB;`):

```php
    /**
     * @return array{porTipo: array<int, array{tipo: string, total: int}>, porDia: array<int, array{fecha: string, total: int}>}
     */
    public function actividad(int $empresaId, ?int $vendedorId, string $periodo): array
    {
        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        $base = CrmActividad::where('empresa_id', $empresaId)
            ->whereBetween('fecha_actividad', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId));

        $porTipo = (clone $base)
            ->selectRaw('tipo, COUNT(*) as total')
            ->groupBy('tipo')
            ->get()
            ->map(fn ($fila) => ['tipo' => $fila->tipo, 'total' => (int) $fila->total])
            ->values()
            ->all();

        $porDia = (clone $base)
            ->selectRaw('DATE(fecha_actividad) as fecha, COUNT(*) as total')
            ->groupBy(DB::raw('DATE(fecha_actividad)'))
            ->orderBy('fecha')
            ->get()
            ->map(fn ($fila) => ['fecha' => $fila->fecha, 'total' => (int) $fila->total])
            ->values()
            ->all();

        return [
            'porTipo' => $porTipo,
            'porDia' => $porDia,
        ];
    }
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: PASS (14 tests).

- [ ] **Step 5: Commit**

```bash
php -l app/Services/CRM/DashboardResumenService.php
git add app/Services/CRM/DashboardResumenService.php tests/Feature/CRM/DashboardResumenServiceTest.php
git commit -m "feat(crm-dashboard): DashboardResumenService::actividad()

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 6: `cumplimientoMetas()` — real vs. meta

**Files:**
- Modify: `app/Services/CRM/DashboardResumenService.php`
- Modify: `tests/Feature/CRM/DashboardResumenServiceTest.php`

**Interfaces:**
- Consumes: `resolverRangoFechas()`.
- Produces: `DashboardResumenService::cumplimientoMetas(int $empresaId, ?int $vendedorId, string $periodo): array{metaMonto: float, metaClientes: int, metaActividades: int, montoReal: float, clientesReales: int, actividadesReales: int}`. Las metas (`CrmPresupuesto`) son mensuales — para periodos que abarcan más de un mes (`trimestre`, `anio`) se **suman** las metas de todos los meses del rango; para `mes_actual`/`mes_anterior` es un solo mes. `montoReal` = suma de `monto_esperado` de oportunidades `cerrado_ganado` con `fecha_cierre_real` en el rango (mismo criterio que `PresupuestoResumenService::resumenMensual()`, implementado de forma independiente aquí — ver Global Constraints).

- [ ] **Step 1: Escribir los tests que fallan**

Agregar (import `use App\Models\CRM\CrmPresupuesto;`):

```php
    public function test_cumplimiento_metas_de_un_solo_mes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 9, 'anio' => 2026, 'meta_monto' => 10000, 'meta_clientes' => 5, 'meta_actividades' => 20,
        ]);
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Ganada', 'monto_esperado' => 4000, 'etapa' => 'cerrado_ganado',
            'fecha_cierre_real' => '2026-09-05',
        ]);
        CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'Cliente nuevo',
        ]);
        CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'descripcion' => 'x', 'fecha_actividad' => '2026-09-06 10:00:00',
        ]);

        $service = new DashboardResumenService();
        $resultado = $service->cumplimientoMetas($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame(10000.0, $resultado['metaMonto']);
        $this->assertSame(5, $resultado['metaClientes']);
        $this->assertSame(20, $resultado['metaActividades']);
        $this->assertSame(4000.0, $resultado['montoReal']);
        $this->assertSame(1, $resultado['clientesReales']);
        $this->assertSame(1, $resultado['actividadesReales']);

        Carbon::setTestNow();
    }

    public function test_cumplimiento_metas_de_trimestre_suma_los_3_meses(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        foreach ([7, 8, 9] as $mes) {
            CrmPresupuesto::create([
                'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
                'mes' => $mes, 'anio' => 2026, 'meta_monto' => 1000, 'meta_clientes' => 1, 'meta_actividades' => 2,
            ]);
        }
        // Meta de un mes fuera del trimestre (junio) -- no debe sumar.
        CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 6, 'anio' => 2026, 'meta_monto' => 99999, 'meta_clientes' => 99, 'meta_actividades' => 99,
        ]);

        $service = new DashboardResumenService();
        $resultado = $service->cumplimientoMetas($this->enterprise->id, $this->vendedor->id, 'trimestre');

        $this->assertSame(3000.0, $resultado['metaMonto']);
        $this->assertSame(3, $resultado['metaClientes']);
        $this->assertSame(6, $resultado['metaActividades']);

        Carbon::setTestNow();
    }

    public function test_cumplimiento_metas_sin_presupuesto_definido_devuelve_metas_en_cero(): void
    {
        $service = new DashboardResumenService();
        $resultado = $service->cumplimientoMetas($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame(0.0, $resultado['metaMonto']);
        $this->assertSame(0, $resultado['metaClientes']);
        $this->assertSame(0.0, $resultado['montoReal']);
    }
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: FAIL — `Call to undefined method ...::cumplimientoMetas()`.

- [ ] **Step 3: Implementación mínima**

Agregar (import `use App\Models\CRM\CrmPresupuesto;`):

```php
    /**
     * @return array{metaMonto: float, metaClientes: int, metaActividades: int, montoReal: float, clientesReales: int, actividadesReales: int}
     */
    public function cumplimientoMetas(int $empresaId, ?int $vendedorId, string $periodo): array
    {
        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        $metas = CrmPresupuesto::where('empresa_id', $empresaId)
            ->where(function ($query) use ($inicio, $fin) {
                $cursor = $inicio->copy()->startOfMonth();
                while ($cursor->lte($fin)) {
                    $query->orWhere(fn ($q) => $q->where('mes', $cursor->month)->where('anio', $cursor->year));
                    $cursor->addMonth();
                }
            })
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->selectRaw('SUM(meta_monto) as meta_monto, SUM(meta_clientes) as meta_clientes, SUM(meta_actividades) as meta_actividades')
            ->first();

        $montoReal = (float) CrmOportunidad::where('empresa_id', $empresaId)
            ->where('etapa', 'cerrado_ganado')
            ->whereBetween('fecha_cierre_real', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->sum('monto_esperado');

        $clientesReales = CrmCliente::where('empresa_id', $empresaId)
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->count();

        $actividadesReales = CrmActividad::where('empresa_id', $empresaId)
            ->whereBetween('fecha_actividad', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->count();

        return [
            'metaMonto' => (float) ($metas->meta_monto ?? 0),
            'metaClientes' => (int) ($metas->meta_clientes ?? 0),
            'metaActividades' => (int) ($metas->meta_actividades ?? 0),
            'montoReal' => $montoReal,
            'clientesReales' => $clientesReales,
            'actividadesReales' => $actividadesReales,
        ];
    }
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: PASS (17 tests).

- [ ] **Step 5: Commit**

```bash
php -l app/Services/CRM/DashboardResumenService.php
git add app/Services/CRM/DashboardResumenService.php tests/Feature/CRM/DashboardResumenServiceTest.php
git commit -m "feat(crm-dashboard): DashboardResumenService::cumplimientoMetas()

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 7: `kpis()` — tarjetas superiores

**Files:**
- Modify: `app/Services/CRM/DashboardResumenService.php`
- Modify: `tests/Feature/CRM/DashboardResumenServiceTest.php`

**Interfaces:**
- Consumes: `cumplimientoMetas()` (Task 6) para `porcentajeCumplimientoMeta`.
- Produces: `DashboardResumenService::kpis(int $empresaId, ?int $vendedorId, string $periodo): array{oportunidadesAbiertas: int, montoOportunidadesAbiertas: float, cotizacionesPendientes: int, montoCotizacionesPendientes: float, clientesNuevos: int, porcentajeCumplimientoMeta: float}`. `oportunidadesAbiertas`/`cotizacionesPendientes` son snapshots del estado actual (ignoran `periodo`, ver Global Constraints); `clientesNuevos`/`porcentajeCumplimientoMeta` sí respetan `periodo`.

- [ ] **Step 1: Escribir los tests que fallan**

```php
    public function test_kpis_combina_snapshot_actual_y_metricas_del_periodo(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        // Oportunidades abiertas (snapshot -- no filtra por fecha).
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Abierta', 'monto_esperado' => 3000, 'etapa' => 'propuesta',
        ]);
        CrmOportunidad::create([
            // Cerrada -- no cuenta como "abierta".
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Cerrada', 'monto_esperado' => 999, 'etapa' => 'cerrado_ganado',
            'fecha_cierre_real' => '2026-08-01',
        ]);

        // Cotizaciones pendientes (snapshot).
        $op = CrmOportunidad::first();
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $op->id,
            'folio' => 'C1', 'estado' => 'enviado', 'fecha_emision' => '2026-01-01', 'total' => 700,
        ]);
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $op->id,
            'folio' => 'C2', 'estado' => 'aprobado', 'fecha_emision' => '2026-01-01', 'total' => 1500,
        ]);

        // Clientes nuevos (respeta el periodo).
        CrmCliente::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'Nuevo']);

        // Meta + real para el % de cumplimiento.
        CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 9, 'anio' => 2026, 'meta_monto' => 2000, 'meta_clientes' => 1, 'meta_actividades' => 1,
        ]);
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Ganada del mes', 'monto_esperado' => 1000, 'etapa' => 'cerrado_ganado',
            'fecha_cierre_real' => '2026-09-10',
        ]);

        $service = new DashboardResumenService();
        $resultado = $service->kpis($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame(1, $resultado['oportunidadesAbiertas']);
        $this->assertSame(3000.0, $resultado['montoOportunidadesAbiertas']);
        $this->assertSame(1, $resultado['cotizacionesPendientes']); // solo 'enviado' es pendiente
        $this->assertSame(700.0, $resultado['montoCotizacionesPendientes']);
        $this->assertSame(1, $resultado['clientesNuevos']);
        $this->assertSame(50.0, $resultado['porcentajeCumplimientoMeta']); // 1000/2000

        Carbon::setTestNow();
    }

    public function test_kpis_sin_meta_definida_no_divide_entre_cero(): void
    {
        $service = new DashboardResumenService();
        $resultado = $service->kpis($this->enterprise->id, $this->vendedor->id, 'mes_actual');

        $this->assertSame(0.0, $resultado['porcentajeCumplimientoMeta']);
    }
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: FAIL — `Call to undefined method ...::kpis()`.

- [ ] **Step 3: Implementación mínima**

```php
    /**
     * @return array{oportunidadesAbiertas: int, montoOportunidadesAbiertas: float, cotizacionesPendientes: int, montoCotizacionesPendientes: float, clientesNuevos: int, porcentajeCumplimientoMeta: float}
     */
    public function kpis(int $empresaId, ?int $vendedorId, string $periodo): array
    {
        $oportunidadesAbiertas = CrmOportunidad::where('empresa_id', $empresaId)
            ->activas()
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->selectRaw('COUNT(*) as total, SUM(monto_esperado) as monto')
            ->first();

        $cotizacionesPendientes = CrmCotizacion::where('empresa_id', $empresaId)
            ->whereIn('estado', ['borrador', 'enviado'])
            ->when(
                $vendedorId !== null,
                fn ($q) => $q->whereHas('oportunidad', fn ($qq) => $qq->where('vendedor_id', $vendedorId)),
            )
            ->selectRaw('COUNT(*) as total, SUM(total) as monto')
            ->first();

        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        $clientesNuevos = CrmCliente::where('empresa_id', $empresaId)
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->count();

        $metas = $this->cumplimientoMetas($empresaId, $vendedorId, $periodo);
        $porcentajeCumplimientoMeta = $metas['metaMonto'] > 0
            ? round(($metas['montoReal'] / $metas['metaMonto']) * 100, 1)
            : 0.0;

        return [
            'oportunidadesAbiertas' => (int) ($oportunidadesAbiertas->total ?? 0),
            'montoOportunidadesAbiertas' => (float) ($oportunidadesAbiertas->monto ?? 0),
            'cotizacionesPendientes' => (int) ($cotizacionesPendientes->total ?? 0),
            'montoCotizacionesPendientes' => (float) ($cotizacionesPendientes->monto ?? 0),
            'clientesNuevos' => $clientesNuevos,
            'porcentajeCumplimientoMeta' => $porcentajeCumplimientoMeta,
        ];
    }
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: PASS (19 tests).

- [ ] **Step 5: Commit**

```bash
php -l app/Services/CRM/DashboardResumenService.php
git add app/Services/CRM/DashboardResumenService.php tests/Feature/CRM/DashboardResumenServiceTest.php
git commit -m "feat(crm-dashboard): DashboardResumenService::kpis()

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 8: `rankingVendedores()` — top vendedores por monto cerrado

**Files:**
- Modify: `app/Services/CRM/DashboardResumenService.php`
- Modify: `tests/Feature/CRM/DashboardResumenServiceTest.php`

**Interfaces:**
- Consumes: `resolverRangoFechas()`.
- Produces: `DashboardResumenService::rankingVendedores(int $empresaId, string $periodo): array<int, array{vendedorId: int, nombre: string, montoCerrado: float}>` — **sin** parámetro `$vendedorId`: siempre agrega los vendedores activos de la empresa, ordenado descendente por monto cerrado.

- [ ] **Step 1: Escribir los tests que fallan**

```php
    public function test_ranking_vendedores_ordena_por_monto_cerrado_descendente(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));

        $vendedorB = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Vendedor B', 'activo' => true,
        ]);

        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Op 1', 'monto_esperado' => 1000, 'etapa' => 'cerrado_ganado',
            'fecha_cierre_real' => '2026-09-05',
        ]);
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $vendedorB->id,
            'nombre' => 'Op 2', 'monto_esperado' => 5000, 'etapa' => 'cerrado_ganado',
            'fecha_cierre_real' => '2026-09-06',
        ]);
        CrmOportunidad::create([
            // Fuera del periodo -- no debe sumar.
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $vendedorB->id,
            'nombre' => 'Op 3 (agosto)', 'monto_esperado' => 9999, 'etapa' => 'cerrado_ganado',
            'fecha_cierre_real' => '2026-08-01',
        ]);

        $service = new DashboardResumenService();
        $ranking = $service->rankingVendedores($this->enterprise->id, 'mes_actual');

        $this->assertSame($vendedorB->id, $ranking[0]['vendedorId']);
        $this->assertSame(5000.0, $ranking[0]['montoCerrado']);
        $this->assertSame($this->vendedor->id, $ranking[1]['vendedorId']);
        $this->assertSame(1000.0, $ranking[1]['montoCerrado']);

        Carbon::setTestNow();
    }

    public function test_ranking_vendedores_incluye_vendedores_sin_cierres_en_cero(): void
    {
        $service = new DashboardResumenService();
        $ranking = $service->rankingVendedores($this->enterprise->id, 'mes_actual');

        $this->assertCount(1, $ranking); // solo $this->vendedor, de la fixture
        $this->assertSame(0.0, $ranking[0]['montoCerrado']);
    }
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: FAIL — `Call to undefined method ...::rankingVendedores()`.

- [ ] **Step 3: Implementación mínima**

Agregar (import `use App\Models\CRM\CrmVendedor;`):

```php
    /**
     * @return array<int, array{vendedorId: int, nombre: string, montoCerrado: float}>
     */
    public function rankingVendedores(int $empresaId, string $periodo): array
    {
        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        return CrmVendedor::where('empresa_id', $empresaId)
            ->activo()
            ->withSum(['oportunidades as monto_cerrado' => function ($query) use ($inicio, $fin) {
                $query->where('etapa', 'cerrado_ganado')
                    ->whereBetween('fecha_cierre_real', [$inicio, $fin]);
            }], 'monto_esperado')
            ->orderByDesc('monto_cerrado')
            ->get()
            ->map(fn ($vendedor) => [
                'vendedorId' => $vendedor->id,
                'nombre' => $vendedor->nombre,
                'montoCerrado' => (float) ($vendedor->monto_cerrado ?? 0),
            ])
            ->values()
            ->all();
    }
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `php artisan test --filter=DashboardResumenServiceTest`
Expected: PASS (21 tests).

- [ ] **Step 5: Commit**

```bash
php -l app/Services/CRM/DashboardResumenService.php
git add app/Services/CRM/DashboardResumenService.php tests/Feature/CRM/DashboardResumenServiceTest.php
git commit -m "feat(crm-dashboard): DashboardResumenService::rankingVendedores()

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 9: `DashboardController` + rutas + tests de integración (permisos, scoping, edge cases)

**Files:**
- Create: `app/Http/Controllers/Api/CRM/DashboardController.php`
- Modify: `routes/crm.php:191-196` (bloque `DASHBOARD`, actualmente vacío)
- Test: `tests/Feature/CRM/DashboardControllerTest.php`

**Interfaces:**
- Consumes: los 7 métodos de `DashboardResumenService` (Tasks 1-8), `FiltraPorEmpresa::getEmpresaId()`, `VerificaPermisoSubmodulo::tienePermisoSubmodulo()`, `CrmBaseController::jsonSuccess()`.
- Produces: 7 rutas `GET /api/crm/dashboard/{kpis|pipeline|cotizaciones|funnel-conversion|actividad|cumplimiento-metas|ranking-vendedores}`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\CRM\CrmVendedor;
use App\Models\UserSubmodulePermission;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    /** Crea el árbol Application/Module/Submodule/PermissionType y otorga los permisos dados al actingUser sobre el submódulo dashboard. */
    private function otorgarPermisosDashboard(array $slugs): void
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $this->enterprise->id, 'slug' => 'crm'],
            ['name' => 'CRM Comercial', 'description' => 'CRM Comercial', 'path' => '/'.$this->enterprise->slug.'/crm', 'is_active' => true],
        );
        $modulo = Module::firstOrCreate(
            ['application_id' => $app->id, 'slug' => 'dashboard'],
            ['name' => 'Dashboard', 'order' => 10, 'is_active' => true],
        );
        $submodulo = Submodule::firstOrCreate(
            ['module_id' => $modulo->id, 'slug' => 'dashboard'],
            ['name' => 'Dashboard', 'order' => 1, 'is_active' => true],
        );

        foreach (['ver', 'ejecutivo'] as $slug) {
            $tipo = SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $submodulo->id, 'slug' => $slug],
                ['name' => ucfirst($slug), 'order' => 1, 'is_active' => true],
            );

            if (in_array($slug, $slugs, true)) {
                UserSubmodulePermission::create([
                    'user_id' => $this->actingUser->id,
                    'submodule_id' => $submodulo->id,
                    'permission_type_id' => $tipo->id,
                    'is_granted' => true,
                ]);
            }
        }
    }

    /** Crea un CrmVendedor cuyo user_id es el actingUser. */
    private function crearVendedorPropio(): CrmVendedor
    {
        return CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id, 'nombre' => 'Vendedor propio',
        ]);
    }

    public static function endpointsRegularesProvider(): array
    {
        return [
            ['kpis'],
            ['pipeline'],
            ['cotizaciones'],
            ['funnel-conversion'],
            ['actividad'],
            ['cumplimiento-metas'],
        ];
    }

    /** @dataProvider endpointsRegularesProvider */
    public function test_rechaza_endpoint_regular_sin_permiso_ver(string $endpoint): void
    {
        $this->otorgarPermisosDashboard([]);

        $response = $this->withHeaders($this->crmHeaders())->getJson("/api/crm/dashboard/{$endpoint}");

        $response->assertStatus(403);
    }

    /** @dataProvider endpointsRegularesProvider */
    public function test_permite_endpoint_regular_con_permiso_ver(string $endpoint): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        $this->crearVendedorPropio();

        $response = $this->withHeaders($this->crmHeaders())->getJson("/api/crm/dashboard/{$endpoint}");

        $response->assertOk();
    }

    public function test_ranking_vendedores_rechaza_sin_permiso_ejecutivo(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        $this->crearVendedorPropio();

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/ranking-vendedores');

        $response->assertStatus(403);
    }

    public function test_ranking_vendedores_permite_con_permiso_ejecutivo(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/ranking-vendedores');

        $response->assertOk();
    }

    public function test_ver_sin_ejecutivo_solo_ve_sus_propias_metricas(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        $vendedorPropio = $this->crearVendedorPropio();

        \App\Models\CRM\CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $vendedorPropio->id,
            'nombre' => 'Propia', 'monto_esperado' => 1000, 'etapa' => 'propuesta',
        ]);
        \App\Models\CRM\CrmOportunidad::create([
            // De otro vendedor (fixture) -- un 'ver'-only NO debe verla.
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Ajena', 'monto_esperado' => 5000, 'etapa' => 'propuesta',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/kpis');

        $response->assertOk()->assertJsonPath('data.oportunidadesAbiertas', 1);
    }

    public function test_ejecutivo_ve_el_agregado_de_todo_el_equipo(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        $otroVendedor = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro', 'activo' => true]);

        \App\Models\CRM\CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'De A', 'monto_esperado' => 1000, 'etapa' => 'propuesta',
        ]);
        \App\Models\CRM\CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $otroVendedor->id,
            'nombre' => 'De B', 'monto_esperado' => 2000, 'etapa' => 'propuesta',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/kpis');

        $response->assertOk()->assertJsonPath('data.oportunidadesAbiertas', 2);
    }

    public function test_ver_sin_vendedor_propio_devuelve_ceros_no_error(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        // El actingUser no tiene NINGÚN CrmVendedor propio.

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/kpis');

        $response->assertOk()
            ->assertJsonPath('data.oportunidadesAbiertas', 0)
            ->assertJsonPath('data.clientesNuevos', 0);
    }

    public function test_sin_empresa_resuelta_rechaza_con_403(): void
    {
        $this->otorgarPermisosDashboard(['ver']);

        $response = $this->getJson('/api/crm/dashboard/kpis'); // sin header X-Enterprise-Id ni acceso activo

        $response->assertStatus(403);
    }

    public function test_periodo_invalido_devuelve_422(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        $this->crearVendedorPropio();

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson('/api/crm/dashboard/kpis?periodo=siglo');

        $response->assertStatus(422);
    }

    public function test_periodo_omitido_usa_mes_actual_por_default(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        $this->crearVendedorPropio();

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/pipeline');

        $response->assertOk()->assertJsonCount(6, 'data'); // las 6 etapas, sin importar el periodo default
    }

    public function test_no_ve_datos_de_otra_empresa(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        $otraEmpresa = $this->crearOtraEmpresa();
        $this->otorgarAccesoA($otraEmpresa);
        $vendedorAjeno = CrmVendedor::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Ajeno']);
        \App\Models\CRM\CrmOportunidad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'nombre' => 'Op de otra empresa', 'monto_esperado' => 99999, 'etapa' => 'propuesta',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->getJson('/api/crm/dashboard/kpis');

        $response->assertOk()->assertJsonPath('data.oportunidadesAbiertas', 0);
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test --filter=DashboardControllerTest`
Expected: FAIL — 404 en cada endpoint (rutas y controller no existen todavía).

- [ ] **Step 3: Implementación mínima**

`app/Http/Controllers/Api/CRM/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmVendedor;
use App\Services\CRM\DashboardResumenService;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * DashboardController
 * 7 endpoints de solo lectura con métricas ejecutivas del CRM (kpis,
 * pipeline, cotizaciones, funnel de conversión, actividad, cumplimiento de
 * metas, ranking de vendedores). Toda la agregación vive en
 * DashboardResumenService; este controller solo resuelve permisos/alcance.
 */
class DashboardController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const PERIODOS_VALIDOS = ['mes_actual', 'mes_anterior', 'trimestre', 'anio'];

    public function __construct(private readonly DashboardResumenService $resumen) {}

    public function kpis(Request $request): JsonResponse
    {
        [$empresaId, $vendedorId, $periodo] = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->kpis($empresaId, $vendedorId, $periodo));
    }

    public function pipeline(Request $request): JsonResponse
    {
        [$empresaId, $vendedorId, $periodo] = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->pipeline($empresaId, $vendedorId, $periodo));
    }

    public function cotizaciones(Request $request): JsonResponse
    {
        [$empresaId, $vendedorId, $periodo] = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->cotizaciones($empresaId, $vendedorId, $periodo));
    }

    public function funnelConversion(Request $request): JsonResponse
    {
        [$empresaId, $vendedorId, $periodo] = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->funnelConversion($empresaId, $vendedorId, $periodo));
    }

    public function actividad(Request $request): JsonResponse
    {
        [$empresaId, $vendedorId, $periodo] = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->actividad($empresaId, $vendedorId, $periodo));
    }

    public function cumplimientoMetas(Request $request): JsonResponse
    {
        [$empresaId, $vendedorId, $periodo] = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->cumplimientoMetas($empresaId, $vendedorId, $periodo));
    }

    /** GET /crm/dashboard/ranking-vendedores -- único endpoint que exige 'ejecutivo', sin vendedorId (siempre agregado de equipo). */
    public function rankingVendedores(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'dashboard', 'dashboard', 'ejecutivo'),
            403,
            'No tienes permiso para ver el ranking de vendedores.',
        );

        $periodo = $this->resolverPeriodo($request);

        return $this->jsonSuccess($this->resumen->rankingVendedores($empresaId, $periodo));
    }

    /**
     * Resuelve (empresaId, vendedorId, periodo) para los 6 endpoints
     * regulares. vendedorId es null cuando el usuario tiene 'ejecutivo'
     * (agregado de equipo, sin filtro); si solo tiene 'ver', se resuelve a
     * su propio CrmVendedor -- o a 0 si no tiene ninguno (0 nunca es un id
     * real, así que el service devuelve ceros de forma natural sin una
     * rama especial por método).
     *
     * @return array{0: int, 1: ?int, 2: string}
     */
    private function contexto(Request $request): array
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'dashboard', 'dashboard', 'ver'),
            403,
            'No tienes permiso para ver el dashboard.',
        );

        $periodo = $this->resolverPeriodo($request);

        if ($this->tienePermisoSubmodulo($empresaId, 'dashboard', 'dashboard', 'ejecutivo')) {
            return [$empresaId, null, $periodo];
        }

        $vendedorId = CrmVendedor::where('empresa_id', $empresaId)
            ->where('user_id', Auth::id())
            ->value('id');

        return [$empresaId, $vendedorId !== null ? (int) $vendedorId : 0, $periodo];
    }

    private function resolverPeriodo(Request $request): string
    {
        $validated = $request->validate([
            'periodo' => 'nullable|in:'.implode(',', self::PERIODOS_VALIDOS),
        ]);

        return $validated['periodo'] ?? 'mes_actual';
    }
}
```

En `routes/crm.php`, reemplazar el bloque vacío (líneas 191-196):

```php
    // -------------------------------------------------
    // DASHBOARD
    // 7 endpoints de métricas ejecutivas
    // -------------------------------------------------
    Route::get('dashboard/kpis', [
        App\Http\Controllers\Api\CRM\DashboardController::class, 'kpis'
    ]);
    Route::get('dashboard/pipeline', [
        App\Http\Controllers\Api\CRM\DashboardController::class, 'pipeline'
    ]);
    Route::get('dashboard/cotizaciones', [
        App\Http\Controllers\Api\CRM\DashboardController::class, 'cotizaciones'
    ]);
    Route::get('dashboard/funnel-conversion', [
        App\Http\Controllers\Api\CRM\DashboardController::class, 'funnelConversion'
    ]);
    Route::get('dashboard/actividad', [
        App\Http\Controllers\Api\CRM\DashboardController::class, 'actividad'
    ]);
    Route::get('dashboard/cumplimiento-metas', [
        App\Http\Controllers\Api\CRM\DashboardController::class, 'cumplimientoMetas'
    ]);
    Route::get('dashboard/ranking-vendedores', [
        App\Http\Controllers\Api\CRM\DashboardController::class, 'rankingVendedores'
    ]);
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `php artisan route:clear && php artisan config:clear && php artisan view:clear && php artisan test --filter=DashboardControllerTest`
Expected: PASS (21 tests: 12 del `@dataProvider` de los 6 endpoints regulares × 2 casos, más 9 tests individuales de permisos/scoping/edge cases).

También correr la suite completa de CRM para confirmar que nada más se rompió:

Run: `php artisan test --filter=CRM`
Expected: PASS (todo verde, incluyendo `DashboardResumenServiceTest` y `DashboardControllerTest`).

- [ ] **Step 5: Commit**

```bash
php artisan route:clear && php artisan config:clear && php artisan view:clear
php -l app/Http/Controllers/Api/CRM/DashboardController.php
php -l routes/crm.php
git add app/Http/Controllers/Api/CRM/DashboardController.php routes/crm.php tests/Feature/CRM/DashboardControllerTest.php
git commit -m "feat(crm-dashboard): DashboardController + rutas de los 7 endpoints

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 10: Frontend — colores CRM + `useCrmDashboard` hook

**Files:**
- Modify: `src/index.css` (agrega `--color-crm-dashboard-start/-end` al bloque `@theme`)
- Create: `src/hooks/crm/dashboard/useCrmDashboard.js`
- Create: `src/hooks/crm/dashboard/index.js`

**Interfaces:**
- Consumes: `fetchAPI` (`src/services/api.js`), `useCrmContext().getContextHeaders('dashboard', 'dashboard')` (`src/hooks/crm/useCrmContext.js`).
- Produces: `useCrmDashboard()` → `{ kpis, pipeline, cotizaciones, funnel, actividad, metas, ranking, loadingKpis, loadingPipeline, loadingCotizaciones, loadingFunnel, loadingActividad, loadingMetas, loadingRanking, rankingNoAplica, error, periodo, setPeriodo }`.

Este task es frontend puro (sin suite de tests de React en el proyecto) — la verificación es `npm run lint` + inspección manual del código contra la spec, más verificación funcional en el Task 12 (cuando la vista ya esté montada y se pueda ver en el navegador).

- [ ] **Step 1: Agregar las variables de color**

En `src/index.css`, dentro del bloque `@theme` (junto a las demás `--color-crm-*`), agregar después de la línea de `--color-crm-integraciones-dialpad-*`:

```css
  --color-crm-dashboard-start: #1e3a8a; /* blue-900 */
  --color-crm-dashboard-end: #1d4ed8;   /* blue-700 */
```

(Color distinto de todos los ya usados — azul oscuro, evoca "resumen ejecutivo/analítica" sin chocar con ningún submódulo existente.)

- [ ] **Step 2: Crear el hook `useCrmDashboard`**

```javascript
import { useState, useCallback } from "react";
import { fetchAPI } from "../../../services/api";
import { useCrmContext } from "../useCrmContext";

/**
 * Dispara los 7 endpoints del Dashboard en paralelo con Promise.allSettled
 * (no Promise.all): un usuario sin permiso 'ejecutivo' recibe un 403
 * esperado en ranking-vendedores, y eso NO debe tumbar los otros 6 paneles.
 * Cada métrica tiene su propio estado de carga individual para que cada
 * panel muestre su skeleton sin bloquear toda la vista por el endpoint
 * más lento.
 */
export const useCrmDashboard = () => {
  const [periodo, setPeriodo] = useState("mes_actual");
  const [kpis, setKpis] = useState(null);
  const [pipeline, setPipeline] = useState([]);
  const [cotizaciones, setCotizaciones] = useState([]);
  const [funnel, setFunnel] = useState(null);
  const [actividad, setActividad] = useState(null);
  const [metas, setMetas] = useState(null);
  const [ranking, setRanking] = useState([]);
  const [rankingNoAplica, setRankingNoAplica] = useState(false);
  const [loadingKpis, setLoadingKpis] = useState(false);
  const [loadingPipeline, setLoadingPipeline] = useState(false);
  const [loadingCotizaciones, setLoadingCotizaciones] = useState(false);
  const [loadingFunnel, setLoadingFunnel] = useState(false);
  const [loadingActividad, setLoadingActividad] = useState(false);
  const [loadingMetas, setLoadingMetas] = useState(false);
  const [loadingRanking, setLoadingRanking] = useState(false);
  const [error, setError] = useState(null);

  const { getContextHeaders } = useCrmContext();
  const headers = getContextHeaders("dashboard", "dashboard");

  const cargar = useCallback(
    async (periodoActual = periodo) => {
      setError(null);

      const peticiones = [
        { key: "kpis", url: `/crm/dashboard/kpis?periodo=${periodoActual}`, setLoading: setLoadingKpis, setData: setKpis },
        { key: "pipeline", url: `/crm/dashboard/pipeline?periodo=${periodoActual}`, setLoading: setLoadingPipeline, setData: setPipeline },
        { key: "cotizaciones", url: `/crm/dashboard/cotizaciones?periodo=${periodoActual}`, setLoading: setLoadingCotizaciones, setData: setCotizaciones },
        { key: "funnel", url: `/crm/dashboard/funnel-conversion?periodo=${periodoActual}`, setLoading: setLoadingFunnel, setData: setFunnel },
        { key: "actividad", url: `/crm/dashboard/actividad?periodo=${periodoActual}`, setLoading: setLoadingActividad, setData: setActividad },
        { key: "metas", url: `/crm/dashboard/cumplimiento-metas?periodo=${periodoActual}`, setLoading: setLoadingMetas, setData: setMetas },
        { key: "ranking", url: `/crm/dashboard/ranking-vendedores?periodo=${periodoActual}`, setLoading: setLoadingRanking, setData: setRanking },
      ];

      peticiones.forEach(({ setLoading }) => setLoading(true));
      setRankingNoAplica(false);

      const resultados = await Promise.allSettled(
        peticiones.map(({ url }) => fetchAPI(url, { headers })),
      );

      resultados.forEach((resultado, index) => {
        const { key, setLoading, setData } = peticiones[index];
        setLoading(false);

        if (resultado.status === "fulfilled") {
          setData(resultado.value.data);
          return;
        }

        // ranking-vendedores en 403 para un usuario sin 'ejecutivo' es
        // esperado, no un error de la vista -- se marca "no aplica" en vez
        // de disparar el error general.
        if (key === "ranking" && resultado.reason?.status === 403) {
          setRankingNoAplica(true);
          return;
        }

        console.error(`Error al cargar ${key} del dashboard:`, resultado.reason);
        setError(resultado.reason?.message || "Error al cargar el dashboard");
      });
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  );

  return {
    kpis,
    pipeline,
    cotizaciones,
    funnel,
    actividad,
    metas,
    ranking,
    rankingNoAplica,
    loadingKpis,
    loadingPipeline,
    loadingCotizaciones,
    loadingFunnel,
    loadingActividad,
    loadingMetas,
    loadingRanking,
    error,
    periodo,
    setPeriodo,
    cargar,
  };
};

export default useCrmDashboard;
```

- [ ] **Step 3: Crear el barrel export**

```javascript
export { useCrmDashboard } from "./useCrmDashboard";
```

Ruta: `src/hooks/crm/dashboard/index.js`.

- [ ] **Step 4: Verificar sintácticamente**

Run: `npm run lint -- src/hooks/crm/dashboard/`
Expected: sin errores (puede haber warnings preexistentes de otros archivos, ignorarlos; el foco es que los 2 archivos nuevos no introduzcan errores).

- [ ] **Step 5: Commit**

```bash
git add src/index.css src/hooks/crm/dashboard/
git commit -m "feat(crm-dashboard): useCrmDashboard hook + paleta de color

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 11: Frontend — `DashboardView.jsx`

**Files:**
- Create: `src/views/crm/dashboard/dashboard/DashboardView.jsx`
- Create: `src/views/crm/dashboard/dashboard/index.js`

**Interfaces:**
- Consumes: `useCrmDashboard()` (Task 10), `CrmKpiStrip` (`src/components/crm/comun/CrmKpiStrip.jsx`), `useWorkspace()` (`hasSubmodulePermission`, `submodule`), `recharts`, `framer-motion`.
- Produces: `DashboardView` (named export), montada en `ModuleLoader.jsx` en la Task 12.

- [ ] **Step 1: Crear la vista**

```jsx
import { useEffect, useMemo } from "react";
import { motion } from "framer-motion";
import {
  BarChart, Bar, LineChart, Line, PieChart, Pie, Cell,
  CartesianGrid, XAxis, YAxis, Tooltip, Legend, ResponsiveContainer,
} from "recharts";
import { LayoutDashboard, Briefcase, FileText, Users, Target } from "lucide-react";

import { useCrmDashboard } from "../../../../hooks/crm/dashboard";
import { useWorkspace } from "../../../../contexts/WorkspaceContext";
import { Card, LoadingScreen } from "../../../../components/sistema";
import { CrmKpiStrip } from "../../../../components/crm/comun/CrmKpiStrip";

const PERIODOS = [
  { value: "mes_actual", label: "Mes actual" },
  { value: "mes_anterior", label: "Mes anterior" },
  { value: "trimestre", label: "Trimestre" },
  { value: "anio", label: "Año (YTD)" },
];

const COLORES_ESTADO = {
  borrador: "#94a3b8", enviado: "#3b82f6", aprobado: "#22c55e",
  rechazado: "#ef4444", superado: "#a855f7",
};

const formatoMoneda = (valor) =>
  new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN", maximumFractionDigits: 0 }).format(valor || 0);

/** Barra de progreso real vs. meta -- mismo criterio visual que PresupuestosView. */
const BarraProgreso = ({ meta, real }) => {
  const pct = meta > 0 ? Math.min(100, Math.round((real / meta) * 100)) : 0;
  const color = meta === 0 ? "bg-gray-300 dark:bg-gray-600" : pct >= 100 ? "bg-emerald-500" : "bg-crm-dashboard-end";

  return (
    <div className='mt-1.5 h-1.5 w-full rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden'>
      <motion.div
        initial={{ width: 0 }}
        animate={{ width: `${pct}%` }}
        transition={{ duration: 0.6, ease: "easeOut" }}
        className={`h-full rounded-full ${color}`}
      />
    </div>
  );
};

export function DashboardView() {
  const { submodule, hasSubmodulePermission } = useWorkspace();
  const puedeVerRanking = hasSubmodulePermission(submodule?.id, "ejecutivo");

  const {
    kpis, pipeline, cotizaciones, funnel, actividad, metas, ranking, rankingNoAplica,
    loadingKpis, loadingPipeline, loadingCotizaciones, loadingFunnel, loadingActividad, loadingMetas,
    error, periodo, setPeriodo, cargar,
  } = useCrmDashboard();

  useEffect(() => {
    cargar(periodo);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [periodo]);

  const tarjetasKpi = useMemo(() => {
    if (!kpis) return [];
    return [
      { label: "Oportunidades abiertas", valor: kpis.oportunidadesAbiertas, icon: Briefcase },
      { label: "Cotizaciones pendientes", valor: kpis.cotizacionesPendientes, icon: FileText },
      { label: "Clientes nuevos", valor: kpis.clientesNuevos, icon: Users },
      { label: "Cumplimiento de meta", valor: `${kpis.porcentajeCumplimientoMeta}%`, icon: Target },
    ];
  }, [kpis]);

  const cargandoInicial = loadingKpis && !kpis;

  if (cargandoInicial) {
    return <LoadingScreen message='Cargando dashboard...' />;
  }

  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.3 }}
      className='container mx-auto px-4 md:px-6 py-6 space-y-4'
    >
      <div className='flex items-center gap-4'>
        <div className='p-3 bg-linear-to-br from-crm-dashboard-start to-crm-dashboard-end rounded-xl shadow-lg shadow-crm-dashboard-start/25'>
          <LayoutDashboard className='w-8 h-8 text-white' />
        </div>
        <div>
          <h1 className='text-3xl font-bold text-gray-900 dark:text-white'>Dashboard</h1>
          <p className='text-gray-600 dark:text-gray-400 mt-1'>Resumen ejecutivo del CRM</p>
        </div>
      </div>

      <Card className='p-4'>
        <select
          value={periodo}
          onChange={(e) => setPeriodo(e.target.value)}
          className='px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm'
        >
          {PERIODOS.map((p) => (
            <option key={p.value} value={p.value}>{p.label}</option>
          ))}
        </select>
      </Card>

      {error && (
        <Card className='p-4 text-center text-red-600 dark:text-red-400'>{error}</Card>
      )}

      {kpis && (
        <CrmKpiStrip tarjetas={tarjetasKpi} iconGradientClassName='from-crm-dashboard-start to-crm-dashboard-end' />
      )}

      <div className='grid grid-cols-1 lg:grid-cols-2 gap-4'>
        <Card className='p-4'>
          <h3 className='text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3'>Pipeline por etapa</h3>
          {loadingPipeline ? (
            <div className='h-64 flex items-center justify-center text-gray-400'>Cargando...</div>
          ) : (
            <ResponsiveContainer width='100%' height={260}>
              <BarChart data={pipeline}>
                <CartesianGrid strokeDasharray='3 3' />
                <XAxis dataKey='etapa' tick={{ fontSize: 11 }} />
                <YAxis />
                <Tooltip formatter={(valor) => formatoMoneda(valor)} />
                <Legend />
                <Bar dataKey='monto' name='Monto esperado' fill='#1d4ed8' />
              </BarChart>
            </ResponsiveContainer>
          )}
        </Card>

        <Card className='p-4'>
          <h3 className='text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3'>Cotizaciones por estado</h3>
          {loadingCotizaciones ? (
            <div className='h-64 flex items-center justify-center text-gray-400'>Cargando...</div>
          ) : (
            <ResponsiveContainer width='100%' height={260}>
              <PieChart>
                <Pie data={cotizaciones} dataKey='total' nameKey='estado' outerRadius={90} label>
                  {cotizaciones.map((entrada) => (
                    <Cell key={entrada.estado} fill={COLORES_ESTADO[entrada.estado] || "#94a3b8"} />
                  ))}
                </Pie>
                <Tooltip />
                <Legend />
              </PieChart>
            </ResponsiveContainer>
          )}
        </Card>

        <Card className='p-4'>
          <h3 className='text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3'>Funnel de conversión</h3>
          {loadingFunnel ? (
            <div className='h-40 flex items-center justify-center text-gray-400'>Cargando...</div>
          ) : funnel ? (
            <div className='space-y-3'>
              <div className='flex justify-between text-sm'>
                <span className='text-gray-600 dark:text-gray-400'>Prospectos creados</span>
                <span className='font-semibold text-gray-900 dark:text-white'>{funnel.prospectosCreados}</span>
              </div>
              <div className='flex justify-between text-sm'>
                <span className='text-gray-600 dark:text-gray-400'>Convertidos a cliente</span>
                <span className='font-semibold text-gray-900 dark:text-white'>{funnel.clientesConvertidos}</span>
              </div>
              <div className='flex justify-between text-sm'>
                <span className='text-gray-600 dark:text-gray-400'>Tasa de conversión</span>
                <span className='font-semibold text-crm-dashboard-end'>{funnel.tasaConversion}%</span>
              </div>
            </div>
          ) : null}
        </Card>

        <Card className='p-4'>
          <h3 className='text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3'>Actividad por día</h3>
          {loadingActividad ? (
            <div className='h-64 flex items-center justify-center text-gray-400'>Cargando...</div>
          ) : (
            <ResponsiveContainer width='100%' height={260}>
              <LineChart data={actividad?.porDia || []}>
                <CartesianGrid strokeDasharray='3 3' />
                <XAxis dataKey='fecha' tick={{ fontSize: 10 }} />
                <YAxis allowDecimals={false} />
                <Tooltip />
                <Line type='monotone' dataKey='total' name='Actividades' stroke='#1d4ed8' strokeWidth={2} />
              </LineChart>
            </ResponsiveContainer>
          )}
        </Card>

        <Card className='p-4 lg:col-span-2'>
          <h3 className='text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3'>Cumplimiento de metas</h3>
          {loadingMetas ? (
            <div className='h-24 flex items-center justify-center text-gray-400'>Cargando...</div>
          ) : metas ? (
            <div className='grid grid-cols-1 md:grid-cols-3 gap-4'>
              <div>
                <p className='text-xs text-gray-500 dark:text-gray-400'>Monto</p>
                <p className='text-sm font-semibold text-gray-900 dark:text-white'>
                  {formatoMoneda(metas.montoReal)} / {formatoMoneda(metas.metaMonto)}
                </p>
                <BarraProgreso meta={metas.metaMonto} real={metas.montoReal} />
              </div>
              <div>
                <p className='text-xs text-gray-500 dark:text-gray-400'>Clientes</p>
                <p className='text-sm font-semibold text-gray-900 dark:text-white'>
                  {metas.clientesReales} / {metas.metaClientes}
                </p>
                <BarraProgreso meta={metas.metaClientes} real={metas.clientesReales} />
              </div>
              <div>
                <p className='text-xs text-gray-500 dark:text-gray-400'>Actividades</p>
                <p className='text-sm font-semibold text-gray-900 dark:text-white'>
                  {metas.actividadesReales} / {metas.metaActividades}
                </p>
                <BarraProgreso meta={metas.metaActividades} real={metas.actividadesReales} />
              </div>
            </div>
          ) : null}
        </Card>

        {puedeVerRanking && !rankingNoAplica && (
          <Card className='p-4 lg:col-span-2'>
            <h3 className='text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3'>Ranking de vendedores</h3>
            <table className='w-full text-sm'>
              <thead>
                <tr className='text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700'>
                  <th className='py-2'>Vendedor</th>
                  <th className='py-2 text-right'>Monto cerrado</th>
                </tr>
              </thead>
              <tbody>
                {ranking.map((fila) => (
                  <tr key={fila.vendedorId} className='border-b border-gray-100 dark:border-gray-800'>
                    <td className='py-2 text-gray-900 dark:text-white'>{fila.nombre}</td>
                    <td className='py-2 text-right font-semibold text-gray-900 dark:text-white'>
                      {formatoMoneda(fila.montoCerrado)}
                    </td>
                  </tr>
                ))}
                {ranking.length === 0 && (
                  <tr>
                    <td colSpan={2} className='py-4 text-center text-gray-400'>Sin datos en este periodo</td>
                  </tr>
                )}
              </tbody>
            </table>
          </Card>
        )}
      </div>
    </motion.div>
  );
}

export default DashboardView;
```

- [ ] **Step 2: Crear el barrel export**

```javascript
export { DashboardView } from "./DashboardView";
```

Ruta: `src/views/crm/dashboard/dashboard/index.js`.

- [ ] **Step 3: Verificar sintácticamente**

Run: `npm run lint -- src/views/crm/dashboard/`
Expected: sin errores nuevos.

- [ ] **Step 4: Verificar que el build no se rompe**

Run: `npm run build`
Expected: build exitoso (el chunk de `DashboardView` aún no es alcanzable desde ninguna ruta hasta la Task 12, pero debe compilar sin errores de sintaxis/import).

- [ ] **Step 5: Commit**

```bash
git add src/views/crm/dashboard/
git commit -m "feat(crm-dashboard): DashboardView (KPIs, pipeline, cotizaciones, funnel, actividad, metas, ranking)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 12: Frontend — registro en `ModuleLoader.jsx` + verificación manual

**Files:**
- Modify: `src/components/workspace/ModuleLoader.jsx`

**Interfaces:**
- Consumes: `DashboardView` (Task 11), export nombrada.
- Produces: ruta `${empresa}/crm/dashboard/dashboard` resuelta en el `REGISTERED_MODULES` del workspace, para las mismas 5 empresas que ya usan Presupuestos/Agenda/Integraciones.

- [ ] **Step 1: Registrar el módulo**

En `src/components/workspace/ModuleLoader.jsx`, agregar (siguiendo el mismo patrón exacto de los bloques de Presupuestos/Agenda, insertado después del bloque de Agenda para mantener el orden de los módulos del seeder):

```jsx
  // CRM · Dashboard (resumen ejecutivo)
  ...Object.fromEntries(
    [
      "grupoesplendido",
      "splendidfarms",
      "splendidbyporvenir",
      "splendid-logistic",
      "canes-agro",
    ].map((emp) => [
      `${emp}/crm/dashboard/dashboard`,
      lazy(() =>
        import("../../views/crm/dashboard/dashboard").then((module) => ({
          default: module.DashboardView,
        })),
      ),
    ]),
  ),
```

- [ ] **Step 2: Verificar sintácticamente y que el build compila**

Run: `npm run lint -- src/components/workspace/ModuleLoader.jsx && npm run build`
Expected: sin errores.

- [ ] **Step 3: Verificación manual guiada (no hay suite de tests de React)**

Con el backend corriendo (Task 9 ya mergeada) y el frontend en dev (`npm run dev`):

1. Iniciar sesión con un usuario que tenga el permiso `dashboard.dashboard.ver` otorgado (usar el seeder/admin para asignarlo) y un `CrmVendedor` propio con al menos una oportunidad/cotización/actividad de prueba.
2. Navegar a `/{empresa}/crm/dashboard/dashboard`.
3. Confirmar que carga la tarjeta de KPIs, los 5 paneles de gráficas, y que el selector de periodo (Mes actual/Mes anterior/Trimestre/Año) dispara un refetch visible (loading states individuales por panel).
4. Confirmar que el panel de "Ranking de vendedores" **no aparece** para este usuario (solo tiene `ver`, no `ejecutivo`).
5. Otorgar además el permiso `ejecutivo` a ese usuario, recargar la página, y confirmar que ahora sí aparece el panel de ranking con datos agregados del equipo.
6. Abrir las herramientas de red del navegador y confirmar que las 7 llamadas a `/api/crm/dashboard/*` se disparan en paralelo (no en cascada) al cargar la vista o cambiar el periodo.
7. Confirmar en modo oscuro (toggle del tema) que los colores `crm-dashboard-start/-end` se ven correctamente sin contraste roto.

- [ ] **Step 4: Commit**

```bash
git add src/components/workspace/ModuleLoader.jsx
git commit -m "feat(crm-dashboard): registrar DashboardView en ModuleLoader

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Resumen de cobertura de la spec

- Los 7 endpoints (§"Los 7 endpoints" de la spec) → Tasks 1-9.
- Patrón de permisos `ver`/`ejecutivo` → Task 9 (`contexto()`, `rankingVendedores()`) con tests dedicados.
- Manejo de errores/edge cases (empresa no resuelta, sin permiso, periodo inválido, sin vendedor propio, sin datos) → Task 9.
- Frontend (vista, hook, routing, paleta) → Tasks 10-12.
- Testing backend → Tasks 1-9 (unitarios de servicio + integración de controller). Testing frontend → verificación manual (Task 12), sin suite automatizada, como el resto del CRM.
- Fuera de alcance (caché, exportar, alertas, rango personalizado, importar `PresupuestoResumenService`) → deliberadamente no tiene tasks; ningún task de este plan lo implementa.
