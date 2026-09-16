<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $admin;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = User::factory()->create(['role' => 'superadmin']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->usuario = User::factory()->create(['role' => 'user']);
    }

    private function url(User $target): string
    {
        return "/api/users/{$target->id}/reset-password";
    }

    private function manual(string $password = 'NuevaClave123', bool $forzar = false): array
    {
        return ['mode' => 'manual', 'password' => $password, 'password_confirmation' => $password, 'force_change' => $forzar];
    }

    public function test_admin_restablece_manual_cierra_sesiones_y_la_nueva_contrasena_funciona(): void
    {
        $this->usuario->createToken('sesion-previa');
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url($this->usuario), $this->manual())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.must_change_password', false)
            ->assertJsonMissingPath('data.temporary_password');

        $fresco = $this->usuario->fresh();
        $this->assertTrue(Hash::check('NuevaClave123', $fresco->password));
        $this->assertFalse($fresco->must_change_password);
        $this->assertSame(0, $fresco->tokens()->count());
    }

    public function test_manual_con_cambio_obligatorio_marca_el_flag(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url($this->usuario), $this->manual('NuevaClave123', true))
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);

        $this->assertTrue($this->usuario->fresh()->must_change_password);
    }

    public function test_modo_generate_devuelve_temporal_de_12_caracteres_que_funciona(): void
    {
        Sanctum::actingAs($this->admin);

        $respuesta = $this->postJson($this->url($this->usuario), ['mode' => 'generate', 'force_change' => true])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);

        $temporal = $respuesta->json('data.temporary_password');
        $this->assertIsString($temporal);
        $this->assertSame(12, strlen($temporal));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $temporal);
        $this->assertTrue(Hash::check($temporal, $this->usuario->fresh()->password));
    }

    public function test_manual_valida_longitud_y_confirmacion(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url($this->usuario), $this->manual('corta'))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson($this->url($this->usuario), [
            'mode' => 'manual', 'password' => 'NuevaClave123', 'password_confirmation' => 'Distinta123', 'force_change' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson($this->url($this->usuario), ['mode' => 'otro', 'force_change' => false])
            ->assertStatus(422)->assertJsonValidationErrors('mode');
    }

    public function test_admin_no_puede_restablecer_a_un_superadmin(): void
    {
        $hashAnterior = $this->superadmin->password;
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url($this->superadmin), $this->manual())
            ->assertStatus(403)
            ->assertJsonPath('status', 'error');

        $this->assertSame($hashAnterior, $this->superadmin->fresh()->password);
    }

    public function test_superadmin_puede_restablecer_a_otro_superadmin(): void
    {
        $otro = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($this->superadmin);

        $this->postJson($this->url($otro), $this->manual())->assertOk();
    }

    public function test_nadie_puede_restablecer_su_propia_contrasena_desde_el_panel(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url($this->admin), $this->manual())->assertStatus(403);
    }

    public function test_usuario_normal_recibe_403(): void
    {
        $otro = User::factory()->create(['role' => 'user']);
        Sanctum::actingAs($this->usuario);

        $this->postJson($this->url($otro), $this->manual())->assertStatus(403);
    }

    public function test_el_log_registra_el_evento_sin_la_contrasena(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url($this->usuario), $this->manual('ClaveSecreta999', true))->assertOk();

        $log = ActivityLog::where('action', 'password_reset')->where('model_id', $this->usuario->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(['mode' => 'manual', 'force_change' => true], $log->new_values);
        $this->assertStringNotContainsString('ClaveSecreta999', json_encode($log->toArray()));
    }
}
