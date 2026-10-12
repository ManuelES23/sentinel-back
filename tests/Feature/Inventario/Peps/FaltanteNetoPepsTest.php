<?php

namespace Tests\Feature\Inventario\Peps;

use App\Models\CapaCosto;
use App\Models\ConsumoCapa;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementDetail;
use App\Models\InventoryStock;
use App\Services\Inventory\CapasCosto;
use App\Support\Inventario\Cubeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

/**
 * Cubetas con faltante abierto (existencia negativa por Empaque o datos heredados) que coexiste
 * con capas positivas: la fila de stock siempre debe valer lo mismo que la suma NETA de las capas
 * y el motor es la única autoridad de suficiencia (la fila no se recorta a cero).
 */
class FaltanteNetoPepsTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures;

    private const URL = '/api/splendidfarms/inventario/operaciones/movimientos';

    private CapasCosto $motor;
    private Cubeta $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
        Sanctum::actingAs($this->crearAdmin());
        $this->motor = app(CapasCosto::class);
        $this->c = new Cubeta($this->insumo->id, $this->almacenA->id);
    }

    private function crear(array $datos): InventoryMovement
    {
        $id = $this->postJson(self::URL, $datos, $this->headersEmpresa())->assertCreated()->json('data.id');

        return InventoryMovement::findOrFail($id);
    }

    private function aprobar(InventoryMovement $m): void
    {
        $this->postJson(self::URL . "/{$m->id}/approve", [], $this->headersEmpresa())->assertOk();
    }

    private function entradaA(float $q, float $costo, string $fecha = '2026-10-01'): InventoryMovement
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

    private function fila(int $almacen): InventoryStock
    {
        return InventoryStock::where('product_id', $this->insumo->id)->where('entity_id', $almacen)->firstOrFail();
    }

    private function netoCapas(int $almacen): float
    {
        return (float) CapaCosto::where('entity_id', $almacen)->sum('cantidad_restante');
    }

    private function valorCapas(int $almacen): float
    {
        return (float) CapaCosto::where('entity_id', $almacen)->get()->sum(fn ($k) => (float) $k->cantidad_restante * (float) $k->unit_cost);
    }

    /** Estado completo de una cubeta, para comprobar que una operación rechazada no cambió nada. */
    private function instantanea(): array
    {
        return [
            InventoryStock::orderBy('id')->get(['id', 'quantity', 'unit_cost', 'total_cost'])->toArray(),
            CapaCosto::orderBy('id')->get(['id', 'cantidad_restante', 'unit_cost'])->toArray(),
            ConsumoCapa::count(),
        ];
    }

    private function compraA(float $q, float $costo, \DateTimeInterface $fecha): void
    {
        $r = $this->motor->entrar($this->c, $q, $costo, $fecha, CapaCosto::ORIGEN_COMPRA);
        InventoryStock::ajustarCantidad($this->c, $r->cantidad);
    }

    /** P1: una entrada menor que el faltante deja la fila negativa y igual a las capas (sin recortar a 0). */
    public function test_entrada_menor_que_el_faltante_deja_la_fila_negativa_igual_a_las_capas(): void
    {
        $this->compraA(10, 100, now()->subDays(3));
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -15, 0, null, null, null, true, 'empaque_produccion:1');
        $this->assertEqualsWithDelta(-5, (float) $this->fila($this->almacenA->id)->quantity, 0.0001);

        $r = $this->motor->entrar($this->c, 3, 120, now(), CapaCosto::ORIGEN_COMPRA);
        InventoryStock::ajustarCantidad($this->c, $r->cantidad);

        $fila = $this->fila($this->almacenA->id);
        $this->assertEqualsWithDelta(-2, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(-200, (float) $fila->total_cost, 0.0001); // -2 × 100 del faltante
        $this->assertEqualsWithDelta(-2, $this->netoCapas($this->almacenA->id), 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    /** S1: una transferencia chica sobre un destino negativo deja el destino negativo y consistente. */
    public function test_transferencia_chica_sobre_destino_negativo_no_recorta_la_fila(): void
    {
        $this->entradaA(20, 100);
        InventoryStock::updateStock($this->insumo->id, $this->almacenB->id, null, -5, 0, null, null, null, true, 'empaque_produccion:1');

        $this->aprobar($this->transferencia(3));

        $b = $this->fila($this->almacenB->id);
        $this->assertEqualsWithDelta(-2, (float) $b->quantity, 0.0001);
        $this->assertEqualsWithDelta(-2, $this->netoCapas($this->almacenB->id), 0.0001);
        // El faltante del destino no tiene historial de costo en B (0, deferido M5) + 3 transferidas a 100.
        $this->assertEqualsWithDelta(300, (float) $b->total_cost, 0.0001);
        $this->assertEqualsWithDelta(17, (float) $this->fila($this->almacenA->id)->quantity, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    /** T2: cancelar esa transferencia conserva filas = capas. */
    public function test_cancelar_transferencia_sobre_destino_negativo_mantiene_filas_y_capas_iguales(): void
    {
        $this->entradaA(20, 100);
        InventoryStock::updateStock($this->insumo->id, $this->almacenB->id, null, -5, 0, null, null, null, true, 'empaque_produccion:1');
        $t = $this->transferencia(10);
        $this->aprobar($t);
        $this->assertEqualsWithDelta(5, (float) $this->fila($this->almacenB->id)->quantity, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);

        $this->postJson(self::URL . "/{$t->id}/cancel", ['reason' => 'x'], $this->headersEmpresa())->assertOk();

        $b = $this->fila($this->almacenB->id);
        $this->assertEqualsWithDelta(-5, (float) $b->quantity, 0.0001);
        $this->assertEqualsWithDelta(-5, $this->netoCapas($this->almacenB->id), 0.0001);
        $this->assertEqualsWithDelta(0, (float) $b->total_cost, 0.0001);
        $this->assertEqualsWithDelta(20, (float) $this->fila($this->almacenA->id)->quantity, 0.0001);
        $this->assertEqualsWithDelta(2000, (float) $this->fila($this->almacenA->id)->total_cost, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    /** P2: tras revertir una aplicación con un faltante abierto, la disponibilidad es la NETA (5), no la positiva (10). */
    public function test_salida_sin_negativos_usa_la_disponibilidad_neta_con_faltante_abierto(): void
    {
        $this->compraA(10, 100, now()->subDays(3));
        $r = $this->motor->salir($this->c, 10, null, null, 'aplic');
        InventoryStock::ajustarCantidad($this->c, -$r->cantidad);
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -5, 0, null, null, null, true, 'empaque_produccion:9');
        InventoryStock::devolverPorReferencia($this->insumo->id, $this->almacenA->id, 10, 'aplic', 0);

        $fila = $this->fila($this->almacenA->id);
        $this->assertEqualsWithDelta(5, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(5, $this->netoCapas($this->almacenA->id), 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);

        $antes = $this->instantanea();
        try {
            $this->motor->salir($this->c, 8, null, null, 'x');
            $this->fail('Debió rechazar la salida de 8 con solo 5 netas.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'Stock insuficiente de Clorotalonil 720 en Almacén Campo A: hay 5, se piden 8.',
                $e->errors()['stock'][0]
            );
        }
        $this->assertSame($antes, $this->instantanea());

        // Lo que sí hay (5) se puede sacar y deja la cubeta en cero, consistente.
        $salida = $this->motor->salir($this->c, 5, null, null, 'y');
        InventoryStock::ajustarCantidad($this->c, -$salida->cantidad);
        $this->assertEqualsWithDelta(0, (float) $this->fila($this->almacenA->id)->quantity, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    /** permitirNegativo conserva su comportamiento: con faltante y capas se consume lo positivo y el resto abre más faltante. */
    public function test_salida_con_permitir_negativo_sigue_pasando_del_neto(): void
    {
        $this->compraA(10, 100, now()->subDays(3));
        $r = $this->motor->salir($this->c, 10, null, null, 'aplic');
        InventoryStock::ajustarCantidad($this->c, -$r->cantidad);
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -5, 0, null, null, null, true, 'empaque_produccion:9');
        InventoryStock::devolverPorReferencia($this->insumo->id, $this->almacenA->id, 10, 'aplic', 0);

        $fila = InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -8, 0, null, null, null, true, 'empaque_produccion:10');

        $this->assertEqualsWithDelta(-3, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(-3, $this->netoCapas($this->almacenA->id), 0.0001);
        $this->assertEqualsWithDelta(-300, (float) $fila->total_cost, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    /** P3: faltante y capas positivas a costos distintos: fila y valor coinciden con las capas. */
    public function test_faltante_y_capas_positivas_a_costos_distintos_dejan_fila_y_valor_consistentes(): void
    {
        $this->compraA(10, 100, now()->subDays(3));
        $r = $this->motor->salir($this->c, 10, null, null, 'aplic');
        InventoryStock::ajustarCantidad($this->c, -$r->cantidad);
        $this->compraA(1, 150, now()->subDay());
        // 1 unidad a 150 + faltante de 5 costeado al último costo conocido (150).
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -6, 0, null, null, null, true, 'empaque_produccion:9');
        InventoryStock::devolverPorReferencia($this->insumo->id, $this->almacenA->id, 10, 'aplic', 0);

        $fila = $this->fila($this->almacenA->id);
        $this->assertEqualsWithDelta(5, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(10 * 100 - 5 * 150, (float) $fila->total_cost, 0.0001);
        $this->assertEqualsWithDelta($this->valorCapas($this->almacenA->id), (float) $fila->total_cost, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);

        $r = $this->motor->entrar($this->c, 3, 120, now(), CapaCosto::ORIGEN_COMPRA);
        InventoryStock::ajustarCantidad($this->c, $r->cantidad);

        $fila = $this->fila($this->almacenA->id);
        $this->assertEqualsWithDelta(8, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(10 * 100 - 2 * 150, (float) $fila->total_cost, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    // ---- M7: movimientos por el controlador ----

    public function test_ajuste_positivo_y_negativo_por_el_controlador_costean_peps_y_verifican(): void
    {
        $this->entradaA(10, 100, '2026-10-01');

        $mas = $this->crear(['movement_type_id' => $this->tipoAjusteMas->id, 'destination_entity_id' => $this->almacenA->id,
            'destination_entity_type' => 'entity', 'movement_date' => '2026-10-05',
            'details' => [['product_id' => $this->insumo->id, 'quantity' => 4, 'unit_cost' => 150]]]);
        $this->aprobar($mas);

        $this->assertEqualsWithDelta(14, (float) $this->fila($this->almacenA->id)->quantity, 0.0001);
        $this->assertEqualsWithDelta(1600, (float) $this->fila($this->almacenA->id)->total_cost, 0.0001);
        $this->assertSame(CapaCosto::ORIGEN_AJUSTE, CapaCosto::where('movement_id', $mas->id)->value('origen'));

        // Ajuste negativo de 12: 10 × 100 + 2 × 150 = 1300 (PEPS), sin importar el costo capturado.
        $menos = $this->crear(['movement_type_id' => $this->tipoAjusteMenos->id, 'source_entity_id' => $this->almacenA->id,
            'source_entity_type' => 'entity',
            'details' => [['product_id' => $this->insumo->id, 'quantity' => 12, 'unit_cost' => 1]]]);
        $this->aprobar($menos);

        $this->assertEqualsWithDelta(1300, (float) $menos->details()->first()->total_cost, 0.0001);
        $fila = $this->fila($this->almacenA->id);
        $this->assertEqualsWithDelta(2, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(300, (float) $fila->total_cost, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);

        // Cancelar el ajuste negativo regresa las porciones a su costo original.
        $this->postJson(self::URL . "/{$menos->id}/cancel", ['reason' => 'x'], $this->headersEmpresa())->assertOk();
        $this->assertEqualsWithDelta(14, (float) $this->fila($this->almacenA->id)->quantity, 0.0001);
        $this->assertEqualsWithDelta(1600, (float) $this->fila($this->almacenA->id)->total_cost, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_entrada_sobre_cubeta_negativa_cubre_el_faltante_y_verifica(): void
    {
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, 4, 100);
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -9, 0, null, null, null, true, 'empaque_produccion:1');
        $this->assertEqualsWithDelta(-5, (float) $this->fila($this->almacenA->id)->quantity, 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);

        $this->entradaA(8, 120, '2026-10-06');

        $fila = $this->fila($this->almacenA->id);
        $this->assertEqualsWithDelta(3, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(360, (float) $fila->total_cost, 0.0001); // 5 cubren el faltante, 3 quedan a 120
        $this->assertEqualsWithDelta(3, $this->netoCapas($this->almacenA->id), 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_salida_sobre_cubeta_negativa_se_rechaza_al_crear_y_al_aprobar_sin_cambiar_nada(): void
    {
        InventoryStock::updateStock($this->insumo->id, $this->almacenA->id, null, -5, 0, null, null, null, true, 'empaque_produccion:1');
        $antes = $this->instantanea();

        $this->postJson(self::URL, ['movement_type_id' => $this->tipoMerma->id, 'source_entity_id' => $this->almacenA->id,
            'source_entity_type' => 'entity',
            'details' => [['product_id' => $this->insumo->id, 'quantity' => 2, 'unit_cost' => 1]]], $this->headersEmpresa())->assertStatus(422);
        $this->assertSame($antes, $this->instantanea());

        // Un pendiente heredado de antes del faltante tampoco se puede aprobar.
        $m = InventoryMovement::create([
            'document_number' => 'out-HEREDADO-1', 'movement_type_id' => $this->tipoMerma->id, 'movement_date' => '2026-10-06',
            'source_entity_id' => $this->almacenA->id, 'source_entity_type' => 'entity', 'status' => 'pending', 'created_by' => auth()->id(),
        ]);
        InventoryMovementDetail::create(['movement_id' => $m->id, 'product_id' => $this->insumo->id, 'quantity' => 2, 'unit_cost' => 1]);

        $this->postJson(self::URL . "/{$m->id}/approve", [], $this->headersEmpresa())->assertStatus(422);

        $this->assertSame($antes, $this->instantanea());
        $this->assertSame('pending', $m->fresh()->status);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }
}
