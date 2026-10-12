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

    public function test_devolver_usa_el_costo_del_consumo_y_no_el_actual_de_la_capa(): void
    {
        // Sale más de lo que hay: se abre un faltante al último costo (120).
        $r = $this->motor->salir($this->a, 60, null, null, 'apl-f', true);
        InventoryStock::ajustarCantidad($this->a, -$r->cantidad);
        // Una entrada a otro costo cierra el faltante (2) y deja una capa de 3 a 200.
        $this->entra($this->a, 5, 200, '2026-10-09');
        // Un nuevo faltante re-precia la capa de faltante (cerrada) al último costo: 200.
        $r2 = $this->motor->salir($this->a, 6, null, null, 'apl-g', true);
        InventoryStock::ajustarCantidad($this->a, -$r2->cantidad);
        $faltante = CapaCosto::where('origen', CapaCosto::ORIGEN_FALTANTE)->firstOrFail();
        $this->assertEqualsWithDelta(200, (float) $faltante->unit_cost, 0.0001);

        $d = $this->motor->devolver($this->a, 2, null, 'apl-f');

        // apl-f: 8×100 + 50×120 + 2×120 (faltante al costo de entonces, no a 200)
        $this->assertEqualsWithDelta(240, $d->importe, 0.0001);
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
