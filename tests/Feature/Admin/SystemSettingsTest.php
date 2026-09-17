<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_usuario_normal_no_puede_ver_ni_guardar(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson('/api/admin/settings')->assertForbidden();
        $this->putJson('/api/admin/settings', ['session' => ['idle_minutes' => 30]])->assertForbidden();
    }

    public function test_admin_lee_valores_por_defecto_sin_contrasena(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session.idle_minutes', 120)
            ->assertJsonPath('data.password.min_length', 8)
            ->assertJsonPath('data.mail.enabled', false)
            ->assertJsonPath('data.mail.password_set', false)
            ->assertJsonMissingPath('data.mail.password');
    }

    public function test_guarda_y_devuelve_los_valores_nuevos(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/admin/settings', [
            'session' => ['idle_minutes' => 30],
            'password' => ['min_length' => 10, 'require_numbers' => true],
            'mail' => ['enabled' => true, 'host' => 'smtp.x.com', 'from_address' => 'no-reply@x.com', 'password' => 'secreta'],
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Ajustes guardados.')
            ->assertJsonPath('data.session.idle_minutes', 30)
            ->assertJsonPath('data.password.require_numbers', true)
            ->assertJsonPath('data.mail.password_set', true)
            ->assertJsonMissingPath('data.mail.password');

        $this->assertStringNotContainsString('secreta', $this->getJson('/api/admin/settings')->getContent());
    }

    public function test_valida_rangos_y_smtp_activo_sin_servidor(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/admin/settings', [
            'session' => ['idle_minutes' => 2],
            'password' => ['min_length' => 100],
            'mail' => ['enabled' => true, 'encryption' => 'starttls'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'session.idle_minutes', 'password.min_length', 'mail.encryption', 'mail.host', 'mail.from_address',
            ]);
    }

    public function test_audita_solo_cambios_y_oculta_la_contrasena(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/admin/settings', [
            'session' => ['idle_minutes' => 120],
            'password' => ['min_length' => 12],
            'mail' => ['password' => 'secreta'],
        ])->assertOk();

        $log = ActivityLog::where('action', 'settings_update')->sole();
        $this->assertSame('system_settings', $log->model);
        $this->assertSame(['password.min_length' => 8, 'mail.password' => '***'], $log->old_values);
        $this->assertSame(['password.min_length' => 12, 'mail.password' => '***'], $log->new_values);
        $this->assertStringNotContainsString('secreta', json_encode($log->toArray()));
    }

    public function test_guardar_sin_cambios_no_audita(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/admin/settings', ['session' => ['idle_minutes' => 120]])->assertOk();

        $this->assertSame(0, ActivityLog::where('action', 'settings_update')->count());
    }
}
