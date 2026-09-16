<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasswordChangeRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_marcado_queda_bloqueado_hasta_cambiar_su_contrasena(): void
    {
        $usuario = User::factory()->create(['role' => 'user', 'must_change_password' => true]);
        Sanctum::actingAs($usuario);

        $this->getJson('/api/enterprises')
            ->assertStatus(403)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'password_change_required');

        $this->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);

        $this->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'ClaveNueva123',
            'password_confirmation' => 'ClaveNueva123',
        ])->assertOk();

        $this->assertFalse($usuario->fresh()->must_change_password);

        $this->getJson('/api/enterprises')->assertOk();
    }

    public function test_admin_marcado_tambien_queda_bloqueado_en_el_panel(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'must_change_password' => true]));

        $this->getJson('/api/users')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');
    }

    public function test_usuario_sin_flag_no_se_ve_afectado(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson('/api/enterprises')->assertOk();
    }

    public function test_login_de_un_usuario_marcado_no_se_bloquea(): void
    {
        $usuario = User::factory()->create(['role' => 'user', 'must_change_password' => true]);

        $this->postJson('/api/auth/login', ['email' => $usuario->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);
    }

    public function test_logout_de_un_usuario_marcado_no_se_bloquea(): void
    {
        $usuario = User::factory()->create(['role' => 'user', 'must_change_password' => true]);
        $token = $usuario->createToken('auth-token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk();
    }
}
