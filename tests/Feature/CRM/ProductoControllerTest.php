<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmProducto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class ProductoControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const BASE_URL = '/api/crm/productos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        // Este archivo prueba el comportamiento, no la autorización (ver CrmPermisosEnforcementTest).
        $this->otorgarTodosLosPermisosCrm();
    }

    public function test_puede_crear_un_producto(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'nombre' => 'Caja de aguacate 10kg',
            'precio' => 250.50,
            'unidad_medida' => 'pieza',
        ]);

        $response->assertCreated()->assertJsonPath('data.nombre', 'Caja de aguacate 10kg');
    }

    public function test_rechaza_una_unidad_de_medida_invalida(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'nombre' => 'Producto X',
            'precio' => 10,
            'unidad_medida' => 'toneladas',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['unidad_medida']);
    }

    public function test_buscar_solo_devuelve_productos_activos_de_la_empresa(): void
    {
        CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Mango Ataulfo', 'precio' => 100,
            'unidad_medida' => 'kg', 'activo' => true,
        ]);
        CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Mango inactivo', 'precio' => 100,
            'unidad_medida' => 'kg', 'activo' => false,
        ]);

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL.'/buscar?q=Mango');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Mango Ataulfo');
    }

    public function test_puede_alternar_el_estado_activo(): void
    {
        $producto = CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Producto', 'precio' => 10,
            'unidad_medida' => 'pieza', 'activo' => true,
        ]);

        $response = $this->withHeaders($this->crmHeaders())->patchJson(self::BASE_URL."/{$producto->id}/toggle-activo");

        $response->assertOk()->assertJsonPath('data.activo', false);
    }

    public function test_no_puede_actualizar_un_producto_de_otra_empresa(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $producto = CrmProducto::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Ajeno', 'precio' => 10, 'unidad_medida' => 'pieza',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL."/{$producto->id}", [
            'nombre' => 'Hackeado',
        ]);

        $response->assertStatus(404);
    }
}
