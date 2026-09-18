<?php

namespace Tests\Feature\Aplicaciones;

use App\Models\Aplicacion;
use App\Models\CosteoAgricola;
use App\Models\InventoryMovement;
use App\Models\UnitOfMeasure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\Concerns\CreatesAplicacionesFixtures;
use Tests\TestCase;

class AplicacionConsumoControllerTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures, CreatesAplicacionesFixtures;

    private const BASE = '/api/splendidfarms/operacion-agricola/agricola/aplicaciones';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAplicacionesFixtures();
        Sanctum::actingAs($this->agronomo);
    }

    private function payload(array $cambios = []): array
    {
        return array_merge([
            'temporada_id' => $this->temporada->id,
            'almacen_id' => $this->almacenA->id,
            'fecha' => '2026-09-17',
            'tipo_aplicacion' => 'agroquimico',
            'productor_id' => $this->productor->id,
            'lote_id' => $this->lote->id,
            'superficie_aplicada' => 4,
            'problematica' => 'Tizón tardío',
            'productos' => [['product_id' => $this->insumo->id, 'dosis' => 2, 'unidad_dosis_id' => $this->unidad->id]],
        ], $cambios);
    }

    private function crearPorApi(array $cambios = []): int
    {
        return $this->postJson(self::BASE, $this->payload($cambios), $this->headersEmpresa())
            ->assertCreated()
            ->json('data.id');
    }

    public function test_store_descuenta_stock_y_crea_movimiento_y_costeo(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);

        $response = $this->postJson(self::BASE, $this->payload(), $this->headersEmpresa());

        $response->assertCreated();
        $this->assertEquals(12, $this->stockTotal($this->almacenA, $this->insumo));
        $aplicacion = Aplicacion::findOrFail($response->json('data.id'));
        $this->assertSame($this->empresa->id, (int) $aplicacion->enterprise_id);
        $this->assertSame($this->almacenA->id, (int) $aplicacion->almacen_id);
        $this->assertNotNull($aplicacion->inventory_movement_id);
        $this->assertSame('L/ha', $aplicacion->detalles()->first()->unidad_medida);
        $this->assertEquals(80, CosteoAgricola::where('fuente_id', $aplicacion->id)->sole()->costo_total);
    }

    public function test_store_sin_stock_da_422_y_no_deja_nada(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 5, 'L-1', 10);

        $this->postJson(self::BASE, $this->payload(), $this->headersEmpresa())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['productos.0.dosis']);

        $this->assertSame(0, Aplicacion::withTrashed()->count());
        $this->assertSame(0, InventoryMovement::withTrashed()->count());
        $this->assertSame(0, CosteoAgricola::withTrashed()->count());
        $this->assertEquals(5, $this->stockTotal($this->almacenA, $this->insumo));
    }

    public function test_store_con_unidad_de_otro_tipo_da_422(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);

        $this->postJson(self::BASE, $this->payload([
            'productos' => [['product_id' => $this->insumo->id, 'dosis' => 1, 'unidad_dosis_id' => $this->kilo->id]],
        ]), $this->headersEmpresa())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['productos.0.unidad_dosis_id']);

        $this->assertSame(0, Aplicacion::withTrashed()->count());
        $this->assertEquals(20, $this->stockTotal($this->almacenA, $this->insumo));
    }

    public function test_store_con_unidad_que_no_es_peso_ni_volumen_da_422(): void
    {
        $unidad = UnitOfMeasure::create(['code' => 'UND', 'name' => 'Unidad', 'abbreviation' => 'und']);
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);

        $this->postJson(self::BASE, $this->payload([
            'productos' => [['product_id' => $this->insumo->id, 'dosis' => 1, 'unidad_dosis_id' => $unidad->id]],
        ]), $this->headersEmpresa())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['productos.0.unidad_dosis_id']);
    }

    public function test_store_exige_almacen_y_superficie(): void
    {
        $this->postJson(self::BASE, $this->payload(['almacen_id' => null]), $this->headersEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors(['almacen_id']);

        $this->postJson(self::BASE, $this->payload(['superficie_aplicada' => null]), $this->headersEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors(['superficie_aplicada']);
    }

    public function test_store_en_un_almacen_no_visible_da_403(): void
    {
        $this->darLote($this->almacenB, $this->insumo, 20, 'L-1', 10);

        $this->postJson(self::BASE, $this->payload(['almacen_id' => $this->almacenB->id]), $this->headersEmpresa())
            ->assertStatus(403);

        $this->assertSame(0, Aplicacion::withTrashed()->count());
        $this->assertEquals(20, $this->stockTotal($this->almacenB, $this->insumo));
    }

    public function test_update_reconsume_con_los_datos_nuevos(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $id = $this->crearPorApi(); // 8 L, quedan 12

        $this->putJson(self::BASE . '/' . $id, [
            'productos' => [['product_id' => $this->insumo->id, 'dosis' => 1, 'unidad_dosis_id' => $this->unidad->id]],
        ], $this->headersEmpresa())->assertOk();

        $this->assertEquals(16, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertEquals(4, CosteoAgricola::where('fuente_id', $id)->sole()->cantidad);
        $this->assertSame(1, InventoryMovement::where('reference_type', 'aplicacion')->count());
    }

    public function test_update_que_cambia_de_almacen_devuelve_el_stock_al_original(): void
    {
        $usuario = $this->crearUsuarioDeCampo([$this->almacenA, $this->almacenB]);
        Sanctum::actingAs($usuario);
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $this->darLote($this->almacenB, $this->insumo, 20, 'L-1', 10);
        $id = $this->crearPorApi();

        $this->putJson(self::BASE . '/' . $id, ['almacen_id' => $this->almacenB->id], $this->headersEmpresa())->assertOk();

        $this->assertEquals(20, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertEquals(12, $this->stockTotal($this->almacenB, $this->insumo));
    }

    public function test_update_sin_stock_suficiente_da_422_y_deja_el_consumo_anterior(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $id = $this->crearPorApi(); // quedan 12

        $this->putJson(self::BASE . '/' . $id, [
            'productos' => [['product_id' => $this->insumo->id, 'dosis' => 10, 'unidad_dosis_id' => $this->unidad->id]],
        ], $this->headersEmpresa())->assertStatus(422);

        $this->assertEquals(12, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertEquals(8, CosteoAgricola::where('fuente_id', $id)->sole()->cantidad);
        $this->assertEquals(2, Aplicacion::findOrFail($id)->detalles()->first()->dosis);
    }

    public function test_destroy_revierte_el_stock_y_da_de_baja_la_aplicacion(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $id = $this->crearPorApi();

        $this->deleteJson(self::BASE . '/' . $id, [], $this->headersEmpresa())->assertOk();

        $this->assertEquals(20, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertSame(0, CosteoAgricola::count());
        $this->assertTrue(Aplicacion::withTrashed()->findOrFail($id)->trashed());
    }

    public function test_una_aplicacion_historica_se_edita_sin_tocar_el_inventario(): void
    {
        $this->darLote($this->almacenA, $this->insumo, 20, 'L-1', 10);
        $aplicacion = $this->crearAplicacion(['almacen_id' => null, 'enterprise_id' => null]);

        $this->putJson(self::BASE . '/' . $aplicacion->id, ['problematica' => 'Corregida'], $this->headersEmpresa())->assertOk();

        $this->assertSame('Corregida', $aplicacion->fresh()->problematica);
        $this->assertEquals(20, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertSame(0, InventoryMovement::withTrashed()->count());
    }

    public function test_una_aplicacion_historica_se_elimina_sin_error(): void
    {
        $aplicacion = $this->crearAplicacion(['almacen_id' => null, 'enterprise_id' => null]);

        $this->deleteJson(self::BASE . '/' . $aplicacion->id, [], $this->headersEmpresa())->assertOk();

        $this->assertTrue(Aplicacion::withTrashed()->findOrFail($aplicacion->id)->trashed());
    }

    public function test_editar_o_eliminar_una_aplicacion_de_un_almacen_no_visible_da_403(): void
    {
        $aplicacion = $this->crearAplicacion(['almacen_id' => $this->almacenB->id]);

        $this->putJson(self::BASE . '/' . $aplicacion->id, ['problematica' => 'x'], $this->headersEmpresa())->assertStatus(403);
        $this->deleteJson(self::BASE . '/' . $aplicacion->id, [], $this->headersEmpresa())->assertStatus(403);

        $this->assertFalse($aplicacion->fresh()->trashed());
    }
}
