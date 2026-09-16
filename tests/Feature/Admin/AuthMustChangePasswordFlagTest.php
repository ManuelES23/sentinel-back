<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthMustChangePasswordFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_expone_el_flag_de_cambio_obligatorio(): void
    {
        $usuario = User::factory()->create(['role' => 'user', 'must_change_password' => true]);

        $this->postJson('/api/auth/login', ['email' => $usuario->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);
    }

    public function test_auth_user_expone_el_flag_en_false_por_defecto(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('user.must_change_password', false);
    }

    public function test_is_superadmin(): void
    {
        $this->assertTrue(User::factory()->make(['role' => 'superadmin'])->isSuperadmin());
        $this->assertFalse(User::factory()->make(['role' => 'admin'])->isSuperadmin());
    }
}
