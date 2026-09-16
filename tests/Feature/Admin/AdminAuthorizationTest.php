<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresión de seguridad: las rutas de asignación de permisos, gestión de
 * usuarios y estructura Aplicación → Módulo → Submódulo solo estaban detrás
 * de auth:sanctum. Cualquier usuario autenticado podía otorgarse a sí mismo
 * cualquier permiso (o hacerse admin con PUT /users/{su_id}).
 *
 * Ahora pasan por el middleware `admin` (EnsureUserIsAdmin: role admin o
 * superadmin). Las lecturas que el front hace para cualquier usuario
 * (sus propios permisos, la jerarquía de la empresa) siguen disponibles.
 */
class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $usuario;
    protected User $admin;
    protected Enterprise $enterprise;
    protected Application $application;
    protected Module $module;
    protected Submodule $submodule;
    protected SubmodulePermissionType $permissionType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create(['role' => 'user']);
        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->enterprise = Enterprise::create([
            'name' => 'Empresa Admin Test', 'slug' => 'empresa-admin-test',
            'description' => 'Empresa de prueba', 'is_active' => true,
        ]);
        $this->application = Application::create([
            'enterprise_id' => $this->enterprise->id, 'slug' => 'app-test', 'name' => 'App Test',
            'description' => 'App de prueba', 'path' => '/empresa-admin-test/app-test', 'is_active' => true,
        ]);
        $this->module = Module::create([
            'application_id' => $this->application->id, 'slug' => 'modulo-test', 'name' => 'Módulo Test',
            'order' => 1, 'is_active' => true,
        ]);
        $this->submodule = Submodule::create([
            'module_id' => $this->module->id, 'slug' => 'submodulo-test', 'name' => 'Submódulo Test',
            'order' => 1, 'is_active' => true,
        ]);
        $this->permissionType = SubmodulePermissionType::create([
            'submodule_id' => $this->submodule->id, 'slug' => 'crear', 'name' => 'Crear',
            'order' => 1, 'is_active' => true,
        ]);
    }

    private function payloadPermisoSubmodulo(): array
    {
        return [
            'submodule_id' => $this->submodule->id,
            'permission_type_id' => $this->permissionType->id,
            'is_granted' => true,
        ];
    }

    public function test_usuario_normal_no_puede_otorgarse_un_permiso_de_submodulo(): void
    {
        Sanctum::actingAs($this->usuario);

        $this->postJson("/api/users/{$this->usuario->id}/hierarchical-permissions/submodule-permission", $this->payloadPermisoSubmodulo())
            ->assertStatus(403);

        $this->assertDatabaseMissing('user_submodule_permissions', ['user_id' => $this->usuario->id]);
    }

    public function test_admin_puede_otorgar_un_permiso_de_submodulo(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/users/{$this->usuario->id}/hierarchical-permissions/submodule-permission", $this->payloadPermisoSubmodulo())
            ->assertOk();

        $this->assertTrue(UserSubmodulePermission::where('user_id', $this->usuario->id)
            ->where('permission_type_id', $this->permissionType->id)->where('is_granted', true)->exists());
    }

    public function test_superadmin_puede_otorgar_un_permiso_de_submodulo(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));

        $this->postJson("/api/users/{$this->usuario->id}/hierarchical-permissions/submodule-permission", $this->payloadPermisoSubmodulo())
            ->assertOk();
    }

    public function test_usuario_normal_recibe_403_en_todas_las_rutas_de_administracion(): void
    {
        Sanctum::actingAs($this->usuario);
        $u = $this->usuario->id;
        $s = $this->submodule->id;
        $m = $this->module->id;
        $a = $this->application->id;
        $e = $this->enterprise->id;

        $rutas = [
            // Permisos jerárquicos (asignación)
            ['POST', "/api/users/{$u}/hierarchical-permissions/bulk", ['submodules' => [$s]]],
            ['POST', "/api/users/{$u}/hierarchical-permissions/enterprise", ['enterprise_id' => $e, 'is_active' => true]],
            ['POST', "/api/users/{$u}/hierarchical-permissions/application", ['application_id' => $a, 'is_active' => true]],
            ['POST', "/api/users/{$u}/hierarchical-permissions/module", ['module_id' => $m, 'is_active' => true]],
            ['POST', "/api/users/{$u}/hierarchical-permissions/submodule", ['submodule_id' => $s, 'is_active' => true]],
            ['POST', "/api/users/{$u}/hierarchical-permissions/submodule-permission", $this->payloadPermisoSubmodulo()],

            // Permisos legacy
            ['GET', "/api/users/{$u}/permissions/available", []],
            ['POST', "/api/users/{$u}/permissions/bulk", []],
            ['POST', "/api/users/{$u}/permissions/module", ['module_id' => $m, 'permissions' => ['view']]],
            ['POST', "/api/users/{$u}/permissions/submodule", ['submodule_id' => $s, 'permissions' => ['view']]],
            ['DELETE', "/api/users/{$u}/permissions/module/{$m}", []],
            ['DELETE', "/api/users/{$u}/permissions/submodule/{$s}", []],

            // Tipos de permiso
            ['POST', '/api/submodules/permission-types/bulk-defaults', []],
            ['POST', "/api/submodules/{$s}/permission-types", ['slug' => 'aprobar', 'name' => 'Aprobar']],
            ['POST', "/api/submodules/{$s}/permission-types/defaults", []],
            ['DELETE', "/api/submodules/{$s}/permission-types/{$this->permissionType->id}", []],

            // Estructura
            ['POST', '/api/applications', ['enterprise_id' => $e, 'name' => 'X', 'slug' => 'x']],
            ['PUT', "/api/applications/{$a}", ['name' => 'X']],
            ['DELETE', "/api/applications/{$a}", []],
            ['POST', '/api/modules', ['application_id' => $a, 'name' => 'X', 'slug' => 'x']],
            ['PUT', "/api/modules/{$m}", ['name' => 'X']],
            ['DELETE', "/api/modules/{$m}", []],
            ['POST', '/api/modules/reorder', ['modules' => []]],
            ['POST', '/api/submodules', ['module_id' => $m, 'name' => 'X', 'slug' => 'x']],
            ['PUT', "/api/submodules/{$s}", ['name' => 'X']],
            ['DELETE', "/api/submodules/{$s}", []],
            ['POST', '/api/submodules/reorder', ['submodules' => []]],

            // Gestión de usuarios
            ['GET', '/api/users', []],
            ['GET', "/api/users/{$this->admin->id}", []],
            ['POST', '/api/users', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'password123']],
            ['PUT', "/api/users/{$u}", ['role' => 'admin']],
            ['DELETE', "/api/users/{$this->admin->id}", []],
            ['GET', '/api/users-employees-available', []],
        ];

        foreach ($rutas as [$metodo, $uri, $payload]) {
            $response = $this->json($metodo, $uri, $payload);
            $this->assertSame(403, $response->status(), "{$metodo} {$uri} debió responder 403, respondió {$response->status()}");
        }

        $this->assertSame('user', $this->usuario->fresh()->role);
        $this->assertDatabaseMissing('user_submodule_permissions', ['user_id' => $u]);
        $this->assertDatabaseHas('modules', ['id' => $m, 'name' => 'Módulo Test']);
        $this->assertDatabaseHas('submodule_permission_types', ['id' => $this->permissionType->id]);
        $this->assertNotNull($this->admin->fresh());
    }

    public function test_usuario_normal_no_puede_hacerse_admin_pero_un_admin_si_puede_cambiar_el_rol(): void
    {
        Sanctum::actingAs($this->usuario);
        $this->putJson("/api/users/{$this->usuario->id}", ['role' => 'admin'])->assertStatus(403);
        $this->assertSame('user', $this->usuario->fresh()->role);

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/users/{$this->usuario->id}", ['role' => 'admin'])->assertOk();
        $this->assertSame('admin', $this->usuario->fresh()->role);
    }

    public function test_usuario_puede_consultar_sus_propios_permisos_pero_no_los_de_otro(): void
    {
        Sanctum::actingAs($this->usuario);

        $this->getJson("/api/users/{$this->usuario->id}/hierarchical-permissions")->assertOk();
        $this->getJson("/api/users/{$this->admin->id}/hierarchical-permissions")->assertStatus(403);
        $this->getJson("/api/users/{$this->admin->id}/permissions")->assertStatus(403);
    }

    public function test_admin_puede_consultar_los_permisos_de_otro_usuario(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/users/{$this->usuario->id}/hierarchical-permissions")->assertOk();
    }

    public function test_lecturas_de_estructura_usadas_por_el_workspace_siguen_abiertas(): void
    {
        Sanctum::actingAs($this->usuario);

        $this->getJson("/api/enterprises/{$this->enterprise->id}/hierarchy")->assertOk();
        $this->getJson("/api/submodules/{$this->submodule->id}/permission-types")->assertOk();
        $this->getJson("/api/modules/{$this->module->id}/submodules")->assertOk();
    }

    public function test_sin_autenticar_responde_401(): void
    {
        $this->postJson("/api/users/{$this->usuario->id}/hierarchical-permissions/submodule-permission", $this->payloadPermisoSubmodulo())
            ->assertStatus(401);
    }
}
