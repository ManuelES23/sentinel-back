<?php

namespace Tests\Feature\Admin;

use App\Models\UserEntityAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class UserAlmacenControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
    }

    public function test_admin_consulta_los_almacenes_de_un_usuario(): void
    {
        $user = $this->crearUsuarioDeCampo([$this->almacenA]);
        Sanctum::actingAs($this->crearAdmin());

        $response = $this->getJson("/api/users/{$user->id}/almacenes");

        $response->assertOk();
        $empresa = $response->json('data.0');
        $this->assertSame('splendidfarms', $empresa['enterprise']['slug']);
        $this->assertFalse($empresa['ver_todos']);
        $almacenes = collect($empresa['almacenes'])->keyBy('id');
        $this->assertTrue($almacenes[$this->almacenA->id]['asignado']);
        $this->assertFalse($almacenes[$this->almacenB->id]['asignado']);
    }

    public function test_admin_sincroniza_almacenes_de_una_empresa(): void
    {
        $user = $this->crearUsuarioDeCampo([$this->almacenA]);
        $admin = $this->crearAdmin();
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$user->id}/almacenes", [
            'enterprise_id' => $this->empresa->id,
            'entity_ids' => [$this->almacenB->id],
        ])->assertOk();

        $this->assertDatabaseMissing('user_entity_access', ['user_id' => $user->id, 'entity_id' => $this->almacenA->id]);
        $this->assertDatabaseHas('user_entity_access', ['user_id' => $user->id, 'entity_id' => $this->almacenB->id, 'granted_by' => $admin->id]);
    }

    public function test_rechaza_almacenes_que_no_son_de_la_empresa(): void
    {
        $user = $this->crearUsuarioDeCampo();
        Sanctum::actingAs($this->crearAdmin());

        $this->putJson("/api/users/{$user->id}/almacenes", [
            'enterprise_id' => $this->empresa->id,
            'entity_ids' => [999999],
        ])->assertStatus(422);

        $this->assertSame(0, UserEntityAccess::count());
    }

    public function test_usuario_no_admin_recibe_403(): void
    {
        $user = $this->crearUsuarioDeCampo();
        Sanctum::actingAs($this->crearUsuarioDeCampo());

        $this->getJson("/api/users/{$user->id}/almacenes")->assertForbidden();
        $this->putJson("/api/users/{$user->id}/almacenes", [
            'enterprise_id' => $this->empresa->id,
            'entity_ids' => [],
        ])->assertForbidden();
    }
}
