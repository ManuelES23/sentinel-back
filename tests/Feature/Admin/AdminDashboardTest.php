<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El Dashboard del panel admin mostraba cifras, actividad y alertas
 * inventadas. Ahora las obtiene de GET /api/admin/dashboard.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function empresa(string $slug, bool $activa = true): Enterprise
    {
        return Enterprise::create([
            'name' => "Empresa {$slug}", 'slug' => $slug,
            'description' => 'Prueba', 'is_active' => $activa,
        ]);
    }

    private function stats(): array
    {
        return $this->getJson('/api/admin/dashboard')->assertOk()->json('data.stats');
    }

    public function test_usuario_normal_recibe_403(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }

    public function test_las_cifras_cuentan_solo_registros_reales_y_activos(): void
    {
        Sanctum::actingAs($this->admin);
        $antes = $this->stats();

        $activa = $this->empresa('dash-activa');
        $this->empresa('dash-inactiva', false);
        $app = Application::create([
            'enterprise_id' => $activa->id, 'slug' => 'dash-app', 'name' => 'App Dash',
            'description' => 'Prueba', 'path' => '/dash-activa/dash-app', 'is_active' => true,
        ]);
        Application::create([
            'enterprise_id' => $activa->id, 'slug' => 'dash-app-off', 'name' => 'App Off',
            'description' => 'Prueba', 'path' => '/dash-activa/dash-app-off', 'is_active' => false,
        ]);
        Module::create(['application_id' => $app->id, 'slug' => 'dash-mod', 'name' => 'Mod', 'order' => 1, 'is_active' => true]);
        Module::create(['application_id' => $app->id, 'slug' => 'dash-mod-off', 'name' => 'Mod Off', 'order' => 2, 'is_active' => false]);
        User::factory()->count(2)->create();

        $despues = $this->stats();

        $this->assertSame(
            ['users_total', 'enterprises_active', 'applications_active', 'modules_active'],
            array_keys($despues)
        );
        $this->assertSame($antes['users_total'] + 2, $despues['users_total']);
        $this->assertSame($antes['enterprises_active'] + 1, $despues['enterprises_active']);
        $this->assertSame($antes['applications_active'] + 1, $despues['applications_active']);
        $this->assertSame($antes['modules_active'] + 1, $despues['modules_active']);
    }

    public function test_las_alertas_omiten_conteos_en_cero_y_reportan_los_positivos(): void
    {
        $empresa = $this->empresa('dash-alertas');
        User::all()->each(fn (User $u) => UserEnterpriseAccess::create([
            'user_id' => $u->id, 'enterprise_id' => $empresa->id, 'is_active' => true,
        ]));

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.alerts', []);

        // Sin empresa (su acceso está inactivo) y con cambio de contraseña pendiente.
        $pendiente = User::factory()->create(['must_change_password' => true]);
        UserEnterpriseAccess::create([
            'user_id' => $pendiente->id, 'enterprise_id' => $empresa->id, 'is_active' => false,
        ]);

        $this->assertSame([
            ['key' => 'users_without_enterprise', 'count' => 1],
            ['key' => 'users_must_change_password', 'count' => 1],
        ], $this->getJson('/api/admin/dashboard')->assertOk()->json('data.alerts'));
    }

    public function test_la_actividad_reciente_se_limita_a_8_y_no_expone_valores(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            ActivityLog::create([
                'user_id' => $this->admin->id, 'action' => 'update', 'model' => 'Prueba',
                'model_id' => $i, 'old_values' => ['secreto' => 'antes'], 'new_values' => ['secreto' => 'despues'],
                'ip_address' => '10.0.0.1', 'user_agent' => 'AgenteSecreto',
            ]);
        }

        Sanctum::actingAs($this->admin);
        $actividad = $this->getJson('/api/admin/dashboard')->assertOk()->json('data.recent_activity');

        $this->assertCount(8, $actividad);
        $this->assertSame(
            ['id', 'action', 'model', 'model_id', 'enterprise', 'module', 'user', 'created_at'],
            array_keys($actividad[0])
        );
        $this->assertSame(10, $actividad[0]['model_id']);
        $this->assertSame(['id' => $this->admin->id, 'name' => $this->admin->name], $actividad[0]['user']);
        $json = json_encode($actividad);
        $this->assertStringNotContainsString('secreto', $json);
        $this->assertStringNotContainsString('AgenteSecreto', $json);
    }

    public function test_la_alerta_sin_empresa_no_cuenta_administradores(): void
    {
        $empresa = $this->empresa('dash-roles');
        User::all()->each(fn (User $u) => UserEnterpriseAccess::create([
            'user_id' => $u->id, 'enterprise_id' => $empresa->id, 'is_active' => true,
        ]));

        // Administradores sin empresa: no deben generar alerta.
        User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'superadmin']);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.alerts', []);

        // Un usuario normal sin empresa sí cuenta.
        User::factory()->create(['role' => 'user']);

        $this->assertSame(
            [['key' => 'users_without_enterprise', 'count' => 1]],
            $this->getJson('/api/admin/dashboard')->assertOk()->json('data.alerts')
        );
    }
}
