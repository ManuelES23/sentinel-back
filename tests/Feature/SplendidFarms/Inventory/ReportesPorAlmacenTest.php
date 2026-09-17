<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\InventoryKardex;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class ReportesPorAlmacenTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    private const BASE = '/api/splendidfarms/inventario/reportes';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
        $this->darStock($this->almacenA, $this->insumo, 5, 'L-A', now()->addDays(10)->toDateString());
        $this->darStock($this->almacenB, $this->insumo, 7, 'L-B', now()->subDay()->toDateString());
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));
    }

    public function test_stock_solo_incluye_almacenes_visibles_y_marca_caducidad(): void
    {
        $response = $this->getJson(self::BASE . '/stock', $this->headersEmpresa());

        $response->assertOk();
        $filas = collect($response->json('data.stock'));
        $this->assertSame([$this->almacenA->id], $filas->pluck('entity_id')->unique()->values()->all());
        $this->assertFalse($filas->first()['vencido']);
        $this->assertTrue($filas->first()['por_caducar']);
    }

    public function test_valorizado_solo_suma_stock_visible(): void
    {
        $response = $this->getJson(self::BASE . '/valorizado', $this->headersEmpresa());

        $response->assertOk();
        $fila = collect($response->json('data.products'))->firstWhere('id', $this->insumo->id);
        $this->assertEquals(5, $fila['quantity']);
    }

    public function test_valorizado_con_stock_only_filtra_por_stock_visible(): void
    {
        $sinStock = Product::create([
            'code' => 'PROD-00002',
            'name' => 'Sin existencias',
            'unit_id' => $this->unidad->id,
            'product_type' => 'consumable',
            'track_inventory' => true,
        ]);
        $sinStock->enterprises()->attach($this->empresa->id);

        $response = $this->getJson(self::BASE . '/valorizado?with_stock_only=1', $this->headersEmpresa());

        $response->assertOk();
        $productos = collect($response->json('data.products'));
        $conStock = $productos->firstWhere('id', $this->insumo->id);
        $this->assertNotNull($conStock);
        $this->assertEquals(5, $conStock['quantity']);
        $this->assertNull($productos->firstWhere('id', $sinStock->id));
    }

    public function test_alertas_no_incluyen_lotes_de_almacenes_ajenos(): void
    {
        $response = $this->getJson(self::BASE . '/alertas', $this->headersEmpresa());

        $response->assertOk();
        $lotes = collect($response->json('data.alerts'))->pluck('lot_number')->filter();
        $this->assertFalse($lotes->contains('L-B'));
    }

    public function test_movimientos_y_kardex_solo_de_almacenes_visibles(): void
    {
        foreach ([$this->almacenA, $this->almacenB] as $almacen) {
            $mov = InventoryMovement::create([
                'document_number' => 'IN-' . $almacen->id,
                'movement_type_id' => $this->tipoEntrada->id,
                'destination_entity_id' => $almacen->id,
                'movement_date' => now(),
                'status' => 'approved',
            ]);
            InventoryKardex::recordEntry($this->insumo->id, $almacen->id, null, $mov->id, 'increase', 1, 10);
        }

        $mov = $this->getJson(self::BASE . '/movimientos', $this->headersEmpresa());
        $mov->assertOk();
        $this->assertSame([$this->almacenA->id], collect($mov->json('data.data'))->pluck('entity_id')->unique()->values()->all());

        $kardex = $this->getJson(self::BASE . '/kardex/' . $this->insumo->id, $this->headersEmpresa());
        $kardex->assertOk();
        $this->assertStringNotContainsString('IN-' . $this->almacenB->id, $kardex->getContent());
    }

    public function test_sin_header_da_422(): void
    {
        $this->getJson(self::BASE . '/stock')->assertStatus(422);
    }
}
