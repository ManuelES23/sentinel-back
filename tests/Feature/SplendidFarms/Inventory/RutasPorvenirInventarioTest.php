<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Enterprise;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pantallas de Inventario que Splendid by Porvenir tiene en su menú y que
 * llamaban rutas registradas solo para Splendid Farms (404).
 */
class RutasPorvenirInventarioTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/splendidbyporvenir';

    private array $headers = ['X-Enterprise-Slug' => 'splendidbyporvenir'];

    protected function setUp(): void
    {
        parent::setUp();
        Enterprise::create(['name' => 'Splendid by Porvenir', 'slug' => 'splendidbyporvenir', 'description' => 'Comercio', 'is_active' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function assertRutaExiste(string $metodo, string $uri): void
    {
        $ruta = app('router')->getRoutes()->match(Request::create($uri, $metodo));
        $this->assertNotNull($ruta, "No hay ruta $metodo $uri");
    }

    public function test_porvenir_tiene_la_ruta_del_pdf_de_movimientos(): void
    {
        $this->assertRutaExiste('GET', self::BASE . '/inventario/operaciones/movimientos/1/pdf');
    }

    public function test_porvenir_puede_consultar_el_gasto_en_produccion(): void
    {
        $this->getJson(self::BASE . '/inventario/reportes/gasto-produccion', $this->headers)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_porvenir_lista_y_da_de_alta_proveedores_desde_la_orden_de_compra(): void
    {
        Supplier::create(['code' => 'PRV-1', 'business_name' => 'Papelería Central', 'is_active' => true]);

        $this->getJson(self::BASE . '/administration/catalogos/proveedores/list', $this->headers)
            ->assertOk()
            ->assertJsonPath('data.0.business_name', 'Papelería Central');

        $this->postJson(self::BASE . '/administration/catalogos/proveedores', [
            'business_name' => 'Empaques del Norte',
            'supplier_type' => 'national',
        ], $this->headers)->assertCreated();

        $this->assertDatabaseHas('suppliers', ['business_name' => 'Empaques del Norte']);
    }
}
