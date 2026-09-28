<?php

namespace Tests\Feature\Broadcasting;

use App\Broadcasting\CrmEmpresaChannel;
use App\Broadcasting\ModuleChannel;
use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserEnterpriseAccess;
use App\Models\UserModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Autorización de los canales privados de tiempo real.
 *
 * El canal `module.{empresa}.{app}.{modulo}` transporta el payload completo de
 * cada alta, cambio y baja del CRM (y del resto de aplicaciones), así que quien
 * se suscribe tiene que ser alguien que ya podía ver ese módulo.
 */
class CanalesTiempoRealTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Enterprise $empresa;

    private Application $aplicacion;

    private Module $modulo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create();
        $this->empresa = Enterprise::create([
            'name' => 'Splendid Farms',
            'slug' => 'splendidfarms',
            'description' => 'Empresa de prueba',
            'is_active' => true,
        ]);
        $this->aplicacion = Application::create([
            'enterprise_id' => $this->empresa->id,
            'slug' => 'crm',
            'name' => 'CRM Comercial',
            'description' => 'Aplicacion de prueba',
            'path' => '/splendidfarms/crm',
            'is_active' => true,
        ]);
        $this->modulo = Module::create([
            'application_id' => $this->aplicacion->id,
            'slug' => 'clientes',
            'name' => 'Clientes',
            'description' => 'Modulo de prueba',
            'path' => '/splendidfarms/crm/clientes',
            'is_active' => true,
        ]);
    }

    private function darAcceso(?User $usuario = null, bool $moduloActivo = true, bool $conAplicacion = true): void
    {
        $usuario ??= $this->usuario;

        UserEnterpriseAccess::create([
            'user_id' => $usuario->id,
            'enterprise_id' => $this->empresa->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);

        if ($conAplicacion) {
            UserApplicationAccess::create([
                'user_id' => $usuario->id,
                'application_id' => $this->aplicacion->id,
                'is_active' => true,
                'granted_at' => now(),
            ]);
        }

        UserModuleAccess::create([
            'user_id' => $usuario->id,
            'module_id' => $this->modulo->id,
            'is_active' => $moduloActivo,
            'granted_at' => now(),
        ]);
    }

    private function unirse(?User $usuario = null, ?string $empresaSlug = null): bool
    {
        return (new ModuleChannel)->join(
            $usuario ?? $this->usuario,
            $empresaSlug ?? $this->empresa->slug,
            'crm',
            'clientes',
        );
    }

    public function test_usuario_con_acceso_al_modulo_puede_unirse(): void
    {
        $this->darAcceso();

        $this->assertTrue($this->unirse());
    }

    public function test_usuario_de_otra_empresa_no_puede_escuchar_el_canal(): void
    {
        $otraEmpresa = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'description' => 'Otra', 'is_active' => true]);
        $ajeno = User::factory()->create();
        UserEnterpriseAccess::create([
            'user_id' => $ajeno->id,
            'enterprise_id' => $otraEmpresa->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);

        $this->assertFalse($this->unirse($ajeno));
    }

    public function test_sin_acceso_a_la_aplicacion_no_puede_unirse(): void
    {
        $this->darAcceso(conAplicacion: false);

        $this->assertFalse($this->unirse());
    }

    public function test_acceso_al_modulo_desactivado_no_puede_unirse(): void
    {
        $this->darAcceso(moduloActivo: false);

        $this->assertFalse($this->unirse());
    }

    public function test_modulo_inexistente_no_autoriza(): void
    {
        $this->darAcceso();

        $this->assertFalse(
            (new ModuleChannel)->join($this->usuario, $this->empresa->slug, 'crm', 'modulo-que-no-existe')
        );
    }

    public function test_empresa_inexistente_no_autoriza(): void
    {
        $this->darAcceso();

        $this->assertFalse($this->unirse(empresaSlug: 'empresa-que-no-existe'));
    }

    public function test_canal_de_empresa_del_crm_usa_los_accesos_reales(): void
    {
        $this->darAcceso();

        $this->assertTrue((new CrmEmpresaChannel)->join($this->usuario, $this->empresa->id));
    }

    public function test_canal_de_empresa_del_crm_rechaza_a_quien_no_pertenece(): void
    {
        $ajeno = User::factory()->create();

        $this->assertFalse((new CrmEmpresaChannel)->join($ajeno, $this->empresa->id));
    }
}
