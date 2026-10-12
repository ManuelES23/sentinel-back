<?php

namespace Tests\Feature\Inventario\Peps;

use App\Models\Area;
use App\Models\CapaCosto;
use App\Models\InventoryStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class ArranqueCapasTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures;

    private function migrar(): void
    {
        (require database_path('migrations/2026_10_11_100100_crear_capas_iniciales_de_inventario.php'))->up();
    }

    private function valorCapas(): float
    {
        return (float) DB::table('inventory_cost_layers')->selectRaw('SUM(cantidad_restante * unit_cost) v')->value('v');
    }

    public function test_migracion_crea_capas_iniciales_une_duplicados_y_conserva_el_valor(): void
    {
        $this->setUpAlmacenFixtures();
        $this->darStock($this->almacenA, $this->insumo, 10);          // 10 × 10
        $this->darStock($this->almacenA, $this->insumo, 5);           // duplicado sin lote: 5 × 10
        $this->darStock($this->almacenB, $this->insumo, 4, 'L-1');    // 4 × 10
        $valorAntes = (float) InventoryStock::sum('total_cost');

        $this->migrar();

        $this->assertSame(2, InventoryStock::count());
        $this->assertSame(2, CapaCosto::where('origen', CapaCosto::ORIGEN_INICIAL)->count());
        $this->assertEqualsWithDelta($valorAntes, $this->valorCapas(), 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_verificar_reporta_diferencias(): void
    {
        $this->setUpAlmacenFixtures();
        $this->darStock($this->almacenA, $this->insumo, 10); // sin capas

        $this->artisan('inventario:verificar-capas')->assertExitCode(1);
    }

    public function test_correr_dos_veces_no_duplica_capas(): void
    {
        $this->setUpAlmacenFixtures();
        $this->darStock($this->almacenA, $this->insumo, 10);
        $this->darStock($this->almacenB, $this->insumo, 4, 'L-1');

        $this->migrar();
        $this->migrar();

        $this->assertSame(2, CapaCosto::count());
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_no_crea_capa_inicial_si_la_cubeta_ya_tiene_capas(): void
    {
        $this->setUpAlmacenFixtures();
        $fila = $this->darStock($this->almacenA, $this->insumo, 10);
        CapaCosto::create([
            'product_id' => $fila->product_id, 'entity_id' => $fila->entity_id, 'area_id' => null, 'lot_number' => null,
            'fecha_entrada' => now(), 'unit_cost' => 10, 'cantidad_inicial' => 3, 'cantidad_restante' => 3,
            'origen' => CapaCosto::ORIGEN_ENTRADA,
        ]);

        $this->migrar();

        $this->assertSame(1, CapaCosto::count());
        $this->assertSame(CapaCosto::ORIGEN_ENTRADA, CapaCosto::first()->origen);
    }

    public function test_lote_vacio_y_nulo_se_unen_y_el_lote_queda_nulo(): void
    {
        $this->setUpAlmacenFixtures();
        $this->darStock($this->almacenA, $this->insumo, 6);
        $this->darStock($this->almacenA, $this->insumo, 4, '');

        $this->migrar();

        $this->assertSame(1, InventoryStock::count());
        $this->assertNull(InventoryStock::first()->lot_number);
        $this->assertSame(1, CapaCosto::count());
        $this->assertNull(CapaCosto::first()->lot_number);
        $this->assertEqualsWithDelta(10.0, (float) CapaCosto::first()->cantidad_restante, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_union_con_costos_distintos_promedia_y_no_pierde_valor(): void
    {
        $this->setUpAlmacenFixtures();
        $a = $this->darStock($this->almacenA, $this->insumo, 3);
        $b = $this->darStock($this->almacenA, $this->insumo, 7);
        $a->update(['unit_cost' => 10, 'total_cost' => 30]);
        $b->update(['unit_cost' => 3.3333, 'total_cost' => 23.3331]);
        $valorAntes = 30 + 23.3331;

        $this->migrar();

        $fila = InventoryStock::first();
        $this->assertSame(1, InventoryStock::count());
        $this->assertEqualsWithDelta(10.0, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta($valorAntes, (float) $fila->total_cost, 0.0001);
        $this->assertEqualsWithDelta($valorAntes, $this->valorCapas(), 0.01);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_union_suma_las_reservas_de_las_filas_duplicadas(): void
    {
        $this->setUpAlmacenFixtures();
        $this->darStock($this->almacenA, $this->insumo, 10)->update(['reserved_quantity' => 2]);
        $this->darStock($this->almacenA, $this->insumo, 5)->update(['reserved_quantity' => 1]);

        $this->migrar();

        $this->assertEqualsWithDelta(3.0, (float) InventoryStock::first()->reserved_quantity, 0.0001);
    }

    public function test_fila_negativa_crea_capa_faltante_y_las_de_cero_no_crean_nada(): void
    {
        $this->setUpAlmacenFixtures();
        $this->darStock($this->almacenA, $this->insumo, -4);
        $this->darStock($this->almacenB, $this->insumo, 0);

        $this->migrar();

        $this->assertSame(1, CapaCosto::count());
        $capa = CapaCosto::first();
        $this->assertSame(CapaCosto::ORIGEN_FALTANTE, $capa->origen);
        $this->assertEqualsWithDelta(0.0, (float) $capa->cantidad_inicial, 0.0001);
        $this->assertEqualsWithDelta(-4.0, (float) $capa->cantidad_restante, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_cantidad_grande_con_unit_cost_redondeado_no_deja_diferencia_de_valor(): void
    {
        $this->setUpAlmacenFixtures();
        $this->darStock($this->almacenA, $this->insumo, 100000)->update(['unit_cost' => 3.3333, 'total_cost' => 333333.33]);

        $this->migrar();

        $this->assertEqualsWithDelta(333333.33, $this->valorCapas(), 0.05);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_dato_legado_inconsistente_conserva_unit_cost_y_lo_reporta_verificar(): void
    {
        $this->setUpAlmacenFixtures();
        $this->darStock($this->almacenA, $this->insumo, 10)->update(['unit_cost' => 10, 'total_cost' => 500]);

        $this->migrar();

        $this->assertEqualsWithDelta(10.0, (float) CapaCosto::first()->unit_cost, 0.000001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(1);
    }

    public function test_existencias_huerfanas_se_omiten_sin_abortar_y_verificar_las_reporta(): void
    {
        $this->setUpAlmacenFixtures();
        $this->darStock($this->almacenA, $this->insumo, 10);
        $ahora = now();
        $base = ['product_id' => $this->insumo->id, 'quantity' => 5, 'reserved_quantity' => 0, 'unit_cost' => 10, 'total_cost' => 50, 'created_at' => $ahora, 'updated_at' => $ahora];
        // Sin FK en inventory_stock: un almacén y un área borrados a mano dejan filas huérfanas.
        $idEntidadHuerfana = DB::table('inventory_stock')->insertGetId($base + ['entity_id' => 987654, 'area_id' => null]);
        $idAreaHuerfana = DB::table('inventory_stock')->insertGetId($base + ['entity_id' => $this->almacenB->id, 'area_id' => 987655]);

        $this->migrar();

        $this->assertSame(1, CapaCosto::count());
        $this->assertSame($this->almacenA->id, (int) CapaCosto::first()->entity_id);
        $this->assertSame(3, InventoryStock::count());
        $this->artisan('inventario:verificar-capas')->assertExitCode(1);
        $this->assertNull(DB::table('inventory_cost_layers')->where('entity_id', 987654)->first());
        $this->assertNotNull($idEntidadHuerfana);
        $this->assertNotNull($idAreaHuerfana);
    }

    public function test_union_con_area_y_lote_con_espacios_no_choca_con_el_indice_unico(): void
    {
        $this->setUpAlmacenFixtures();
        $area = Area::create(['code' => 'ARE-ARR', 'name' => 'Bodega', 'slug' => 'bodega-arr', 'is_active' => true]);
        foreach ([[' L-1', 6], ['L-1', 4]] as [$lote, $cantidad]) {
            InventoryStock::create([
                'product_id' => $this->insumo->id, 'entity_id' => $this->almacenA->id, 'area_id' => $area->id,
                'quantity' => $cantidad, 'reserved_quantity' => 0, 'unit_cost' => 10, 'total_cost' => $cantidad * 10, 'lot_number' => $lote,
            ]);
        }

        $this->migrar();

        $this->assertSame(1, InventoryStock::count());
        $fila = InventoryStock::first();
        $this->assertSame('L-1', $fila->lot_number);
        $this->assertEqualsWithDelta(10.0, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $fila->total_cost, 0.0001);
        $this->assertSame(1, CapaCosto::count());
        $this->assertEqualsWithDelta(100.0, $this->valorCapas(), 0.01);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_lotes_que_solo_difieren_en_mayusculas_son_la_misma_cubeta(): void
    {
        $this->setUpAlmacenFixtures();
        $this->darStock($this->almacenA, $this->insumo, 6, 'L-1');
        $this->darStock($this->almacenA, $this->insumo, 4, 'l-1');

        $this->migrar();

        $this->assertSame(1, InventoryStock::count());
        $this->assertSame('L-1', InventoryStock::first()->lot_number);
        $this->assertEqualsWithDelta(100.0, (float) InventoryStock::first()->total_cost, 0.0001);
        $this->assertSame(1, CapaCosto::count());
        $this->assertEqualsWithDelta(100.0, $this->valorCapas(), 0.01);
        $this->migrar();
        $this->assertSame(1, CapaCosto::count());
    }

    public function test_down_borra_solo_las_capas_de_arranque(): void
    {
        $this->setUpAlmacenFixtures();
        $fila = $this->darStock($this->almacenA, $this->insumo, 10);
        $this->migrar();
        CapaCosto::create([
            'product_id' => $fila->product_id, 'entity_id' => $this->almacenB->id, 'fecha_entrada' => now(), 'unit_cost' => 1,
            'cantidad_inicial' => 1, 'cantidad_restante' => 1, 'origen' => CapaCosto::ORIGEN_ENTRADA,
        ]);

        (require database_path('migrations/2026_10_11_100100_crear_capas_iniciales_de_inventario.php'))->down();

        $this->assertSame(1, CapaCosto::count());
        $this->assertSame(CapaCosto::ORIGEN_ENTRADA, CapaCosto::first()->origen);
    }
}
