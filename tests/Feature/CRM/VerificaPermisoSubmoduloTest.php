<?php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmodulePermission;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class VerificaPermisoSubmoduloTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    /** Arnés mínimo: una clase anónima que solo expone el método protegido del trait. */
    private function harness()
    {
        return new class {
            use VerificaPermisoSubmodulo;

            public function check(int $empresaId, string $modulo, string $submodulo, string $permiso): bool
            {
                return $this->tienePermisoSubmodulo($empresaId, $modulo, $submodulo, $permiso);
            }
        };
    }

    private function crearSubmoduloConPermiso(string $permisoSlug): Submodule
    {
        $app = Application::create([
            'enterprise_id' => $this->enterprise->id,
            'slug' => 'crm',
            'name' => 'CRM Comercial',
            'description' => 'CRM Comercial',
            'path' => '/'.$this->enterprise->slug.'/crm',
            'is_active' => true,
        ]);
        $modulo = Module::create([
            'application_id' => $app->id,
            'slug' => 'presupuestos',
            'name' => 'Presupuestos',
            'order' => 1,
            'is_active' => true,
        ]);
        $submodulo = Submodule::create([
            'module_id' => $modulo->id,
            'slug' => 'presupuestos',
            'name' => 'Presupuestos',
            'order' => 1,
            'is_active' => true,
        ]);
        SubmodulePermissionType::create([
            'submodule_id' => $submodulo->id,
            'slug' => $permisoSlug,
            'name' => ucfirst($permisoSlug),
            'order' => 1,
            'is_active' => true,
        ]);

        return $submodulo;
    }

    public function test_devuelve_true_si_el_usuario_tiene_el_permiso_otorgado(): void
    {
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);

        $submodulo = $this->crearSubmoduloConPermiso('crear');
        $tipo = $submodulo->permissionTypes()->first();

        UserSubmodulePermission::create([
            'user_id' => $this->actingUser->id,
            'submodule_id' => $submodulo->id,
            'permission_type_id' => $tipo->id,
            'is_granted' => true,
        ]);

        $resultado = $this->harness()->check($this->enterprise->id, 'presupuestos', 'presupuestos', 'crear');

        $this->assertTrue($resultado);
    }

    public function test_devuelve_false_si_no_hay_permiso_otorgado(): void
    {
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);

        $this->crearSubmoduloConPermiso('crear');

        $resultado = $this->harness()->check($this->enterprise->id, 'presupuestos', 'presupuestos', 'crear');

        $this->assertFalse($resultado);
    }

    public function test_devuelve_false_si_el_permiso_existe_pero_is_granted_es_false(): void
    {
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);

        $submodulo = $this->crearSubmoduloConPermiso('editar');
        $tipo = $submodulo->permissionTypes()->first();

        UserSubmodulePermission::create([
            'user_id' => $this->actingUser->id,
            'submodule_id' => $submodulo->id,
            'permission_type_id' => $tipo->id,
            'is_granted' => false,
        ]);

        $resultado = $this->harness()->check($this->enterprise->id, 'presupuestos', 'presupuestos', 'editar');

        $this->assertFalse($resultado);
    }

    public function test_devuelve_false_si_el_submodulo_es_de_otra_empresa(): void
    {
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);

        $otraEmpresa = $this->crearOtraEmpresa();
        $appAjena = Application::create([
            'enterprise_id' => $otraEmpresa->id,
            'slug' => 'crm',
            'name' => 'CRM Comercial',
            'description' => 'CRM Comercial',
            'path' => '/'.$otraEmpresa->slug.'/crm',
            'is_active' => true,
        ]);
        $moduloAjeno = Module::create([
            'application_id' => $appAjena->id, 'slug' => 'presupuestos', 'name' => 'Presupuestos',
            'order' => 1, 'is_active' => true,
        ]);
        $submoduloAjeno = Submodule::create([
            'module_id' => $moduloAjeno->id, 'slug' => 'presupuestos', 'name' => 'Presupuestos',
            'order' => 1, 'is_active' => true,
        ]);
        $tipoAjeno = SubmodulePermissionType::create([
            'submodule_id' => $submoduloAjeno->id, 'slug' => 'crear', 'name' => 'Crear',
            'order' => 1, 'is_active' => true,
        ]);
        UserSubmodulePermission::create([
            'user_id' => $this->actingUser->id,
            'submodule_id' => $submoduloAjeno->id,
            'permission_type_id' => $tipoAjeno->id,
            'is_granted' => true,
        ]);

        // Se pregunta por la empresa PROPIA ($this->enterprise), no la ajena.
        $resultado = $this->harness()->check($this->enterprise->id, 'presupuestos', 'presupuestos', 'crear');

        $this->assertFalse($resultado);
    }
}
