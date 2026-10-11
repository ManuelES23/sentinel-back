<?php

namespace Tests\Feature\Respaldos;

use App\Contracts\VolcadorBaseDatos;
use App\Models\ActivityLog;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Services\Respaldos\CicloRespaldos;
use App\Services\Respaldos\DatabaseBackupService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Support\VolcadorFalso;
use Tests\TestCase;

class DatabaseBackupApiTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->app->instance(VolcadorBaseDatos::class, new VolcadorFalso);
        $this->superadmin = User::factory()->create(['role' => 'superadmin']);
    }

    private function completado(string $archivo = 'sentinel_a.sql.gz', bool $conArchivo = true): DatabaseBackup
    {
        $b = DatabaseBackup::create([
            'type' => 'manual', 'status' => 'completado', 'filename' => $archivo,
            'size_bytes' => 3, 'started_at' => now()->subSeconds(5), 'finished_at' => now(),
            'created_by' => $this->superadmin->id,
        ]);
        if ($conArchivo) {
            Storage::disk('local')->put("backups/{$archivo}", 'gz!');
        }

        return $b;
    }

    public function test_admin_y_usuario_reciben_403_en_todas_las_rutas(): void
    {
        $b = $this->completado();
        foreach (['user', 'admin'] as $rol) {
            Sanctum::actingAs(User::factory()->create(['role' => $rol]));
            $this->getJson('/api/admin/backups')->assertForbidden();
            $this->getJson('/api/admin/backups/status')->assertForbidden();
            $this->postJson('/api/admin/backups')->assertForbidden();
            $this->putJson('/api/admin/backups/config', ['enabled' => true, 'hour' => 1, 'keep_scheduled' => 3])->assertForbidden();
            $this->postJson("/api/admin/backups/{$b->id}/download-url")->assertForbidden();
            $this->deleteJson("/api/admin/backups/{$b->id}")->assertForbidden();
        }
        $this->assertSame(1, DatabaseBackup::count());
    }

    public function test_lista_paginada_con_creador_y_disponibilidad(): void
    {
        $this->freezeTime();
        $con = $this->completado('con.sql.gz');
        $sin = $this->completado('sin.sql.gz', conArchivo: false);
        Sanctum::actingAs($this->superadmin);

        $this->getJson('/api/admin/backups')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $sin->id)
            ->assertJsonPath('data.0.archivo_disponible', false)
            ->assertJsonPath('data.1.archivo_disponible', true)
            ->assertJsonPath('data.1.creador.name', $this->superadmin->name)
            ->assertJsonPath('data.1.duracion_segundos', 5)
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_generar_crea_pendiente_y_bloquea_el_segundo(): void
    {
        Sanctum::actingAs($this->superadmin);

        $this->postJson('/api/admin/backups')
            ->assertCreated()
            ->assertJsonPath('data.status', 'pendiente')
            ->assertJsonPath('data.type', 'manual')
            ->assertJsonPath('data.created_by', $this->superadmin->id);

        $this->postJson('/api/admin/backups')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Ya hay un respaldo en curso. Espera a que termine.');

        $this->assertSame(1, DatabaseBackup::count());
        $this->assertTrue(ActivityLog::where('action', 'backup_create')->exists());
    }

    public function test_status(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 12:00:00', config('app.timezone')));
        $ok = $this->completado();
        $fallo = DatabaseBackup::create(['type' => 'programado', 'status' => 'fallido', 'error' => 'Espacio insuficiente en el servidor para generar el respaldo.', 'finished_at' => now()]);
        app(CicloRespaldos::class)->registrarLatido();
        Sanctum::actingAs($this->superadmin);

        $this->getJson('/api/admin/backups/status')
            ->assertOk()
            ->assertJsonPath('data.ultimo_completado.id', $ok->id)
            ->assertJsonPath('data.ultimo_fallido_reciente.id', $fallo->id)
            ->assertJsonPath('data.en_curso', [])
            ->assertJsonPath('data.scheduler_activo', true)
            ->assertJsonPath('data.config.hour', 2)
            ->assertJsonPath('data.proximo_programado', Carbon::parse('2026-10-11 02:00:00', config('app.timezone'))->toIso8601String());

        // Uno posterior que sale bien apaga el aviso rojo.
        $this->completado('despues.sql.gz');
        $this->getJson('/api/admin/backups/status')->assertJsonPath('data.ultimo_fallido_reciente', null);
    }

    public function test_config_valida_y_guarda(): void
    {
        Sanctum::actingAs($this->superadmin);

        $this->putJson('/api/admin/backups/config', ['enabled' => true, 'hour' => 24, 'keep_scheduled' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hour', 'keep_scheduled']);

        $this->putJson('/api/admin/backups/config', ['enabled' => false, 'hour' => 4, 'keep_scheduled' => 14])
            ->assertOk()
            ->assertJsonPath('data', ['enabled' => false, 'hour' => 4, 'keep_scheduled' => 14])
            ->assertJsonPath('message', 'Configuración de respaldos guardada.');

        $this->assertTrue(ActivityLog::where('action', 'backup_config_update')->exists());
    }

    public function test_descarga_con_url_firmada_relativa(): void
    {
        $b = $this->completado();
        Sanctum::actingAs($this->superadmin);

        $url = $this->postJson("/api/admin/backups/{$b->id}/download-url")
            ->assertOk()
            ->json('data.url');
        $this->assertTrue(ActivityLog::where('action', 'backup_download')->exists());

        // El backend devuelve solo la ruta firmada: el front la resuelve contra el origen de la API.
        $this->assertStringStartsWith('/api/backups/', $url);
        $relativa = $url;
        $this->app['auth']->forgetGuards();
        $respuesta = $this->get($relativa);
        $respuesta->assertOk();
        $this->assertSame('gz!', $respuesta->streamedContent());
        $this->assertStringContainsString('sentinel_a.sql.gz', $respuesta->headers->get('content-disposition'));
    }

    public function test_firma_alterada_o_vencida_da_403(): void
    {
        $b = $this->completado();
        $relativa = URL::temporarySignedRoute('backups.download', now()->addMinutes(5), ['backup' => $b->id], absolute: false);

        $this->get($relativa.'x')->assertForbidden();

        $this->travel(6)->minutes();
        $this->get($relativa)->assertForbidden();
    }

    public function test_descarga_de_archivo_inexistente_o_no_completado(): void
    {
        $sinArchivo = $this->completado('borrado.sql.gz', conArchivo: false);
        $pendiente = DatabaseBackup::create(['type' => 'manual', 'status' => 'pendiente']);
        Sanctum::actingAs($this->superadmin);

        $this->postJson("/api/admin/backups/{$sinArchivo->id}/download-url")
            ->assertNotFound()
            ->assertJsonPath('message', 'El archivo ya no existe en el servidor.');
        $this->postJson("/api/admin/backups/{$pendiente->id}/download-url")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este respaldo no está disponible para descargar.');

        $relativa = URL::temporarySignedRoute('backups.download', now()->addMinutes(5), ['backup' => $sinArchivo->id], absolute: false);
        $this->get($relativa)->assertNotFound();
    }

    public function test_eliminar(): void
    {
        $b = $this->completado();
        $enProceso = DatabaseBackup::create(['type' => 'manual', 'status' => 'en_proceso', 'started_at' => now()]);
        Sanctum::actingAs($this->superadmin);

        $this->deleteJson("/api/admin/backups/{$enProceso->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'No se puede eliminar un respaldo que se está generando.');

        $this->deleteJson("/api/admin/backups/{$b->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Respaldo eliminado.');
        $this->assertNull($b->fresh());
        Storage::disk('local')->assertMissing('backups/sentinel_a.sql.gz');
        $this->assertTrue(ActivityLog::where('action', 'backup_delete')->exists());
    }

    public function test_si_el_archivo_no_se_pudo_borrar_no_se_reporta_eliminado(): void
    {
        // Simula un borrado que falla en silencio (permisos): el archivo sigue en disco.
        $this->app->instance(CicloRespaldos::class, new class(app(DatabaseBackupService::class), app(SettingsService::class)) extends CicloRespaldos {
            protected function borrarArchivo(string $rutaRelativa): void {}
        });
        $b = $this->completado();
        Sanctum::actingAs($this->superadmin);

        $this->deleteJson("/api/admin/backups/{$b->id}")
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No se pudo borrar el archivo del servidor. Revisa los permisos de storage/app/private/backups.');

        $this->assertNotNull($b->fresh());
        Storage::disk('local')->assertExists('backups/sentinel_a.sql.gz');
        $this->assertFalse(ActivityLog::where('action', 'backup_delete')->exists());
    }

    public function test_si_la_carpeta_no_es_accesible_no_se_da_el_archivo_por_borrado(): void
    {
        // Simula que el usuario web no puede entrar a la carpeta (exists() daría false aunque el archivo siga ahí).
        $this->app->instance(CicloRespaldos::class, new class(app(DatabaseBackupService::class), app(SettingsService::class)) extends CicloRespaldos {
            protected function directorioAccesible(): bool
            {
                return false;
            }
        });
        $b = $this->completado();
        Sanctum::actingAs($this->superadmin);

        $this->deleteJson("/api/admin/backups/{$b->id}")
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No se pudo borrar el archivo del servidor. Revisa los permisos de storage/app/private/backups.');

        $this->assertNotNull($b->fresh());
        Storage::disk('local')->assertExists('backups/sentinel_a.sql.gz');
        $this->assertFalse(ActivityLog::where('action', 'backup_delete')->exists());
    }

    public function test_url_firmada_de_un_respaldo_no_sirve_para_otro(): void
    {
        $a = $this->completado('a.sql.gz');
        $b = $this->completado('b.sql.gz');
        Sanctum::actingAs($this->superadmin);

        $url = $this->postJson("/api/admin/backups/{$a->id}/download-url")->json('data.url');
        $this->app['auth']->forgetGuards();

        $cambiada = str_replace("/api/backups/{$a->id}/download", "/api/backups/{$b->id}/download", $url);
        $this->assertNotSame($url, $cambiada);
        $this->get($cambiada)->assertForbidden();
        $this->get($url)->assertOk();
    }

    public function test_sin_autenticar_da_401(): void
    {
        $this->getJson('/api/admin/backups')->assertUnauthorized();
        $this->postJson('/api/admin/backups')->assertUnauthorized();
        $this->assertSame(0, DatabaseBackup::count());
    }
}
