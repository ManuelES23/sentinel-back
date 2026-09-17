<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\InventoryStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class ProductoLotesCaducidadTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
        $this->url = "/api/splendidfarms/inventario/catalogos/articulos/{$this->insumo->id}";
        Sanctum::actingAs($this->crearAdmin());
    }

    public function test_activar_caducidad_activa_lotes_y_marca_existencias_como_sin_lote(): void
    {
        $this->darStock($this->almacenA, $this->insumo, 5);

        $this->putJson($this->url, ['track_expiry' => true, 'dias_alerta_caducidad' => 45, 'ingrediente_activo' => 'Clorotalonil'], $this->headersEmpresa())
            ->assertOk();

        $this->insumo->refresh();
        $this->assertTrue($this->insumo->track_lots);
        $this->assertSame(45, $this->insumo->dias_alerta_caducidad);
        $this->assertSame('Clorotalonil', $this->insumo->ingrediente_activo);
        $this->assertSame('SIN-LOTE', InventoryStock::where('product_id', $this->insumo->id)->value('lot_number'));
    }

    public function test_sin_lote_se_fusiona_si_ya_existe_una_fila_sin_lote(): void
    {
        // qty 5 @ unit_cost 10 (total 50) desde darStock, más una fila SIN-LOTE
        // ya existente con costo distinto (qty 3 @ 30, total 90) para verificar
        // que la fusión promedia el costo por cantidad, no solo suma unidades.
        $this->darStock($this->almacenA, $this->insumo, 5);
        InventoryStock::create([
            'product_id' => $this->insumo->id,
            'entity_id' => $this->almacenA->id,
            'quantity' => 3,
            'reserved_quantity' => 0,
            'unit_cost' => 30,
            'total_cost' => 90,
            'lot_number' => 'SIN-LOTE',
        ]);

        $this->putJson($this->url, ['track_lots' => true], $this->headersEmpresa())->assertOk();

        $filas = InventoryStock::where('product_id', $this->insumo->id)->get();
        $this->assertCount(1, $filas);
        $fila = $filas->first();
        $this->assertEquals(8, $fila->quantity);
        $this->assertEquals(140, $fila->total_cost);
        $this->assertEqualsWithDelta(17.5, (float) $fila->unit_cost, 0.0001);
    }

    public function test_no_se_desactivan_lotes_con_varios_lotes_en_existencia(): void
    {
        $this->insumo->update(['track_lots' => true]);
        $this->darStock($this->almacenA, $this->insumo, 1, 'L1');
        $this->darStock($this->almacenA, $this->insumo, 1, 'L2');

        $response = $this->putJson($this->url, ['track_lots' => false], $this->headersEmpresa());
        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonStructure(['errors' => ['track_lots']]);
        $this->assertTrue($this->insumo->fresh()->track_lots);
    }

    public function test_editar_quita_por_revisar_y_el_filtro_lo_encuentra(): void
    {
        $this->insumo->update(['requiere_revision' => true]);

        $lista = $this->getJson('/api/splendidfarms/inventario/catalogos/articulos?requiere_revision=1', $this->headersEmpresa());
        $this->assertSame([$this->insumo->id], collect($lista->json('data'))->pluck('id')->all());

        $this->putJson($this->url, ['name' => 'Clorotalonil 720 SC'], $this->headersEmpresa())->assertOk();
        $this->assertFalse($this->insumo->fresh()->requiere_revision);
    }
}
