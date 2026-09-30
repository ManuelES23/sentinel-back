<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Enterprise;
use App\Models\InventoryStock;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class ProductoControlLotesMasivoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    private const URL = '/api/splendidfarms/inventario/catalogos/articulos/control-lotes';

    private Product $segundo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
        $this->segundo = $this->crearArticulo('PROD-00002', 'Mancozeb');
        Sanctum::actingAs($this->crearAdmin());
    }

    private function crearArticulo(string $codigo, string $nombre, ?Enterprise $empresa = null): Product
    {
        $producto = Product::create([
            'code' => $codigo,
            'name' => $nombre,
            'unit_id' => $this->unidad->id,
            'product_type' => 'consumable',
            'track_inventory' => true,
        ]);
        $producto->enterprises()->attach(($empresa ?? $this->empresa)->id);

        return $producto;
    }

    private function aplicar(array $ids, string $control)
    {
        return $this->postJson(self::URL, ['product_ids' => $ids, 'control' => $control], $this->headersEmpresa());
    }

    public function test_lotes_y_caducidad_activa_las_dos_banderas_en_todos_los_seleccionados(): void
    {
        $this->aplicar([$this->insumo->id, $this->segundo->id], 'lotes_caducidad')
            ->assertOk()
            ->assertJsonPath('data.actualizados', [$this->insumo->id, $this->segundo->id])
            ->assertJsonPath('data.omitidos', []);

        foreach ([$this->insumo, $this->segundo] as $producto) {
            $producto->refresh();
            $this->assertTrue($producto->track_lots);
            $this->assertTrue($producto->track_expiry);
        }
    }

    public function test_solo_lotes_deja_la_caducidad_apagada(): void
    {
        $this->insumo->update(['track_lots' => true, 'track_expiry' => true]);

        $this->aplicar([$this->insumo->id], 'lotes')->assertOk();

        $this->insumo->refresh();
        $this->assertTrue($this->insumo->track_lots);
        $this->assertFalse($this->insumo->track_expiry);
    }

    public function test_sin_control_apaga_las_dos_banderas(): void
    {
        $this->insumo->update(['track_lots' => true, 'track_expiry' => true]);

        $this->aplicar([$this->insumo->id], 'ninguno')->assertOk();

        $this->insumo->refresh();
        $this->assertFalse($this->insumo->track_lots);
        $this->assertFalse($this->insumo->track_expiry);
    }

    public function test_activar_lotes_marca_las_existencias_sin_lote_como_sin_lote(): void
    {
        $this->darStock($this->almacenA, $this->insumo, 5);

        $this->aplicar([$this->insumo->id], 'lotes_caducidad')->assertOk();

        $this->assertSame('SIN-LOTE', InventoryStock::where('product_id', $this->insumo->id)->value('lot_number'));
    }

    public function test_omite_y_reporta_el_articulo_con_varios_lotes_en_existencia_pero_aplica_al_resto(): void
    {
        $this->insumo->update(['track_lots' => true]);
        $this->darStock($this->almacenA, $this->insumo, 1, 'L1');
        $this->darStock($this->almacenA, $this->insumo, 1, 'L2');
        $this->segundo->update(['track_lots' => true, 'track_expiry' => true]);

        $respuesta = $this->aplicar([$this->insumo->id, $this->segundo->id], 'ninguno')->assertOk();

        $respuesta->assertJsonPath('data.actualizados', [$this->segundo->id]);
        $respuesta->assertJsonPath('data.omitidos.0.id', $this->insumo->id);
        $respuesta->assertJsonPath('data.omitidos.0.code', 'PROD-00001');
        $this->assertStringContainsString('varios lotes', $respuesta->json('data.omitidos.0.motivo'));

        $this->assertTrue($this->insumo->fresh()->track_lots);
        $this->assertFalse($this->segundo->fresh()->track_lots);
        $this->assertFalse($this->segundo->fresh()->track_expiry);
    }

    public function test_no_toca_articulos_de_otra_empresa(): void
    {
        $otra = Enterprise::create(['name' => 'Otra', 'slug' => 'otra', 'description' => 'x', 'is_active' => true]);
        $ajeno = $this->crearArticulo('PROD-00099', 'Ajeno', $otra);

        $respuesta = $this->aplicar([$this->insumo->id, $ajeno->id], 'lotes_caducidad')->assertOk();

        $respuesta->assertJsonPath('data.actualizados', [$this->insumo->id]);
        $respuesta->assertJsonPath('data.omitidos.0.id', $ajeno->id);
        $this->assertFalse($ajeno->fresh()->track_lots);
        $this->assertFalse($ajeno->fresh()->track_expiry);
    }

    public function test_un_usuario_sin_acceso_a_la_empresa_recibe_403(): void
    {
        Sanctum::actingAs(\App\Models\User::factory()->create(['role' => 'user']));

        $this->aplicar([$this->insumo->id], 'lotes')->assertForbidden();
        $this->assertFalse($this->insumo->fresh()->track_lots);
    }

    public function test_valida_los_datos_de_entrada(): void
    {
        $this->postJson(self::URL, ['product_ids' => [], 'control' => 'lotes'], $this->headersEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors(['product_ids']);
        $this->postJson(self::URL, ['product_ids' => [$this->insumo->id], 'control' => 'todo'], $this->headersEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors(['control']);
    }
}
