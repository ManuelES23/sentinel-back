<?php

namespace Tests\Unit\Inventory;

use App\Models\CapaCosto;
use App\Models\ConsumoCapa;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementDetail;
use App\Models\InventoryStock;
use App\Services\Inventory\CapasCosto;
use App\Support\Inventario\Cubeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class CapasCostoDevolucionTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures;

    private CapasCosto $motor;
    private Cubeta $a;
    private Cubeta $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
        $this->motor = app(CapasCosto::class);
        $this->a = new Cubeta($this->insumo->id, $this->almacenA->id);
        $this->b = new Cubeta($this->insumo->id, $this->almacenB->id);
        $this->entra($this->a, 8, 100, '2026-10-01');
        $this->entra($this->a, 50, 120, '2026-10-05');
    }

    /** Detalle real de movimiento (las capas y consumos tienen llave foránea a movimientos). */
    private function detalle(): InventoryMovementDetail
    {
        $movimiento = InventoryMovement::create([
            'document_number' => 'TEST-' . str_pad((string) (InventoryMovement::withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT),
            'movement_type_id' => $this->tipoTransferencia->id,
            'movement_date' => '2026-10-08',
            'source_entity_id' => $this->almacenA->id,
            'source_entity_type' => 'entity',
            'destination_entity_id' => $this->almacenB->id,
            'destination_entity_type' => 'entity',
            'status' => 'approved',
            'created_by' => $this->crearAdmin()->id,
        ]);

        return InventoryMovementDetail::create([
            'movement_id' => $movimiento->id,
            'product_id' => $this->insumo->id,
            'quantity' => 1,
            'unit_cost' => 1,
            'total_cost' => 1,
        ]);
    }

    private function entra(Cubeta $c, float $q, float $costo, string $fecha, ?int $detalle = null): void
    {
        $this->motor->entrar($c, $q, $costo, Carbon::parse($fecha), CapaCosto::ORIGEN_COMPRA, null, $detalle);
        InventoryStock::ajustarCantidad($c, $q);
    }

    private function valor(Cubeta $c): float
    {
        return (float) $c->filtrar(InventoryStock::query())->value('total_cost');
    }

    public function test_devolucion_parcial_regresa_primero_lo_ultimo_que_se_tomo(): void
    {
        $this->motor->salir($this->a, 10, null, null, 'apl-1'); // 8×100 + 2×120
        InventoryStock::ajustarCantidad($this->a, -10);

        $r = $this->motor->devolver($this->a, 3, null, 'apl-1'); // 2×120 + 1×100
        InventoryStock::ajustarCantidad($this->a, $r->cantidad);

        $this->assertEqualsWithDelta(340, $r->importe, 0.0001);
        $this->assertEqualsWithDelta(1, (float) CapaCosto::where('unit_cost', 100)->value('cantidad_restante'), 0.0001);
        $this->assertEqualsWithDelta(50, (float) CapaCosto::where('unit_cost', 120)->value('cantidad_restante'), 0.0001);
        $this->assertEqualsWithDelta(6100, $this->valor($this->a), 0.0001);
    }

    public function test_devolucion_total_deja_todo_como_antes(): void
    {
        $this->motor->salir($this->a, 10, null, null, 'apl-1');
        InventoryStock::ajustarCantidad($this->a, -10);
        $r = $this->motor->devolver($this->a, 10, null, 'apl-1');
        InventoryStock::ajustarCantidad($this->a, $r->cantidad);

        $this->assertEqualsWithDelta(6800, $this->valor($this->a), 0.0001);
        $this->assertSame('2026-10-01', $this->motor->fechaMasVieja($this->a)->toDateString());
    }

    public function test_devolver_a_otra_cubeta_conserva_costo_y_fecha(): void
    {
        $d = $this->detalle()->id;
        $this->motor->salir($this->a, 10, null, $d);
        InventoryStock::ajustarCantidad($this->a, -10);

        $r = $this->motor->devolver($this->a, 10, $d, null, $this->b);
        InventoryStock::ajustarCantidad($this->b, $r->cantidad);

        $this->assertEqualsWithDelta(1040, $this->valor($this->b), 0.0001);
        $this->assertSame('2026-10-01', $this->motor->fechaMasVieja($this->b)->toDateString());
        $this->assertSame(2, CapaCosto::deCubeta($this->b)->whereNotNull('capa_origen_id')->where('movement_detail_id', $d)->count());
    }

    public function test_devolver_sin_porciones_usa_costo_de_respaldo(): void
    {
        $r = $this->motor->devolver($this->a, 4, $this->detalle()->id, null, null, 90);
        InventoryStock::ajustarCantidad($this->a, $r->cantidad);

        $this->assertEqualsWithDelta(360, $r->importe, 0.0001);
        $this->assertSame(CapaCosto::ORIGEN_DEVOLUCION, CapaCosto::latest('id')->value('origen'));
    }

    private function capasSuma(Cubeta $c): array
    {
        $k = CapaCosto::deCubeta($c)->get();

        return [
            round($k->sum(fn ($x) => (float) $x->cantidad_restante), 4),
            round($k->sum(fn ($x) => (float) $x->cantidad_restante * (float) $x->unit_cost), 4),
        ];
    }

    private function filaDe(Cubeta $c): array
    {
        $f = $c->filtrar(InventoryStock::query())->first();

        return [round((float) $f->quantity, 4), round((float) $f->total_cost, 4)];
    }

    private function entraNeg(Cubeta $c, float $q, float $costo, string $fecha, ?int $detalle = null): void
    {
        $this->motor->entrar($c, $q, $costo, Carbon::parse($fecha), CapaCosto::ORIGEN_COMPRA, null, $detalle);
        InventoryStock::ajustarCantidad($c, $q, null, null, true);
    }

    private function saleNeg(Cubeta $c, float $q, ?int $detalle = null, ?string $ref = null): \App\Support\Inventario\ResultadoMovimiento
    {
        $r = $this->motor->salir($c, $q, null, $detalle, $ref, true);
        InventoryStock::ajustarCantidad($c, -$r->cantidad, null, null, true);

        return $r;
    }

    public function test_devolver_usa_el_costo_del_consumo_y_no_el_actual_de_la_capa(): void
    {
        // Faltante abierto a 100, cerrado, re-preciado a 200 y reabierto.
        $this->entraNeg($this->b, 8, 100, '2026-10-01');
        $this->saleNeg($this->b, 10, null, 'x');          // 8@100 + faltante 2@100
        $this->entraNeg($this->b, 5, 200, '2026-10-09');  // cubre el faltante, queda 3@200
        $this->saleNeg($this->b, 6, null, 'y');           // 3@200 + faltante re-preciado a 200 (-3)
        $antes = $this->filaDe($this->b);

        $d = $this->motor->devolver($this->b, 2, null, 'x');
        InventoryStock::ajustarCantidad($this->b, $d->cantidad, null, null, true);
        $despues = $this->filaDe($this->b);

        $this->assertEqualsWithDelta(200, $d->importe, 0.0001);
        $this->assertEqualsWithDelta(200, $despues[1] - $antes[1], 0.0001);
        $this->assertEqualsWithDelta($despues[0], $this->capasSuma($this->b)[0], 0.0001);
        $this->assertEqualsWithDelta($despues[1], $this->capasSuma($this->b)[1], 0.0001);
    }

    public function test_devolver_mixto_porciones_y_respaldo_no_reconcilia_contra_fila_vieja(): void
    {
        $this->motor->salir($this->a, 4, null, null, 'z'); // 4@100
        InventoryStock::ajustarCantidad($this->a, -4);

        $d = $this->motor->devolver($this->a, 10, null, 'z', null, 90); // 4 con porciones + 6 a 90
        InventoryStock::ajustarCantidad($this->a, $d->cantidad);

        $this->assertEqualsWithDelta(10, $d->cantidad, 0.0001);
        $this->assertEqualsWithDelta(940, $d->importe, 0.0001);
        $fila = $this->filaDe($this->a);
        $this->assertEqualsWithDelta(64, $fila[0], 0.0001);
        $this->assertEqualsWithDelta(7340, $fila[1], 0.0001);
        $this->assertEqualsWithDelta(64, $this->capasSuma($this->a)[0], 0.0001);
        $this->assertEqualsWithDelta(7340, $this->capasSuma($this->a)[1], 0.0001);
        $this->assertEqualsWithDelta(8, (float) CapaCosto::where('unit_cost', 100)->value('cantidad_restante'), 0.0001);
    }

    public function test_deshacer_transferencia_no_restaura_sobre_capa_repreciada(): void
    {
        $d = $this->detalle()->id;
        // Origen limpio: cubeta C (lote 'L') con 8@100 y faltante 2@100.
        $c = new Cubeta($this->insumo->id, $this->almacenA->id, null, 'L');
        $this->entraNeg($c, 8, 100, '2026-10-01');
        $this->saleNeg($c, 10, $d);
        $t = $this->motor->devolver($c, 10, $d, null, $this->b);
        InventoryStock::ajustarCantidad($this->b, $t->cantidad, null, null, true);
        $this->entraNeg($c, 5, 200, '2026-10-09');
        $this->saleNeg($c, 6); // faltante re-preciado a 200 (-3)
        $antes = $this->filaDe($c);
        $antesB = $this->filaDe($this->b);

        $x = $this->motor->deshacerTransferencia($c, $this->b, $d, 10, 0);
        InventoryStock::ajustarCantidad($this->b, -$x->cantidad, null, null, true);
        InventoryStock::ajustarCantidad($c, $x->cantidad, null, null, true);

        $this->assertEqualsWithDelta(1000, $x->importe, 0.0001);
        $this->assertEqualsWithDelta(1000, $this->filaDe($c)[1] - $antes[1], 0.0001);
        $this->assertEqualsWithDelta(-1000, $this->filaDe($this->b)[1] - $antesB[1], 0.0001);
        $this->assertEqualsWithDelta($this->filaDe($c)[0], $this->capasSuma($c)[0], 0.0001);
        $this->assertEqualsWithDelta($this->filaDe($c)[1], $this->capasSuma($c)[1], 0.0001);
        $this->assertEqualsWithDelta(0, $this->capasSuma($this->b)[0], 0.0001);
    }

    public function test_deshacer_transferencia_con_capa_original_de_otra_cubeta_crea_capa_en_origen(): void
    {
        $d = $this->detalle()->id;
        $this->motor->salir($this->a, 10, null, $d);
        InventoryStock::ajustarCantidad($this->a, -10);
        $r = $this->motor->devolver($this->a, 10, $d, null, $this->b);
        InventoryStock::ajustarCantidad($this->b, $r->cantidad);

        // Se deshace hacia otra cubeta de origen (lote 'X'): la capa original es de la cubeta A.
        $otra = new Cubeta($this->insumo->id, $this->almacenA->id, null, 'X');
        $x = $this->motor->deshacerTransferencia($otra, $this->b, $d, 10, 0);
        InventoryStock::ajustarCantidad($this->b, -$x->cantidad);
        InventoryStock::ajustarCantidad($otra, $x->cantidad);

        $this->assertEqualsWithDelta(0, (float) CapaCosto::where('unit_cost', 100)->whereNull('lot_number')->value('cantidad_restante'), 0.0001);
        $this->assertEqualsWithDelta(10, $this->capasSuma($otra)[0], 0.0001);
        $this->assertEqualsWithDelta(1040, $this->capasSuma($otra)[1], 0.0001);
        $this->assertEqualsWithDelta(1040, $this->filaDe($otra)[1], 0.0001);
    }

    public function test_reetiquetar_concilia_el_destino_sin_capas(): void
    {
        $this->darStock($this->almacenA, $this->insumo, 5, 'SIN-LOTE'); // fila con costo 10 y sin capas
        $sinLote = new Cubeta($this->insumo->id, $this->almacenA->id, null, 'SIN-LOTE');

        $this->motor->reetiquetar($this->a, $sinLote);

        // Lo que ya había en el destino conserva su costo (5×10) y se suma lo reetiquetado (6800).
        $this->assertEqualsWithDelta(5 + 58, $this->capasSuma($sinLote)[0], 0.0001);
        $this->assertEqualsWithDelta(50 + 6800, $this->capasSuma($sinLote)[1], 0.0001);
    }

    public function test_revertir_entrada_totalmente_absorbida_por_faltante_es_limitacion_conocida(): void
    {
        // LIMITACION CONOCIDA (registrada para la revision final): una entrada que cubrio por
        // completo un faltante no deja capa con su detalle; revertirEntrada cae a la ruta legada
        // (salir) y, sin existencias, lanza "stock insuficiente" en lugar de reabrir el faltante.
        $d = $this->detalle()->id;
        $this->entraNeg($this->b, 8, 100, '2026-10-01');
        $this->saleNeg($this->b, 13);
        $this->entraNeg($this->b, 5, 150, '2026-10-09', $d); // cubre todo el faltante: sin capa con $d
        $this->assertSame(0, CapaCosto::where('movement_detail_id', $d)->count());

        try {
            $this->motor->revertirEntrada($this->b, $d, 5);
            $this->fail('Debió lanzar');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }
    }

    public function test_revertir_entrada_intacta_y_usada(): void
    {
        $d1 = $this->detalle()->id;
        $d2 = $this->detalle()->id;

        $this->entra($this->b, 5, 130, '2026-10-07', $d1);
        $r = $this->motor->revertirEntrada($this->b, $d1, 5);
        InventoryStock::ajustarCantidad($this->b, -$r->cantidad);
        $this->assertSame(0, CapaCosto::where('movement_detail_id', $d1)->count());

        $this->entra($this->b, 5, 130, '2026-10-07', $d2);
        $this->motor->salir($this->b, 1);
        InventoryStock::ajustarCantidad($this->b, -1);

        try {
            $this->motor->revertirEntrada($this->b, $d2, 5);
            $this->fail('Debió lanzar');
        } catch (ValidationException $e) {
            $this->assertSame('Ya se usó parte de esta entrada; primero cancela o ajusta las salidas que la tomaron.', $e->errors()['movement'][0]);
        }
    }

    public function test_revertir_entrada_sin_capa_hace_salida_peps(): void
    {
        $r = $this->motor->revertirEntrada($this->a, 12345, 10);
        $this->assertEqualsWithDelta(1040, $r->importe, 0.0001);
    }

    public function test_deshacer_transferencia(): void
    {
        $d = $this->detalle()->id;
        $this->motor->salir($this->a, 10, null, $d);
        InventoryStock::ajustarCantidad($this->a, -10);
        $r = $this->motor->devolver($this->a, 10, $d, null, $this->b);
        InventoryStock::ajustarCantidad($this->b, $r->cantidad);

        $x = $this->motor->deshacerTransferencia($this->a, $this->b, $d, 10, 0);
        InventoryStock::ajustarCantidad($this->b, -$x->cantidad);
        InventoryStock::ajustarCantidad($this->a, $x->cantidad);

        $this->assertEqualsWithDelta(6800, $this->valor($this->a), 0.0001);
        $this->assertEqualsWithDelta(0, $this->valor($this->b), 0.0001);
    }

    public function test_deshacer_transferencia_con_destino_usado_se_bloquea(): void
    {
        $d = $this->detalle()->id;
        $this->motor->salir($this->a, 10, null, $d);
        InventoryStock::ajustarCantidad($this->a, -10);
        $r = $this->motor->devolver($this->a, 10, $d, null, $this->b);
        InventoryStock::ajustarCantidad($this->b, $r->cantidad);
        $this->motor->salir($this->b, 1);
        InventoryStock::ajustarCantidad($this->b, -1);

        $this->expectException(ValidationException::class);
        $this->motor->deshacerTransferencia($this->a, $this->b, $d, 10, 0);
    }

    public function test_reetiquetar_conserva_valor(): void
    {
        $sinLote = new Cubeta($this->insumo->id, $this->almacenA->id, null, 'SIN-LOTE');
        $this->motor->reetiquetar($this->a, $sinLote);

        $this->assertSame(0, CapaCosto::deCubeta($this->a)->count());
        $this->assertEqualsWithDelta(6800, (float) CapaCosto::deCubeta($sinLote)->get()->sum(fn ($k) => $k->cantidad_restante * $k->unit_cost), 0.0001);
    }
}
