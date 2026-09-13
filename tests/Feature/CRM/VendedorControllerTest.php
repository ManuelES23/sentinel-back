<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmVendedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class VendedorControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const BASE_URL = '/api/crm/vendedores';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        // Este archivo prueba el comportamiento, no la autorización (ver CrmPermisosEnforcementTest).
        $this->otorgarTodosLosPermisosCrm();
    }

    public function test_puede_listar_vendedores(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL);

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * Regresión: user_id se creó como NOT NULL en la BD aunque la validación
     * del controller y la documentación dicen que es opcional. Sin la
     * migración que lo corrige, esto revienta con 500 en vez de crear el
     * registro.
     */
    public function test_puede_crear_un_vendedor_sin_usuario_ligado(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'nombre' => 'Vendedor sin cuenta',
        ]);

        $response->assertCreated()->assertJsonPath('data.user', null);
        $this->assertDatabaseHas('crm_vendedores', ['nombre' => 'Vendedor sin cuenta', 'user_id' => null]);
    }

    public function test_puede_crear_un_vendedor_ligado_a_un_usuario(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'nombre' => 'Vendedor con cuenta',
            'user_id' => $user->id,
        ]);

        $response->assertCreated()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_no_permite_que_el_mismo_usuario_sea_vendedor_dos_veces_en_la_misma_empresa(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'nombre' => 'Duplicado',
            'user_id' => $this->vendedor->user_id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['user_id']);
    }

    public function test_usuarios_disponibles_excluye_a_quienes_ya_son_vendedores(): void
    {
        $usuarioLibre = User::factory()->create();
        \App\Models\UserEnterpriseAccess::create([
            'user_id' => $usuarioLibre->id,
            'enterprise_id' => $this->enterprise->id,
            'is_active' => true,
        ]);
        \App\Models\UserEnterpriseAccess::create([
            'user_id' => $this->vendedor->user_id,
            'enterprise_id' => $this->enterprise->id,
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL.'/usuarios-disponibles');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($usuarioLibre->id));
        $this->assertFalse($ids->contains($this->vendedor->user_id));
    }

    public function test_puede_alternar_el_estado_activo(): void
    {
        $response = $this->withHeaders($this->crmHeaders())
            ->patchJson(self::BASE_URL."/{$this->vendedor->id}/toggle-activo");

        $response->assertOk()->assertJsonPath('data.activo', false);
    }

    public function test_no_permite_eliminar_un_vendedor_con_clientes_asignados(): void
    {
        CrmCliente::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Cliente asignado',
            'vendedor_id' => $this->vendedor->id,
        ]);

        $response = $this->withHeaders($this->crmHeaders())->deleteJson(self::BASE_URL."/{$this->vendedor->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('crm_vendedores', ['id' => $this->vendedor->id]);
    }

    public function test_puede_eliminar_un_vendedor_sin_dependencias(): void
    {
        $vendedor = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Sin dependencias', 'activo' => true]);

        $response = $this->withHeaders($this->crmHeaders())->deleteJson(self::BASE_URL."/{$vendedor->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('crm_vendedores', ['id' => $vendedor->id]);
    }
}
