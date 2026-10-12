<?php

namespace Tests\Feature\Inventario\Peps;

use App\Models\CapaCosto;
use App\Models\InventoryKardex;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class MovimientosPepsTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures;

    private const URL = '/api/splendidfarms/inventario/operaciones/movimientos';
    private const YA_USADA = 'Ya se usó parte de esta entrada; primero cancela o ajusta las salidas que la tomaron.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
        Sanctum::actingAs($this->crearAdmin());
    }

    private function crear(array $datos): InventoryMovement
    {
        $id = $this->postJson(self::URL, $datos, $this->headersEmpresa())->assertCreated()->json('data.id');

        return InventoryMovement::findOrFail($id);
    }

    private function aprobar(InventoryMovement $m, array $extra = []): void
    {
        $this->postJson(self::URL . "/{$m->id}/approve", $extra, $this->headersEmpresa())->assertOk();
    }

    private function entrada(float $q, float $costo, string $fecha): InventoryMovement
    {
        $m = $this->crear(['movement_type_id' => $this->tipoEntrada->id, 'destination_entity_id' => $this->almacenA->id,
            'destination_entity_type' => 'entity', 'movement_date' => $fecha,
            'details' => [['product_id' => $this->insumo->id, 'quantity' => $q, 'unit_cost' => $costo]]]);
        $this->aprobar($m);

        return $m;
    }

    private function transferencia(float $q): InventoryMovement
    {
        return $this->crear(['movement_type_id' => $this->tipoTransferencia->id, 'source_entity_id' => $this->almacenA->id, 'source_entity_type' => 'entity',
            'destination_entity_id' => $this->almacenB->id, 'destination_entity_type' => 'entity',
            'details' => [['product_id' => $this->insumo->id, 'quantity' => $q]]]);
    }

    private function merma(int $almacen, float $q): InventoryMovement
    {
        return $this->crear(['movement_type_id' => $this->tipoMerma->id, 'source_entity_id' => $almacen, 'source_entity_type' => 'entity',
            'details' => [['product_id' => $this->insumo->id, 'quantity' => $q, 'unit_cost' => 1]]]);
    }

    private function fila(int $almacen): ?InventoryStock
    {
        return InventoryStock::where('product_id', $this->insumo->id)->where('entity_id', $almacen)->first();
    }

    private function restante(int $almacen): float
    {
        return (float) CapaCosto::where('entity_id', $almacen)->sum('cantidad_restante');
    }

    public function test_salida_aprobada_se_costea_peps_ignorando_el_costo_capturado(): void
    {
        $this->entrada(8, 100, '2026-10-01');
        $this->entrada(50, 120, '2026-10-05');
        $m = $this->merma($this->almacenA->id, 10);
        $this->aprobar($m);

        $this->assertEqualsWithDelta(1040, (float) $m->details()->first()->total_cost, 0.0001);
        $this->assertEqualsWithDelta(5760, (float) $this->fila($this->almacenA->id)->total_cost, 0.0001);
    }

    public function test_transferencia_completa_lleva_las_capas_al_destino(): void
    {
        $this->entrada(8, 100, '2026-10-01');
        $this->entrada(50, 120, '2026-10-05');
        $t = $this->transferencia(10);
        $this->aprobar($t);

        $this->assertEqualsWithDelta(1040, (float) $this->fila($this->almacenB->id)->total_cost, 0.0001);
        $min = CapaCosto::where('entity_id', $this->almacenB->id)->min('fecha_entrada');
        $this->assertSame('2026-10-01', substr((string) $min, 0, 10));
    }

    public function test_transferencia_recibida_parcial_devuelve_la_diferencia_al_origen_y_el_destino_recibe_lo_mas_viejo(): void
    {
        $this->entrada(8, 100, '2026-10-01');
        $this->entrada(50, 120, '2026-10-05');
        $t = $this->transferencia(10);
        $t->update(['metadata' => array_merge($t->metadata ?? [], ['requires_external_validation' => true])]);
        $detalle = $t->details()->first();

        $this->aprobar($t, ['received_quantities' => [['detail_id' => $detalle->id, 'quantity' => 6]]]);

        // R3: la diferencia (4) regresa primero al origen tomando las últimas porciones (2×120 + 2×100);
        // al destino llegan los 6 más viejos (6×100).
        $destino = $this->fila($this->almacenB->id);
        $origen = $this->fila($this->almacenA->id);
        $this->assertEqualsWithDelta(6, (float) $destino->quantity, 0.0001);
        $this->assertEqualsWithDelta(600, (float) $destino->total_cost, 0.0001);
        $this->assertEqualsWithDelta(52, (float) $origen->quantity, 0.0001);
        $this->assertEqualsWithDelta(6200, (float) $origen->total_cost, 0.0001);

        $this->assertEqualsWithDelta(6, $this->restante($this->almacenB->id), 0.0001);
        $this->assertEqualsWithDelta(100, (float) CapaCosto::where('entity_id', $this->almacenB->id)->value('unit_cost'), 0.0001);
        // El origen conserva 2×100 (devueltas) y 50×120.
        $this->assertEqualsWithDelta(52, $this->restante($this->almacenA->id), 0.0001);
        $this->assertEqualsWithDelta(2, (float) CapaCosto::where('entity_id', $this->almacenA->id)->where('unit_cost', 100)->sum('cantidad_restante'), 0.0001);
    }

    public function test_cancelar_transferencia_recibida_parcial_ajusta_las_filas_por_lo_recibido(): void
    {
        $this->entrada(8, 100, '2026-10-01');
        $this->entrada(50, 120, '2026-10-05');
        $t = $this->transferencia(10);
        $t->update(['metadata' => array_merge($t->metadata ?? [], ['requires_external_validation' => true])]);
        $this->aprobar($t, ['received_quantities' => [['detail_id' => $t->details()->first()->id, 'quantity' => 6]]]);

        $this->postJson(self::URL . "/{$t->id}/cancel", ['reason' => 'error'], $this->headersEmpresa())->assertOk();

        $a = $this->fila($this->almacenA->id);
        $b = $this->fila($this->almacenB->id);
        $this->assertEqualsWithDelta(58, (float) $a->quantity, 0.0001);
        $this->assertEqualsWithDelta(6800, (float) $a->total_cost, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $b->quantity, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $b->total_cost, 0.0001);
        $this->assertEqualsWithDelta(58, $this->restante($this->almacenA->id), 0.0001);
        $this->assertSame(0, CapaCosto::where('entity_id', $this->almacenB->id)->count());
    }

    public function test_transferencia_antigua_sin_descuento_previo_recibida_parcial_descuenta_solo_lo_recibido(): void
    {
        $this->entrada(8, 100, '2026-10-01');
        $this->entrada(50, 120, '2026-10-05');
        $t = InventoryMovement::create([
            'document_number' => 'TRF-LEGADO-1', 'movement_type_id' => $this->tipoTransferencia->id,
            'source_entity_id' => $this->almacenA->id, 'source_entity_type' => 'entity',
            'destination_entity_id' => $this->almacenB->id, 'destination_entity_type' => 'entity',
            'movement_date' => '2026-10-06', 'status' => 'pending', 'created_by' => auth()->id(),
            'metadata' => ['requires_external_validation' => true],
        ]);
        $d = \App\Models\InventoryMovementDetail::create([
            'movement_id' => $t->id, 'product_id' => $this->insumo->id, 'quantity' => 10, 'unit_cost' => 0,
        ]);

        $this->aprobar($t, ['received_quantities' => [['detail_id' => $d->id, 'quantity' => 6]]]);

        $origen = $this->fila($this->almacenA->id);
        $destino = $this->fila($this->almacenB->id);
        $this->assertEqualsWithDelta(52, (float) $origen->quantity, 0.0001);
        $this->assertEqualsWithDelta(6200, (float) $origen->total_cost, 0.0001);
        $this->assertEqualsWithDelta(6, (float) $destino->quantity, 0.0001);
        $this->assertEqualsWithDelta(600, (float) $destino->total_cost, 0.0001);
    }

    public function test_cancelar_salida_aprobada_devuelve_porciones_y_kardex_sube(): void
    {
        $this->entrada(8, 100, '2026-10-01');
        $this->entrada(50, 120, '2026-10-05');
        $m = $this->merma($this->almacenA->id, 10);
        $this->aprobar($m);

        $this->postJson(self::URL . "/{$m->id}/cancel", ['reason' => 'error'], $this->headersEmpresa())->assertOk();

        $this->assertEqualsWithDelta(6800, (float) $this->fila($this->almacenA->id)->total_cost, 0.0001);
        $this->assertEqualsWithDelta(58, $this->restante($this->almacenA->id), 0.0001);
        $k = InventoryKardex::where('movement_id', $m->id)->latest('id')->first();
        $this->assertSame('increase', $k->transaction_type);
        $this->assertEqualsWithDelta(6800, (float) $k->balance_value, 0.0001);
        // El renglón original conserva su costo real PEPS (no se sobrescribe al cancelar).
        $this->assertEqualsWithDelta(1040, (float) $m->details()->first()->total_cost, 0.0001);
    }

    public function test_cancelar_entrada_aprobada_sin_uso_quita_capas_y_kardex_netea_cero(): void
    {
        $this->entrada(10, 90, '2026-09-20');
        $entrada = $this->entrada(5, 100, '2026-10-01');
        $this->assertEqualsWithDelta(15, (float) $this->fila($this->almacenA->id)->quantity, 0.0001);

        $this->postJson(self::URL . "/{$entrada->id}/cancel", ['reason' => 'error'], $this->headersEmpresa())->assertOk();

        $fila = $this->fila($this->almacenA->id);
        $this->assertEqualsWithDelta(10, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(900, (float) $fila->total_cost, 0.0001);
        $this->assertSame(0, CapaCosto::where('movement_id', $entrada->id)->count());
        $this->assertEqualsWithDelta(10, $this->restante($this->almacenA->id), 0.0001);

        $kardex = InventoryKardex::where('movement_id', $entrada->id)->orderBy('id')->get();
        $this->assertSame(['increase', 'decrease'], $kardex->pluck('transaction_type')->all());
        $this->assertEqualsWithDelta(
            0,
            (float) $kardex->where('transaction_type', 'increase')->sum('quantity') - (float) $kardex->where('transaction_type', 'decrease')->sum('quantity'),
            0.0001
        );
        $this->assertEqualsWithDelta(900, (float) $kardex->last()->balance_value, 0.0001);
    }

    public function test_cancelar_entrada_ya_usada_se_bloquea_y_no_cambia_nada(): void
    {
        $entrada = $this->entrada(5, 100, '2026-10-01');
        $m = $this->merma($this->almacenA->id, 1);
        $this->aprobar($m);
        $capasAntes = CapaCosto::orderBy('id')->get(['id', 'cantidad_inicial', 'cantidad_restante'])->toArray();
        $kardexAntes = InventoryKardex::count();

        $this->postJson(self::URL . "/{$entrada->id}/cancel", [], $this->headersEmpresa())
            ->assertStatus(422)
            ->assertJsonPath('errors.movement.0', self::YA_USADA);

        $this->assertEqualsWithDelta(4, (float) $this->fila($this->almacenA->id)->quantity, 0.0001);
        $this->assertEqualsWithDelta(400, (float) $this->fila($this->almacenA->id)->total_cost, 0.0001);
        $this->assertSame($capasAntes, CapaCosto::orderBy('id')->get(['id', 'cantidad_inicial', 'cantidad_restante'])->toArray());
        $this->assertSame($kardexAntes, InventoryKardex::count());
        $this->assertSame('approved', $entrada->fresh()->status);
    }

    public function test_cancelar_transferencia_aprobada_deshace_filas_capas_y_kardex_en_ambos_lados(): void
    {
        $this->entrada(8, 100, '2026-10-01');
        $this->entrada(50, 120, '2026-10-05');
        $t = $this->transferencia(10);
        $this->aprobar($t);

        $this->postJson(self::URL . "/{$t->id}/cancel", ['reason' => 'error'], $this->headersEmpresa())->assertOk();

        $a = $this->fila($this->almacenA->id);
        $b = $this->fila($this->almacenB->id);
        $this->assertEqualsWithDelta(58, (float) $a->quantity, 0.0001);
        $this->assertEqualsWithDelta(6800, (float) $a->total_cost, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $b->quantity, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $b->total_cost, 0.0001);
        $this->assertEqualsWithDelta(58, $this->restante($this->almacenA->id), 0.0001);
        $this->assertSame(0, CapaCosto::where('entity_id', $this->almacenB->id)->count());
        $this->assertEqualsWithDelta(8, (float) CapaCosto::where('entity_id', $this->almacenA->id)->where('unit_cost', 100)->sum('cantidad_restante'), 0.0001);

        $ultimoOrigen = InventoryKardex::where('movement_id', $t->id)->where('entity_id', $this->almacenA->id)->latest('id')->first();
        $ultimoDestino = InventoryKardex::where('movement_id', $t->id)->where('entity_id', $this->almacenB->id)->latest('id')->first();
        $this->assertSame('increase', $ultimoOrigen->transaction_type);
        $this->assertEqualsWithDelta(6800, (float) $ultimoOrigen->balance_value, 0.0001);
        $this->assertSame('decrease', $ultimoDestino->transaction_type);
        $this->assertEqualsWithDelta(0, (float) $ultimoDestino->balance_value, 0.0001);
    }

    public function test_cancelar_transferencia_aprobada_con_destino_usado_se_bloquea(): void
    {
        $this->entrada(10, 100, '2026-10-01');
        $t = $this->transferencia(4);
        $this->aprobar($t);
        $s = $this->merma($this->almacenB->id, 1);
        $this->aprobar($s);

        $this->postJson(self::URL . "/{$t->id}/cancel", [], $this->headersEmpresa())
            ->assertStatus(422)
            ->assertJsonPath('errors.movement.0', self::YA_USADA);
        $this->assertEqualsWithDelta(3, (float) $this->fila($this->almacenB->id)->quantity, 0.0001);
        $this->assertEqualsWithDelta(6, (float) $this->fila($this->almacenA->id)->quantity, 0.0001);
    }

    public function test_borrar_transferencia_pendiente_regresa_todo_al_origen(): void
    {
        $this->entrada(10, 100, '2026-10-01');
        $t = $this->transferencia(4);

        $this->deleteJson(self::URL . "/{$t->id}", [], $this->headersEmpresa())->assertOk();

        $this->assertEqualsWithDelta(10, (float) $this->fila($this->almacenA->id)->quantity, 0.0001);
        $this->assertEqualsWithDelta(1000, (float) $this->fila($this->almacenA->id)->total_cost, 0.0001);
        $this->assertEqualsWithDelta(10, $this->restante($this->almacenA->id), 0.0001);
    }

    public function test_cancelar_transferencia_pendiente_regresa_al_costo_original_de_cada_porcion(): void
    {
        $this->entrada(8, 100, '2026-10-01');
        $this->entrada(50, 120, '2026-10-05');
        $t = $this->transferencia(10);
        // Una compra posterior más cara no debe contaminar lo que regresa.
        $this->entrada(5, 200, '2026-10-08');

        $this->postJson(self::URL . "/{$t->id}/cancel", ['reason' => 'error'], $this->headersEmpresa())->assertOk();

        $fila = $this->fila($this->almacenA->id);
        $this->assertEqualsWithDelta(63, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(6800 + 1000, (float) $fila->total_cost, 0.0001);
        $this->assertEqualsWithDelta(8, (float) CapaCosto::where('entity_id', $this->almacenA->id)->where('unit_cost', 100)->sum('cantidad_restante'), 0.0001);
        $this->assertEqualsWithDelta(50, (float) CapaCosto::where('entity_id', $this->almacenA->id)->where('unit_cost', 120)->sum('cantidad_restante'), 0.0001);
        $k = InventoryKardex::where('movement_id', $t->id)->latest('id')->first();
        $this->assertSame('increase', $k->transaction_type);
        $this->assertEqualsWithDelta(1040, (float) $k->quantity * (float) $k->unit_cost, 0.0001);
    }
}
