<?php

namespace Tests\Feature\Aplicaciones;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\Concerns\CreatesAplicacionesFixtures;
use Tests\TestCase;

class FlujoAplicacionConsumoTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures, CreatesAplicacionesFixtures;

    private const BASE = '/api/splendidfarms/operacion-agricola/agricola';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAplicacionesFixtures();
        Sanctum::actingAs($this->agronomo);
    }

    private function payload(float $dosis): array
    {
        return [
            'temporada_id' => $this->temporada->id,
            'almacen_id' => $this->almacenA->id,
            'fecha' => '2026-09-17',
            'tipo_aplicacion' => 'agroquimico',
            'productor_id' => $this->productor->id,
            'lote_id' => $this->lote->id,
            'superficie_aplicada' => 4,
            'problematica' => 'Tizón tardío',
            'productos' => [['product_id' => $this->insumo->id, 'dosis' => $dosis, 'unidad_dosis_id' => $this->unidad->id]],
        ];
    }

    private function dashboard(): array
    {
        return $this->getJson(self::BASE . '/costeo/dashboard?temporada_id=' . $this->temporada->id, $this->headersEmpresa())
            ->assertOk()
            ->json('data');
    }

    public function test_registrar_editar_y_eliminar_mantiene_stock_kardex_y_costeo_alineados(): void
    {
        $this->entrarStock($this->almacenA, $this->insumo, 5, 'L-1', 10);
        $this->entrarStock($this->almacenA, $this->insumo, 10, 'L-2', 20);

        // Alta: 2 L/ha x 4 ha = 8 L (5 @10 + 3 @20 = 110)
        $id = $this->postJson(self::BASE . '/aplicaciones', $this->payload(2), $this->headersEmpresa())
            ->assertCreated()->json('data.id');

        $this->assertEquals(7, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertEquals(7, $this->kardexSaldo($this->almacenA, $this->insumo));

        $tablero = $this->dashboard();
        $this->assertEquals(110, $tablero['total_general']);
        $this->assertSame('agroquimico', $tablero['por_categoria'][0]['categoria']);
        $this->assertEquals(110, $tablero['por_categoria'][0]['total']);
        $fuentes = collect($tablero['por_fuente'])->keyBy('tipo_fuente');
        $this->assertEquals(110, $fuentes['movimiento_inventario']['total']);
        $this->assertSame($this->lote->id, (int) $tablero['por_lote'][0]['lote_id']);
        $this->assertEquals(110, $tablero['por_lote'][0]['total']);

        // Edición: 1 L/ha x 4 ha = 4 L (4 @10 = 40 tomando del primer lote)
        $this->putJson(self::BASE . '/aplicaciones/' . $id, $this->payload(1), $this->headersEmpresa())->assertOk();

        $this->assertEquals(11, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertEquals(11, $this->kardexSaldo($this->almacenA, $this->insumo));
        $this->assertEquals(40, $this->dashboard()['total_general']);

        // Baja: todo vuelve
        $this->deleteJson(self::BASE . '/aplicaciones/' . $id, [], $this->headersEmpresa())->assertOk();

        $this->assertEquals(15, $this->stockTotal($this->almacenA, $this->insumo));
        $this->assertEquals(15, $this->kardexSaldo($this->almacenA, $this->insumo));
        $this->assertEquals(0, $this->dashboard()['total_general']);
    }
}
