<?php

namespace Tests\Feature\Admin;

use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresión de seguridad: /api/admin/* y la escritura de empresas no tenían
 * guard de administrador; cualquier usuario autenticado podía usarlas.
 */
class AdminRoutesAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;
    private Enterprise $activa;
    private Enterprise $inactiva;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create(['role' => 'user']);
        $this->activa = Enterprise::create([
            'name' => 'Empresa Activa', 'slug' => 'empresa-activa', 'description' => 'Prueba', 'is_active' => true,
        ]);
        $this->inactiva = Enterprise::create([
            'name' => 'Empresa Inactiva', 'slug' => 'empresa-inactiva', 'description' => 'Prueba', 'is_active' => false,
        ]);
    }

    public function test_usuario_normal_recibe_403_en_la_administracion_global(): void
    {
        Sanctum::actingAs($this->usuario);
        $e = $this->activa->id;

        $rutas = [
            ['GET', '/api/admin/logs', []],
            ['GET', '/api/admin/logs/stats', []],
            ['GET', '/api/admin/schedules', []],
            ['POST', '/api/admin/schedules', ['name' => 'Horario X']],
            ['GET', "/api/admin/enterprises/{$e}/schedules", []],
            ['GET', "/api/admin/enterprises/{$e}/entity-access", []],
            ['GET', '/api/admin/approval-processes', []],
            ['POST', '/api/admin/approval-processes', []],
        ];

        foreach ($rutas as [$metodo, $uri, $payload]) {
            $response = $this->json($metodo, $uri, $payload);
            $this->assertSame(403, $response->status(), "{$metodo} {$uri} debió responder 403, respondió {$response->status()}");
        }
    }

    public function test_usuario_normal_no_puede_escribir_empresas(): void
    {
        Sanctum::actingAs($this->usuario);

        $this->postJson('/api/enterprises', ['name' => 'Nueva', 'slug' => 'nueva'])->assertStatus(403);
        $this->putJson("/api/enterprises/{$this->activa->id}", ['name' => 'Hackeada'])->assertStatus(403);
        $this->deleteJson("/api/enterprises/{$this->activa->id}")->assertStatus(403);

        $this->assertDatabaseHas('enterprises', ['id' => $this->activa->id, 'name' => 'Empresa Activa']);
        $this->assertDatabaseMissing('enterprises', ['slug' => 'nueva']);
    }

    public function test_usuario_normal_sigue_pudiendo_listar_empresas_activas(): void
    {
        Sanctum::actingAs($this->usuario);

        $this->getJson('/api/enterprises')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'empresa-activa'])
            ->assertJsonMissing(['slug' => 'empresa-inactiva']);
    }

    public function test_superadmin_recibe_la_lista_completa_de_empresas(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));

        $this->getJson('/api/enterprises')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'empresa-inactiva']);
    }

    public function test_admin_no_es_rechazado_en_la_administracion_global(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        foreach (['/api/admin/logs', '/api/admin/schedules', '/api/admin/approval-processes'] as $uri) {
            $status = $this->getJson($uri)->status();
            $this->assertNotContains($status, [401, 403], "GET {$uri} rechazó a un admin con {$status}");
        }
    }
}
