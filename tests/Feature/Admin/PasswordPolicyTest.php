<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\SettingsService;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function politicaEstricta(): void
    {
        app(SettingsService::class)->update([
            'password.min_length' => 10,
            'password.require_mixed_case' => true,
            'password.require_numbers' => true,
            'password.require_symbols' => true,
        ]);
    }

    private function crearUsuario(string $password)
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        return $this->postJson('/api/users', [
            'name' => 'Nuevo', 'email' => uniqid().'@x.com', 'password' => $password, 'role' => 'user',
        ]);
    }

    public function test_por_defecto_solo_exige_8_caracteres(): void
    {
        $this->crearUsuario('corta')->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'La contraseña debe tener al menos 8 caracteres.');
        $this->crearUsuario('solominusculas')->assertCreated();
    }

    public function test_politica_estricta_rechaza_con_mensajes_en_espanol(): void
    {
        $this->politicaEstricta();

        $errores = $this->crearUsuario('abcdefghij')->assertStatus(422)->json('errors.password');

        $this->assertContains('La contraseña debe incluir al menos una mayúscula y una minúscula.', $errores);
        $this->assertContains('La contraseña debe incluir al menos un número.', $errores);
        $this->assertContains('La contraseña debe incluir al menos un símbolo.', $errores);

        $this->crearUsuario('Abcdefg1!x')->assertCreated();
    }

    public function test_aplica_en_reset_manual_y_en_mi_perfil(): void
    {
        $this->politicaEstricta();
        $usuario = User::factory()->create(['role' => 'user']);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson("/api/users/{$usuario->id}/reset-password", [
            'mode' => 'manual', 'password' => 'debil12345', 'password_confirmation' => 'debil12345', 'force_change' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        Sanctum::actingAs($usuario);
        $this->putJson('/api/profile/password', [
            'current_password' => 'password', 'password' => 'debil12345', 'password_confirmation' => 'debil12345',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->putJson('/api/profile/password', [
            'current_password' => 'password', 'password' => 'Fuerte#2026', 'password_confirmation' => 'Fuerte#2026',
        ])->assertOk();
    }

    public function test_aplica_en_registro(): void
    {
        $this->politicaEstricta();

        $this->postJson('/api/auth/register', [
            'name' => 'X', 'email' => 'reg@x.com', 'password' => 'abcdefghij', 'password_confirmation' => 'abcdefghij',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_contrasena_generada_cumple_la_politica_mas_estricta(): void
    {
        app(SettingsService::class)->update([
            'password.min_length' => 64,
            'password.require_mixed_case' => true,
            'password.require_numbers' => true,
            'password.require_symbols' => true,
        ]);

        for ($i = 0; $i < 20; $i++) {
            $generada = PasswordPolicy::generate();
            $this->assertSame(64, strlen($generada));
            $this->assertTrue(Validator::make(['password' => $generada], ['password' => PasswordPolicy::rule()])->passes());
        }
    }

    public function test_reset_generado_usa_la_politica(): void
    {
        $this->politicaEstricta();
        $usuario = User::factory()->create(['role' => 'user']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $temporal = $this->postJson("/api/users/{$usuario->id}/reset-password", ['mode' => 'generate', 'force_change' => true])
            ->assertOk()->json('data.temporary_password');

        $this->assertSame(12, strlen($temporal));
        $this->assertMatchesRegularExpression('/\p{P}|\p{S}/u', $temporal);
    }

    public function test_endpoint_publico_para_usuarios_con_cambio_obligatorio(): void
    {
        $this->politicaEstricta();
        Sanctum::actingAs(User::factory()->create(['role' => 'user', 'must_change_password' => true]));

        $this->getJson('/api/password-policy')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => [
                'min_length' => 10, 'require_mixed_case' => true, 'require_numbers' => true, 'require_symbols' => true,
            ]]);
    }

    public function test_endpoint_requiere_sesion(): void
    {
        $this->getJson('/api/password-policy')->assertUnauthorized();
    }
}
