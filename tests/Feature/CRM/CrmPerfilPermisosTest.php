<?php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\CRM\CrmVendedor;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserModuleAccess;
use App\Models\UserSubmodulePermission;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class CrmPerfilPermisosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        (new CrmPermisosSeeder())->run();

        $this->actingUser->forceFill(['role' => 'admin'])->save();

        $this->usuario = User::factory()->create();
        UserEnterpriseAccess::create([
            'user_id' => $this->usuario->id,
            'enterprise_id' => $this->enterprise->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);

        Sanctum::actingAs($this->actingUser);
    }

    private function aplicar(string $perfil, ?User $usuario = null)
    {
        $usuario ??= $this->usuario;

        return $this->postJson("/api/crm/perfiles/usuarios/{$usuario->id}", [
            'enterprise_id' => $this->enterprise->id,
            'perfil' => $perfil,
        ]);
    }

    private function crmModulo(string $slug): Module
    {
        $app = Application::where('enterprise_id', $this->enterprise->id)->where('slug', 'crm')->firstOrFail();

        return Module::where('application_id', $app->id)->where('slug', $slug)->firstOrFail();
    }

    private function tienePermiso(string $modulo, string $submodulo, string $permiso): bool
    {
        $sub = Submodule::where('module_id', $this->crmModulo($modulo)->id)->where('slug', $submodulo)->firstOrFail();

        return UserSubmodulePermission::where('user_id', $this->usuario->id)
            ->where('submodule_id', $sub->id)
            ->where('is_granted', true)
            ->whereHas('permissionType', fn ($q) => $q->where('slug', $permiso))
            ->exists();
    }

    private function tieneModulo(string $modulo): bool
    {
        return UserModuleAccess::where('user_id', $this->usuario->id)
            ->where('module_id', $this->crmModulo($modulo)->id)
            ->where('is_active', true)
            ->exists();
    }

    public function test_lista_los_tres_perfiles(): void
    {
        $this->getJson('/api/crm/perfiles')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.slug', 'vendedor')
            ->assertJsonPath('data.1.slug', 'gerencia')
            ->assertJsonPath('data.2.slug', 'administracion');
    }

    public function test_perfil_vendedor_da_la_operacion_sin_catalogos_ni_aprobaciones(): void
    {
        $this->aplicar('vendedor')->assertOk()->assertJsonPath('data.perfil', 'vendedor');

        $this->assertTrue($this->tieneModulo('agenda'));
        $this->assertTrue($this->tienePermiso('agenda', 'agenda', 'crear'));
        $this->assertTrue($this->tienePermiso('oportunidades', 'oportunidades', 'crear'));
        $this->assertTrue($this->tienePermiso('dashboard', 'dashboard', 'ver'));

        $this->assertFalse($this->tieneModulo('catalogos'));
        $this->assertFalse($this->tienePermiso('cotizaciones', 'cotizaciones', 'aprobar'));
        $this->assertFalse($this->tienePermiso('dashboard', 'dashboard', 'ejecutivo'));
        $this->assertFalse($this->tienePermiso('presupuestos', 'presupuestos', 'crear'));
        $this->assertFalse($this->tienePermiso('integraciones', 'dialpad', 'ver'));
    }

    public function test_perfil_gerencia_suma_aprobaciones_metas_y_tablero_ejecutivo(): void
    {
        $this->aplicar('gerencia')->assertOk();

        $this->assertTrue($this->tienePermiso('agenda', 'agenda', 'crear'));
        $this->assertTrue($this->tienePermiso('cotizaciones', 'cotizaciones', 'aprobar'));
        $this->assertTrue($this->tienePermiso('clientes', 'clientes', 'asignar_vendedor'));
        $this->assertTrue($this->tienePermiso('dashboard', 'dashboard', 'ejecutivo'));
        $this->assertTrue($this->tienePermiso('presupuestos', 'presupuestos', 'editar'));
        $this->assertTrue($this->tienePermiso('integraciones', 'dialpad', 'editar'));
        $this->assertFalse($this->tieneModulo('catalogos'));
    }

    public function test_aplicar_otro_perfil_reemplaza_el_anterior(): void
    {
        $this->aplicar('gerencia')->assertOk();
        $this->aplicar('administracion')->assertOk();

        $this->assertTrue($this->tieneModulo('catalogos'));
        $this->assertTrue($this->tienePermiso('catalogos', 'productos', 'editar'));
        $this->assertTrue($this->tienePermiso('catalogos', 'configuracion-comercial', 'editar'));

        $this->assertFalse($this->tieneModulo('agenda'));
        $this->assertFalse($this->tienePermiso('agenda', 'agenda', 'crear'));
        $this->assertFalse($this->tienePermiso('cotizaciones', 'cotizaciones', 'aprobar'));
    }

    public function test_no_toca_permisos_fuera_del_crm(): void
    {
        $otraApp = Application::create([
            'enterprise_id' => $this->enterprise->id, 'slug' => 'inventario', 'name' => 'Inventario',
            'description' => 'Otra aplicación', 'path' => '/inventario', 'is_active' => true,
        ]);
        $modulo = Module::create(['application_id' => $otraApp->id, 'slug' => 'productos', 'name' => 'Productos', 'order' => 1, 'is_active' => true]);
        $sub = Submodule::create(['module_id' => $modulo->id, 'slug' => 'productos', 'name' => 'Productos', 'order' => 1, 'is_active' => true]);
        $tipo = SubmodulePermissionType::create(['submodule_id' => $sub->id, 'slug' => 'ver', 'name' => 'Ver', 'order' => 1, 'is_active' => true]);
        $permisoAjeno = UserSubmodulePermission::create([
            'user_id' => $this->usuario->id, 'submodule_id' => $sub->id,
            'permission_type_id' => $tipo->id, 'is_granted' => true,
        ]);

        $this->aplicar('administracion')->assertOk();

        $this->assertTrue((bool) $permisoAjeno->fresh()->is_granted);
    }

    public function test_el_vendedor_con_perfil_puede_consultar_su_agenda(): void
    {
        $this->aplicar('vendedor')->assertOk();
        $vendedorPropio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->usuario->id, 'nombre' => 'Vendedor con perfil',
        ]);

        Sanctum::actingAs($this->usuario);

        $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/agenda?vendedor_id={$vendedorPropio->id}")
            ->assertOk();
    }

    public function test_mi_dia_vendedor_ve_su_dia_y_gerencia_tambien_el_del_equipo(): void
    {
        $this->aplicar('vendedor')->assertOk();
        $this->assertTrue($this->tieneModulo('mi-dia'));
        $this->assertTrue($this->tienePermiso('mi-dia', 'mi-dia', 'ver'));
        $this->assertFalse($this->tienePermiso('mi-dia', 'mi-dia', 'equipo'));

        $this->aplicar('gerencia')->assertOk();
        $this->assertTrue($this->tienePermiso('mi-dia', 'mi-dia', 'ver'));
        $this->assertTrue($this->tienePermiso('mi-dia', 'mi-dia', 'equipo'));

        $this->aplicar('administracion')->assertOk();
        $this->assertFalse($this->tieneModulo('mi-dia'));
        $this->assertFalse($this->tienePermiso('mi-dia', 'mi-dia', 'ver'));
    }

    public function test_solo_un_administrador_puede_aplicar_perfiles(): void
    {
        $this->actingUser->forceFill(['role' => 'user'])->save();

        $this->aplicar('gerencia')->assertForbidden();
        $this->getJson('/api/crm/perfiles')->assertForbidden();

        $this->assertSame(0, UserSubmodulePermission::where('user_id', $this->usuario->id)->count());
    }

    public function test_rechaza_usuario_sin_acceso_a_la_empresa(): void
    {
        $sinAcceso = User::factory()->create();

        $this->aplicar('vendedor', $sinAcceso)->assertStatus(422);

        $this->assertSame(0, UserSubmodulePermission::where('user_id', $sinAcceso->id)->count());
    }

    public function test_rechaza_perfil_desconocido(): void
    {
        $this->aplicar('director')->assertStatus(422);
    }
}
