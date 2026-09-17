<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Usa tokens reales (no Sanctum::actingAs) porque la caducidad se decide
 * en el guard de Sanctum.
 */
class SessionIdleTest extends TestCase
{
    use RefreshDatabase;

    private function pedirUsuario(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/auth/user');
    }

    public function test_token_activo_funciona_e_informa_el_limite(): void
    {
        $token = User::factory()->create()->createToken('auth-token')->plainTextToken;

        $this->pedirUsuario($token)
            ->assertOk()
            ->assertJsonPath('user.session_idle_minutes', 120);
    }

    public function test_token_inactivo_mas_del_limite_da_401(): void
    {
        $token = User::factory()->create()->createToken('auth-token')->plainTextToken;

        $this->travel(119)->minutes();
        $this->pedirUsuario($token)->assertOk(); // renueva last_used_at

        $this->travel(119)->minutes();
        $this->pedirUsuario($token)->assertOk();

        $this->travel(121)->minutes();
        $this->pedirUsuario($token)->assertUnauthorized();
    }

    public function test_bajar_el_limite_afecta_a_tokens_existentes(): void
    {
        $token = User::factory()->create()->createToken('auth-token')->plainTextToken;
        $this->pedirUsuario($token)->assertOk();

        $this->travel(20)->minutes();
        app(SettingsService::class)->update(['session.idle_minutes' => 15]);

        $this->pedirUsuario($token)->assertUnauthorized();
    }

    public function test_login_devuelve_el_limite(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.session_idle_enabled', true)
            ->assertJsonPath('user.session_idle_minutes', 120);
    }

    public function test_desactivar_el_interruptor_mantiene_activo_un_token_inactivo(): void
    {
        $token = User::factory()->create()->createToken('auth-token')->plainTextToken;
        app(SettingsService::class)->update(['session.idle_enabled' => false]);

        $this->travel(200)->minutes();

        $this->pedirUsuario($token)
            ->assertOk()
            ->assertJsonPath('user.session_idle_enabled', false);
    }
}
