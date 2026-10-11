<?php

namespace Tests\Feature\Respaldos;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackupSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_valores_por_defecto(): void
    {
        $this->assertSame(
            ['enabled' => true, 'hour' => 2, 'keep_scheduled' => 7],
            app(SettingsService::class)->backupConfig(),
        );
    }

    public function test_el_endpoint_general_no_expone_ni_acepta_backups(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/admin/settings')->assertOk()->assertJsonMissingPath('data.backups');

        $this->putJson('/api/admin/settings', ['backups' => ['enabled' => false, 'hour' => 5]])->assertOk();

        $this->assertSame(
            ['enabled' => true, 'hour' => 2, 'keep_scheduled' => 7],
            app(SettingsService::class)->backupConfig(),
        );
    }

    public function test_update_guarda_backups_cuando_se_llama_directo(): void
    {
        $s = app(SettingsService::class);
        $s->update(['backups.enabled' => false, 'backups.hour' => 23, 'backups.keep_scheduled' => 30]);

        $this->assertSame(['enabled' => false, 'hour' => 23, 'keep_scheduled' => 30], $s->backupConfig());
    }

    public function test_middleware_superadmin(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'admin', 'admin:superadmin'])
            ->get('/api/_prueba_superadmin', fn () => response()->json(['ok' => true]));

        Sanctum::actingAs(User::factory()->create(['role' => 'user']));
        $this->getJson('/api/_prueba_superadmin')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/_prueba_superadmin')
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo los superadministradores pueden gestionar respaldos.');

        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));
        $this->getJson('/api/_prueba_superadmin')->assertOk();
    }
}
