<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function intento(string $email, string $password = 'incorrecta')
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_sexto_intento_del_mismo_correo_recibe_429(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->intento($user->email)->assertStatus(422);
        }

        $respuesta = $this->intento($user->email, 'password')->assertStatus(429);
        $respuesta->assertJsonPath('status', 'error');
        $this->assertGreaterThan(0, $respuesta->json('retry_after'));
        $this->assertStringStartsWith('Demasiados intentos. Intenta de nuevo en ', $respuesta->json('message'));
    }

    public function test_otro_correo_desde_la_misma_ip_sigue_entrando(): void
    {
        $bloqueado = User::factory()->create();
        $otro = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->intento($bloqueado->email);
        }

        $this->intento($otro->email, 'password')->assertOk();
    }

    public function test_limite_por_ip_de_20_por_minuto(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->intento("u{$i}@x.com")->assertStatus(422);
        }

        $this->intento('otro@x.com')->assertStatus(429);
    }

    public function test_el_limite_se_libera_tras_un_minuto(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 6; $i++) {
            $this->intento($user->email);
        }

        $this->travel(61)->seconds();

        $this->intento($user->email, 'password')->assertOk();
    }
}
