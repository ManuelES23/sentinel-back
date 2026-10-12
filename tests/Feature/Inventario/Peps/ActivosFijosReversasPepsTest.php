<?php

namespace Tests\Feature\Inventario\Peps;

use App\Models\CapaCosto;
use App\Models\ConsumoCapa;
use App\Models\FixedAssetReceiptUnit;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementDetail;
use App\Models\InventoryStock;
use App\Services\Inventory\AplicadorStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAltaCompraFixtures;
use Tests\TestCase;

/**
 * La protección de activos fijos (el stock no baja de las unidades vigentes que respalda una compra)
 * también aplica a las reversas que quitan capas: cancelar una entrada y deshacer una transferencia.
 */
class ActivosFijosReversasPepsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAltaCompraFixtures;

    private const URL_MOVIMIENTOS = '/api/splendidfarms/inventario/operaciones/movimientos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAltaCompraFixtures();
    }

    private function stockDe(int $almacen): float
    {
        return (float) InventoryStock::where('product_id', $this->insumo->id)->where('entity_id', $almacen)
            ->where('lot_number', 'L-100')->value('quantity');
    }

    private function instantanea(): array
    {
        return [
            InventoryStock::orderBy('id')->get(['id', 'quantity', 'unit_cost', 'total_cost'])->toArray(),
            CapaCosto::orderBy('id')->get(['id', 'cantidad_restante', 'unit_cost'])->toArray(),
            ConsumoCapa::count(),
        ];
    }

    /** Movimiento aprobado de entrada o ajuste con una línea de L-100 aplicada con el aplicador (como el controlador). */
    private function movimientoAprobado(int $tipoId, array $campos, float $cantidad, float $costo): array
    {
        $m = InventoryMovement::create($campos + [
            'document_number' => 'TEST-' . uniqid(), 'movement_type_id' => $tipoId, 'movement_date' => now()->toDateString(),
            'status' => 'approved', 'created_by' => $this->crearAdmin()->id,
        ]);
        $d = InventoryMovementDetail::create([
            'movement_id' => $m->id, 'product_id' => $this->insumo->id, 'quantity' => $cantidad, 'unit_cost' => $costo,
            'lot_number' => 'L-100', 'expiry_date' => now()->addYear()->toDateString(),
        ]);

        return [$m, $d];
    }

    public function test_cancelar_una_entrada_manual_que_dejaria_el_stock_bajo_las_unidades_vigentes_da_422_y_no_cambia_nada(): void
    {
        $this->recepcionConfirmada(10, 'L-100'); // 10 en stock respaldando 10 unidades vigentes
        [$entrada, $detalle] = $this->movimientoAprobado($this->tipoEntrada->id, [
            'destination_entity_id' => $this->almacenA->id, 'destination_entity_type' => 'entity',
        ], 5, 100);
        app(AplicadorStock::class)->aumentar($detalle, $this->almacenA->id, 'entity', $entrada);
        // Salen 3 (PEPS: de la compra); quedan 12, de las que 10 respaldan activos.
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -3, 0, 'L-100');
        $this->assertEqualsWithDelta(12, $this->stockDe($this->almacenA->id), 0.0001);
        $antes = $this->instantanea();
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $this->postJson(self::URL_MOVIMIENTOS . "/{$entrada->id}/cancel", ['reason' => 'x'], $this->headersEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors('stock');

        $this->assertSame($antes, $this->instantanea());
        $this->assertSame('approved', $entrada->fresh()->status);
        $this->assertEqualsWithDelta(12, $this->stockDe($this->almacenA->id), 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_cancelar_una_entrada_manual_procede_si_el_stock_restante_cubre_las_unidades_vigentes(): void
    {
        $this->recepcionConfirmada(10, 'L-100');
        [$entrada, $detalle] = $this->movimientoAprobado($this->tipoEntrada->id, [
            'destination_entity_id' => $this->almacenA->id, 'destination_entity_type' => 'entity',
        ], 5, 100);
        app(AplicadorStock::class)->aumentar($detalle, $this->almacenA->id, 'entity', $entrada);
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $this->postJson(self::URL_MOVIMIENTOS . "/{$entrada->id}/cancel", ['reason' => 'x'], $this->headersEmpresa())->assertOk();

        $this->assertEqualsWithDelta(10, $this->stockDe($this->almacenA->id), 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_deshacer_una_transferencia_que_dejaria_el_destino_bajo_las_unidades_vigentes_da_422_y_no_cambia_nada(): void
    {
        $this->recepcionConfirmada(10, 'L-100'); // A: 10 respaldando 10 unidades vigentes
        // B envía 6 a A (aprobada): A queda con 16.
        InventoryStock::updateStock($this->insumo->id, $this->almacenB->id, null, 6, 90, 'L-100');
        [$t, $d] = $this->movimientoAprobado($this->tipoTransferencia->id, [
            'source_entity_id' => $this->almacenB->id, 'source_entity_type' => 'entity',
            'destination_entity_id' => $this->almacenA->id, 'destination_entity_type' => 'entity',
        ], 6, 0);
        $aplicador = app(AplicadorStock::class);
        $aplicador->disminuir($d, $this->almacenB->id, 'entity', $t);
        $aplicador->devolver($d, $this->almacenB->id, 'entity', $t, 6, $this->almacenA->id, 'entity');
        // Salen 6 de A: quedan 10 (todas respaldan activos), de las cuales 6 llegaron por la transferencia.
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -6, 0, 'L-100');
        $this->assertEqualsWithDelta(10, $this->stockDe($this->almacenA->id), 0.0001);
        $antes = $this->instantanea();

        try {
            $aplicador->deshacerTransferencia($d, $this->almacenB->id, 'entity', $this->almacenA->id, 'entity', $t);
            $this->fail('Debió rechazar: el destino quedaría por debajo de las unidades vigentes.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        $this->assertSame($antes, $this->instantanea());
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }
}
