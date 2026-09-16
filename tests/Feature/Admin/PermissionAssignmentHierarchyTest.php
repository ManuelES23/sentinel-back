<?php

namespace Tests\Feature\Admin;

use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La jerarquía de usuarios también rige la asignación de permisos: un admin
 * no puede quitar ni dar accesos a un superadmin (lo dejaría fuera del
 * workspace), pero sí gestionar los de user/admin.
 */
class PermissionAssignmentHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $admin;
    private User $usuario;
    private Enterprise $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = User::factory()->create(['role' => 'superadmin']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->usuario = User::factory()->create(['role' => 'user']);
        $this->empresa = Enterprise::create([
            'name' => 'Empresa Jerarquía',
            'slug' => 'empresa-jerarquia',
            'description' => 'Prueba',
            'is_active' => true,
        ]);

        UserEnterpriseAccess::create([
            'user_id' => $this->superadmin->id,
            'enterprise_id' => $this->empresa->id,
            'is_active' => true,
        ]);
    }

    private function revocarEmpresa(User $objetivo)
    {
        return $this->postJson("/api/users/{$objetivo->id}/hierarchical-permissions/enterprise", [
            'enterprise_id' => $this->empresa->id,
            'is_active' => false,
        ]);
    }

    public function test_admin_no_puede_revocar_accesos_de_un_superadmin(): void
    {
        Sanctum::actingAs($this->admin);

        $this->revocarEmpresa($this->superadmin)
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo un superadministrador puede gestionar a otro superadministrador.');

        $this->assertTrue((bool) UserEnterpriseAccess::where('user_id', $this->superadmin->id)->value('is_active'));
    }

    public function test_admin_no_puede_usar_la_asignacion_masiva_ni_la_legacy_sobre_un_superadmin(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/users/{$this->superadmin->id}/hierarchical-permissions/bulk", [])
            ->assertForbidden();
        $this->postJson("/api/users/{$this->superadmin->id}/permissions/bulk", [])
            ->assertForbidden();
        $this->deleteJson("/api/users/{$this->superadmin->id}/permissions/module/1")
            ->assertForbidden();
    }

    public function test_admin_si_gestiona_permisos_de_usuarios_normales(): void
    {
        Sanctum::actingAs($this->admin);

        $this->revocarEmpresa($this->usuario)->assertSuccessful();
    }

    public function test_superadmin_si_gestiona_permisos_de_otro_superadmin(): void
    {
        $otro = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($otro);

        $this->revocarEmpresa($this->superadmin)->assertSuccessful();
        $this->assertFalse((bool) UserEnterpriseAccess::where('user_id', $this->superadmin->id)->value('is_active'));
    }

    public function test_admin_puede_leer_los_permisos_de_un_superadmin(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/users/{$this->superadmin->id}/hierarchical-permissions")->assertOk();
    }
}
