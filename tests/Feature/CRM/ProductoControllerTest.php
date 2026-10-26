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

    public function test_store_acepta_commodity_y_unidad_caja(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'nombre' => 'Kent Mango',
            'precio' => 0,
            'unidad_medida' => 'caja',
            'commodity' => ' Mango ',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.commodity', 'Mango')
            ->assertJsonPath('data.unidad_medida', 'caja');
    }

    public function test_commodity_vacio_se_guarda_como_null(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'nombre' => 'Producto sin commodity',
            'precio' => 5,
            'unidad_medida' => 'pieza',
            'commodity' => '   ',
        ]);

        $response->assertCreated()->assertJsonPath('data.commodity', null);
    }

    public function test_update_cambia_el_commodity(): void
    {
        $producto = CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Kent Mango', 'precio' => 0,
            'unidad_medida' => 'caja', 'commodity' => 'Mango',
        ]);

        $this->withHeaders($this->crmHeaders())
            ->putJson(self::BASE_URL.'/'.$producto->id, ['commodity' => ' Fruta '])
            ->assertOk()
            ->assertJsonPath('data.commodity', 'Fruta');
    }

    public function test_buscar_devuelve_commodity(): void
    {
        CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Kent Mango', 'precio' => 0,
            'unidad_medida' => 'caja', 'commodity' => 'Mango',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL.'/buscar?q=Kent');

        $response->assertOk()->assertJsonPath('data.0.commodity', 'Mango');
    }

    public function test_commodities_lista_distintos_de_la_empresa(): void
    {
        $otra = $this->crearOtraEmpresa();
        foreach (['Mango', 'Mango', 'Corn', null] as $i => $commodity) {
            CrmProducto::create([
                'empresa_id' => $this->enterprise->id, 'nombre' => "P$i", 'precio' => 0,
                'unidad_medida' => 'caja', 'commodity' => $commodity,
            ]);
        }
        CrmProducto::create([
            'empresa_id' => $otra->id, 'nombre' => 'Pimiento', 'precio' => 0,
            'unidad_medida' => 'caja', 'commodity' => 'Pepper',
        ]);

        $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL.'/commodities')
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Operación exitosa', 'data' => ['Corn', 'Mango']]);
    }

    public function test_index_filtra_por_commodity(): void
    {
        foreach (['Kent Mango' => 'Mango', 'Haden Mango' => 'Mango', 'White Corn' => 'Corn'] as $nombre => $commodity) {
            CrmProducto::create([
                'empresa_id' => $this->enterprise->id, 'nombre' => $nombre, 'precio' => 0,
                'unidad_medida' => 'caja', 'commodity' => $commodity,
            ]);
        }

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL.'?commodity=Mango&all=1');

        $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.commodity', 'Mango');
    }
}
