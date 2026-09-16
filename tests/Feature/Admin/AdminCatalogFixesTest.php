<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Correcciones del panel admin (sub-proyecto C1): datos que el frontend
 * mostraba mal o reconstruía a mano, ruido en laravel.log y endpoints
 * legacy engañosos.
 */
class AdminCatalogFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Enterprise $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->empresa = Enterprise::create([
            'name' => 'Empresa C1', 'slug' => 'empresa-c1', 'description' => 'Prueba', 'is_active' => true,
        ]);
    }

    public function test_los_modulos_incluyen_la_empresa_de_su_aplicacion(): void
    {
        $app = Application::create([
            'enterprise_id' => $this->empresa->id, 'slug' => 'app-c1', 'name' => 'App C1',
            'description' => 'Prueba', 'path' => '/empresa-c1/app-c1', 'is_active' => true,
        ]);
        $modulo = Module::create(['application_id' => $app->id, 'slug' => 'mod-c1', 'name' => 'Mod C1', 'order' => 1, 'is_active' => true]);

        Sanctum::actingAs($this->admin);
        $fila = collect($this->getJson('/api/modules')->assertOk()->json('data'))->firstWhere('id', $modulo->id);

        $this->assertSame($this->empresa->id, $fila['application']['enterprise']['id']);
        $this->assertSame('Empresa C1', $fila['application']['enterprise']['name']);
    }

    public function test_las_empresas_cuentan_usuarios_con_acceso_activo(): void
    {
        $vacia = Enterprise::create([
            'name' => 'Empresa Vacía', 'slug' => 'empresa-vacia', 'description' => 'Prueba', 'is_active' => true,
        ]);
        [$a, $b, $c] = User::factory()->count(3)->create()->all();
        UserEnterpriseAccess::create(['user_id' => $a->id, 'enterprise_id' => $this->empresa->id, 'is_active' => true]);
        UserEnterpriseAccess::create(['user_id' => $b->id, 'enterprise_id' => $this->empresa->id, 'is_active' => true]);
        UserEnterpriseAccess::create(['user_id' => $c->id, 'enterprise_id' => $this->empresa->id, 'is_active' => false]);

        Sanctum::actingAs($this->admin);
        $filas = collect($this->getJson('/api/enterprises')->assertOk()->json('data'));

        $this->assertSame(2, $filas->firstWhere('id', $this->empresa->id)['users_count']);
        $this->assertSame(0, $filas->firstWhere('id', $vacia->id)['users_count']);
    }

    public function test_un_usuario_normal_no_recibe_el_conteo_de_usuarios(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $fila = $this->getJson('/api/enterprises')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('users_count', $fila);
    }

    public function test_models_lista_valores_distintos_ordenados_y_no_cae_en_show(): void
    {
        foreach (['User', 'Cultivo', 'User', null] as $modelo) {
            ActivityLog::create(['user_id' => $this->admin->id, 'action' => 'update', 'model' => $modelo]);
        }

        Sanctum::actingAs($this->admin);
        $modelos = $this->getJson('/api/admin/logs/models')->assertOk()->assertJsonPath('success', true)->json('data');

        $this->assertContains('Cultivo', $modelos);
        $this->assertContains('User', $modelos);
        $this->assertNotContains(null, $modelos);
        $this->assertSame(array_values(array_unique($modelos)), $modelos);
        $ordenados = $modelos;
        sort($ordenados);
        $this->assertSame($ordenados, $modelos);
    }

    public function test_listar_logs_no_escribe_en_laravel_log(): void
    {
        ActivityLog::create(['user_id' => $this->admin->id, 'action' => 'update', 'model' => 'Prueba']);
        Sanctum::actingAs($this->admin);
        Log::spy();

        $this->getJson('/api/admin/logs?model=Prueba&search=Prueba')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        Log::shouldNotHaveReceived('info');
    }

    public function test_las_rutas_legacy_de_asignacion_ya_no_existen(): void
    {
        $usuario = User::factory()->create();
        $app = Application::create([
            'enterprise_id' => $this->empresa->id, 'slug' => 'app-legacy', 'name' => 'App Legacy',
            'description' => 'Prueba', 'path' => '/empresa-c1/app-legacy', 'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin);

        $respuestas = [
            $this->postJson("/api/users/{$usuario->id}/enterprises", ['enterprise_ids' => [$this->empresa->id]]),
            $this->postJson("/api/users/{$usuario->id}/enterprises/{$this->empresa->id}/applications", ['application_ids' => [$app->id]]),
        ];

        foreach ($respuestas as $respuesta) {
            $this->assertContains($respuesta->status(), [404, 405]);
        }
        $this->assertDatabaseMissing('user_enterprises', ['user_id' => $usuario->id]);
    }
}
