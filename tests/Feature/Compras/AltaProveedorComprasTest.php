<?php

namespace Tests\Feature\Compras;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class AltaProveedorComprasTest extends TestCase
{
    use CreatesComprasFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpComprasFixtures();
    }

    private const URL = '/api/splendidfarms/administration/compras/requisiciones/proveedores';

    public function test_quien_cotiza_da_de_alta_un_proveedor(): void
    {
        $user = $this->crearUsuarioDeCampo();
        $this->otorgarCotizar($user);
        Sanctum::actingAs($user);

        $res = $this->postJson(self::URL, [
            'business_name' => 'Semillas del Pacífico',
            'supplier_type' => 'national',
            'phone' => '6671234567',
        ], $this->headersEmpresa());

        $res->assertOk()->assertJsonPath('data.business_name', 'Semillas del Pacífico');
        $this->assertNotEmpty($res->json('data.code'));
        $this->assertDatabaseHas('suppliers', ['business_name' => 'Semillas del Pacífico', 'is_active' => true]);
    }

    public function test_sin_permiso_de_cotizar_no_puede(): void
    {
        Sanctum::actingAs($this->crearUsuarioDeCampo());

        $this->postJson(self::URL, ['business_name' => 'Proveedor X', 'supplier_type' => 'national'], $this->headersEmpresa())
            ->assertForbidden();

        $this->assertDatabaseMissing('suppliers', ['business_name' => 'Proveedor X']);
    }

    public function test_exige_razon_social(): void
    {
        $user = $this->crearUsuarioDeCampo();
        $this->otorgarCotizar($user);
        Sanctum::actingAs($user);

        $this->postJson(self::URL, ['supplier_type' => 'national'], $this->headersEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors('business_name');
    }

    public function test_las_rutas_de_cotizacion_ya_no_viven_en_operacion_agricola(): void
    {
        $user = $this->crearUsuarioDeCampo();
        $this->otorgarCotizar($user);
        Sanctum::actingAs($user);

        $this->getJson('/api/splendidfarms/operacion-agricola/agricola/requisiciones/proveedores', $this->headersEmpresa())->assertNotFound();
    }

    public function test_la_bandeja_por_cotizar_vive_en_compras_y_exige_permiso(): void
    {
        $solicitante = $this->crearUsuarioDeCampo([$this->almacenA]);
        $this->crearRequisicion($solicitante, $this->almacenA, 'enviada');

        $comprador = $this->crearUsuarioDeCampo();
        $this->otorgarCotizar($comprador);
        $this->otorgarVerTodos($comprador);
        Sanctum::actingAs($comprador);
        $this->getJson('/api/splendidfarms/administration/compras/requisiciones?bandeja=por_cotizar', $this->headersEmpresa())
            ->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($solicitante);
        $this->getJson('/api/splendidfarms/administration/compras/requisiciones?bandeja=por_cotizar', $this->headersEmpresa())
            ->assertForbidden();
    }
}
