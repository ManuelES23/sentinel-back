<?php

namespace Tests\Feature\Admin;

use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Jerarquía del panel de usuarios: superadmin gestiona a todos, admin
 * gestiona user/admin pero nunca superadmin, y nadie se borra ni cambia su
 * propio rol. Además, editar sin contraseña ya no revienta con 422.
 */
class UserManagementHierarchyTest extends TestCase
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

    public function test_editar_sin_contrasena_no_falla_y_no_la_cambia(): void
    {
        Sanctum::actingAs($this->admin);
        $hashAnterior = $this->usuario->password;

        // El front mandaba password: "" y ConvertEmptyStringsToNull lo volvía null → 422.
        $this->putJson("/api/users/{$this->usuario->id}", ['name' => 'Nombre Nuevo', 'password' => ''])
            ->assertOk();

        $this->assertSame('Nombre Nuevo', $this->usuario->fresh()->name);
        $this->assertSame($hashAnterior, $this->usuario->fresh()->password);
    }

    public function test_la_contrasena_enviada_al_editar_se_ignora(): void
    {
        Sanctum::actingAs($this->admin);
        $hashAnterior = $this->usuario->password;

        $this->putJson("/api/users/{$this->usuario->id}", ['password' => 'OtraClave123'])->assertOk();

        $this->assertSame($hashAnterior, $this->usuario->fresh()->password);
    }

    public function test_admin_no_puede_crear_un_superadmin_pero_un_superadmin_si(): void
    {
        $payload = fn (string $email) => ['name' => 'Nuevo', 'email' => $email, 'password' => 'password123', 'role' => 'superadmin'];

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/users', $payload('a@example.com'))
            ->assertStatus(403)
            ->assertJsonPath('status', 'error');
        $this->assertDatabaseMissing('users', ['email' => 'a@example.com']);

        Sanctum::actingAs($this->superadmin);
        $this->postJson('/api/users', $payload('b@example.com'))->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'b@example.com', 'role' => 'superadmin']);
    }

    public function test_admin_puede_crear_otro_admin(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/users', ['name' => 'Otro', 'email' => 'otro@example.com', 'password' => 'password123', 'role' => 'admin'])
            ->assertCreated();
    }

    public function test_admin_no_puede_ascender_a_nadie_a_superadmin(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/users/{$this->usuario->id}", ['role' => 'superadmin'])->assertStatus(403);
        $this->assertSame('user', $this->usuario->fresh()->role);
    }

    public function test_admin_no_puede_editar_ni_borrar_a_un_superadmin(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/users/{$this->superadmin->id}", ['name' => 'Hackeado'])->assertStatus(403);
        $this->deleteJson("/api/users/{$this->superadmin->id}")->assertStatus(403);

        $this->assertNotSame('Hackeado', $this->superadmin->fresh()->name);
    }

    public function test_superadmin_puede_editar_a_otro_superadmin(): void
    {
        $otro = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($this->superadmin);

        $this->putJson("/api/users/{$otro->id}", ['name' => 'Editado', 'role' => 'admin'])->assertOk();

        $this->assertSame('admin', $otro->fresh()->role);
    }

    public function test_nadie_puede_cambiar_su_propio_rol_pero_si_sus_datos(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/users/{$this->admin->id}", ['role' => 'user'])->assertStatus(403);
        $this->assertSame('admin', $this->admin->fresh()->role);

        // Mismo rol que ya tiene + otros datos: permitido.
        $this->putJson("/api/users/{$this->admin->id}", ['name' => 'Yo Mismo', 'role' => 'admin'])->assertOk();
        $this->assertSame('Yo Mismo', $this->admin->fresh()->name);
    }

    public function test_nadie_puede_borrar_su_propia_cuenta(): void
    {
        Sanctum::actingAs($this->superadmin);

        $this->deleteJson("/api/users/{$this->superadmin->id}")->assertStatus(403);
        $this->assertNotNull($this->superadmin->fresh());
    }

    public function test_admin_puede_borrar_a_un_usuario(): void
    {
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/users/{$this->usuario->id}")->assertOk();
        $this->assertNull($this->usuario->fresh());
    }

    public function test_listado_cuenta_empresas_desde_user_enterprise_access_y_expone_el_flag(): void
    {
        $empresa = Enterprise::create([
            'name' => 'Empresa Conteo', 'slug' => 'empresa-conteo',
            'description' => 'Prueba', 'is_active' => true,
        ]);
        UserEnterpriseAccess::create(['user_id' => $this->usuario->id, 'enterprise_id' => $empresa->id, 'is_active' => true]);
        $this->usuario->update(['must_change_password' => true]);

        Sanctum::actingAs($this->admin);
        $fila = collect($this->getJson('/api/users')->assertOk()->json())->firstWhere('id', $this->usuario->id);

        $this->assertSame([$empresa->id], $fila['permissions']['enterprises']);
        $this->assertTrue($fila['must_change_password']);
    }
}
