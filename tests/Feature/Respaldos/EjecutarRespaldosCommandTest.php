<?php

namespace Tests\Feature\Respaldos;

use App\Contracts\VolcadorBaseDatos;
use App\Models\DatabaseBackup;
use App\Services\Respaldos\CicloRespaldos;
use App\Services\Respaldos\DatabaseBackupService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\VolcadorFalso;
use Tests\TestCase;

class EjecutarRespaldosCommandTest extends TestCase
{
    use RefreshDatabase;

    private VolcadorFalso $volcador;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->volcador = new VolcadorFalso;
        $this->app->instance(VolcadorBaseDatos::class, $this->volcador);
    }

    private function a(string $fechaHora): void
    {
        $this->travelTo(Carbon::parse($fechaHora, config('app.timezone')));
    }

    public function test_no_crea_el_programado_antes_de_la_hora(): void
    {
        $this->a('2026-10-10 01:59:00');
        $this->artisan('respaldos:ejecutar')->assertSuccessful();

        $this->assertSame(0, DatabaseBackup::count());
    }

    public function test_crea_y_genera_el_programado_despues_de_la_hora(): void
    {
        $this->a('2026-10-10 02:00:30');
        $this->artisan('respaldos:ejecutar')->assertSuccessful();

        $b = DatabaseBackup::sole();
        $this->assertSame(DatabaseBackup::TIPO_PROGRAMADO, $b->type);
        $this->assertSame(DatabaseBackup::ESTADO_COMPLETADO, $b->status);
        $this->assertNull($b->created_by);
    }

    public function test_un_programado_fallido_no_se_reintenta_el_mismo_dia(): void
    {
        $this->volcador->falla = new RuntimeException('caída');
        $this->a('2026-10-10 02:00:00');
        $this->artisan('respaldos:ejecutar');
        $this->a('2026-10-10 02:01:00');
        $this->artisan('respaldos:ejecutar');
        $this->a('2026-10-10 15:00:00');
        $this->artisan('respaldos:ejecutar');

        $this->assertSame(1, DatabaseBackup::count());
        $this->assertSame(1, $this->volcador->llamadas);

        $this->volcador->falla = null;
        $this->a('2026-10-11 02:00:00');
        $this->artisan('respaldos:ejecutar');
        $this->assertSame(2, DatabaseBackup::count());
    }

    public function test_desactivado_no_crea_programado(): void
    {
        app(SettingsService::class)->update(['backups.enabled' => false]);
        $this->a('2026-10-10 03:00:00');
        $this->artisan('respaldos:ejecutar');

        $this->assertSame(0, DatabaseBackup::count());
    }

    public function test_procesa_pendientes_manuales_en_orden(): void
    {
        app(SettingsService::class)->update(['backups.enabled' => false]);
        $a = DatabaseBackup::create(['type' => 'manual', 'status' => 'pendiente']);
        $b = DatabaseBackup::create(['type' => 'manual', 'status' => 'pendiente']);

        $this->artisan('respaldos:ejecutar');

        $this->assertSame('completado', $a->fresh()->status);
        $this->assertSame('completado', $b->fresh()->status);
        $this->assertLessThanOrEqual($b->fresh()->started_at, $a->fresh()->started_at);
    }

    public function test_marca_fallidos_los_atascados_de_mas_de_dos_horas(): void
    {
        app(SettingsService::class)->update(['backups.enabled' => false]);
        $this->a('2026-10-10 12:00:00');
        $viejo = DatabaseBackup::create(['type' => 'manual', 'status' => 'en_proceso', 'filename' => 'v.sql.gz', 'started_at' => now()->subMinutes(121)]);
        $reciente = DatabaseBackup::create(['type' => 'manual', 'status' => 'en_proceso', 'filename' => 'r.sql.gz', 'started_at' => now()->subMinutes(30)]);
        Storage::disk('local')->put('backups/v/v.sql.gz.partial', 'x');

        $this->artisan('respaldos:ejecutar');

        $this->assertSame('fallido', $viejo->fresh()->status);
        $this->assertSame('El proceso de respaldo se interrumpió (tiempo excedido).', $viejo->fresh()->error);
        Storage::disk('local')->assertMissing('backups/v/v.sql.gz.partial');
        $this->assertSame('en_proceso', $reciente->fresh()->status);
    }

    public function test_retencion_conserva_n_programados_y_no_toca_manuales(): void
    {
        app(SettingsService::class)->update(['backups.enabled' => false, 'backups.keep_scheduled' => 2]);
        $this->a('2026-10-10 12:00:00');
        $programados = collect(range(1, 4))->map(function ($i) {
            $b = DatabaseBackup::create(['type' => 'programado', 'status' => 'completado', 'filename' => "p{$i}.sql.gz"]);
            Storage::disk('local')->put("backups/p{$i}.sql.gz", 'x');

            return $b;
        });
        $manual = DatabaseBackup::create(['type' => 'manual', 'status' => 'completado', 'filename' => 'm.sql.gz']);
        Storage::disk('local')->put('backups/m.sql.gz', 'x');
        $fallidoViejo = DatabaseBackup::create(['type' => 'manual', 'status' => 'fallido']);
        $fallidoViejo->forceFill(['created_at' => now()->subDays(31)])->save();
        $fallidoNuevo = DatabaseBackup::create(['type' => 'manual', 'status' => 'fallido']);

        $this->artisan('respaldos:ejecutar');

        $this->assertNull($programados[0]->fresh());
        $this->assertNull($programados[1]->fresh());
        Storage::disk('local')->assertMissing('backups/p1.sql.gz');
        Storage::disk('local')->assertMissing('backups/p2.sql.gz');
        $this->assertNotNull($programados[2]->fresh());
        $this->assertNotNull($programados[3]->fresh());
        $this->assertNotNull($manual->fresh());
        Storage::disk('local')->assertExists('backups/m.sql.gz');
        $this->assertNull($fallidoViejo->fresh());
        $this->assertNotNull($fallidoNuevo->fresh());
    }

    public function test_retencion_no_lanza_y_conserva_la_fila_si_el_archivo_no_se_puede_borrar(): void
    {
        app(SettingsService::class)->update(['backups.enabled' => false, 'backups.keep_scheduled' => 1]);
        $this->a('2026-10-10 12:00:00');
        $viejo = DatabaseBackup::create(['type' => 'programado', 'status' => 'completado', 'filename' => 'viejo.sql.gz']);
        Storage::disk('local')->put('backups/viejo.sql.gz', 'x');
        $nuevo = DatabaseBackup::create(['type' => 'programado', 'status' => 'completado', 'filename' => 'nuevo.sql.gz']);
        Storage::disk('local')->put('backups/nuevo.sql.gz', 'x');

        // Simula un borrado que falla en silencio (permisos): el archivo sigue en disco.
        $ciclo = new class(app(DatabaseBackupService::class), app(SettingsService::class)) extends CicloRespaldos {
            protected function borrarArchivo(string $rutaRelativa): void {}
        };
        $ciclo->aplicarRetencion();

        // La fila queda para reintentar al minuto siguiente.
        $this->assertNotNull($viejo->fresh());
        Storage::disk('local')->assertExists('backups/viejo.sql.gz');
        $this->assertNotNull($nuevo->fresh());
    }

    public function test_latido_y_programador_activo(): void
    {
        $ciclo = app(CicloRespaldos::class);
        $this->assertFalse($ciclo->programadorActivo());

        $this->a('2026-10-10 12:00:00');
        $this->artisan('respaldos:ejecutar');
        $this->assertTrue(app(CicloRespaldos::class)->programadorActivo());
        $this->assertNotNull(Cache::get(CicloRespaldos::LATIDO_CACHE_KEY));
        $this->assertSame('2026-10-10 12:00:00', app(CicloRespaldos::class)->ultimoLatido()->format('Y-m-d H:i:s'));

        $this->a('2026-10-10 12:06:00');
        $this->assertFalse(app(CicloRespaldos::class)->programadorActivo());
    }

    public function test_programador_activo_mientras_hay_un_volcado_en_curso(): void
    {
        $this->a('2026-10-10 12:00:00');
        app(CicloRespaldos::class)->registrarLatido();
        $this->a('2026-10-10 12:10:00');
        DatabaseBackup::create(['type' => 'manual', 'status' => 'en_proceso', 'filename' => 'x.sql.gz', 'started_at' => now()->subMinutes(10)]);

        $this->assertTrue(app(CicloRespaldos::class)->programadorActivo());
    }

    public function test_programador_inactivo_si_el_volcado_en_curso_esta_atascado(): void
    {
        $this->a('2026-10-10 12:00:00');
        app(CicloRespaldos::class)->registrarLatido();
        $this->a('2026-10-10 15:00:00');
        DatabaseBackup::create(['type' => 'manual', 'status' => 'en_proceso', 'filename' => 'x.sql.gz', 'started_at' => now()->subHours(3)]);

        $this->assertFalse(app(CicloRespaldos::class)->programadorActivo());
    }

    public function test_proximo_programado(): void
    {
        $ciclo = app(CicloRespaldos::class);

        $this->a('2026-10-10 01:00:00');
        $this->assertSame('2026-10-10 02:00', $ciclo->proximoProgramado()->format('Y-m-d H:i'));

        $this->a('2026-10-10 03:00:00');
        $this->assertSame('2026-10-11 02:00', $ciclo->proximoProgramado()->format('Y-m-d H:i'));

        app(SettingsService::class)->update(['backups.enabled' => false]);
        $this->assertNull(app(CicloRespaldos::class)->proximoProgramado());
    }

    public function test_opcion_ahora_crea_y_procesa_un_manual(): void
    {
        $this->a('2026-10-10 01:00:00');
        $this->artisan('respaldos:ejecutar', ['--ahora' => true])->assertSuccessful();

        $b = DatabaseBackup::sole();
        $this->assertSame('manual', $b->type);
        $this->assertSame('completado', $b->status);
    }

    public function test_esta_programado_cada_minuto(): void
    {
        // schedule:list carga routes/console.php; desde una prueba HTTP el Schedule puede estar vacío.
        $this->artisan('schedule:list')
            ->expectsOutputToContain('respaldos:ejecutar')
            ->assertSuccessful();
    }
}
