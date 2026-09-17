<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class LoteCaducidadMovimientosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    private const URL = '/api/splendidfarms/inventario/operaciones/movimientos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
        $this->insumo->update(['track_lots' => true, 'track_expiry' => true]);
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA, $this->almacenB]));
    }

    private function mover(int $tipoId, array $detalle, ?int $origen = null, ?int $destino = null)
    {
        return $this->postJson(self::URL, [
            'movement_type_id' => $tipoId,
            'source_entity_id' => $origen,
            'destination_entity_id' => $destino,
            'details' => [array_merge(['product_id' => $this->insumo->id, 'quantity' => 1], $detalle)],
        ], $this->headersEmpresa());
    }

    public function test_entrada_sin_lote_o_sin_caducidad_da_422(): void
    {
        $this->mover($this->tipoEntrada->id, [], null, $this->almacenA->id)
            ->assertStatus(422)->assertJsonValidationErrors(['details.0.lot_number'], 'errors');

        $this->mover($this->tipoEntrada->id, ['lot_number' => 'L1'], null, $this->almacenA->id)
            ->assertStatus(422)->assertJsonValidationErrors(['details.0.expiry_date'], 'errors');
    }

    public function test_entrada_completa_crea_stock_con_lote_y_caducidad_al_aprobar(): void
    {
        $fecha = now()->addMonths(6)->toDateString();
        $id = $this->mover($this->tipoEntrada->id, ['lot_number' => 'L1', 'expiry_date' => $fecha], null, $this->almacenA->id)
            ->assertCreated()->json('data.id');

        $this->postJson(self::URL . "/{$id}/approve", [], $this->headersEmpresa())->assertOk();

        $stock = InventoryStock::where('entity_id', $this->almacenA->id)->where('lot_number', 'L1')->first();
        $this->assertSame($fecha, $stock->expiry_date->toDateString());
    }

    public function test_lote_existente_con_otra_caducidad_da_422(): void
    {
        $this->darStock($this->almacenA, $this->insumo, 5, 'L1', now()->addMonth()->toDateString());

        $this->mover($this->tipoEntrada->id, ['lot_number' => 'L1', 'expiry_date' => now()->addYear()->toDateString()], null, $this->almacenA->id)
            ->assertStatus(422)->assertJsonValidationErrors(['details.0.lot_number'], 'errors');
    }

    public function test_compra_de_lote_vencido_da_422_pero_ajuste_si_se_permite(): void
    {
        $vencida = now()->subDay()->toDateString();

        $this->mover($this->tipoEntrada->id, ['lot_number' => 'LV', 'expiry_date' => $vencida], null, $this->almacenA->id)
            ->assertStatus(422);
        $this->mover($this->tipoAjusteMas->id, ['lot_number' => 'LV', 'expiry_date' => $vencida], null, $this->almacenA->id)
            ->assertCreated();
    }

    public function test_salida_sin_lote_o_de_lote_inexistente_da_422(): void
    {
        $this->darStock($this->almacenA, $this->insumo, 5, 'L1', now()->addMonth()->toDateString());

        $this->mover($this->tipoSalida->id, [], $this->almacenA->id)->assertStatus(422);
        $this->mover($this->tipoSalida->id, ['lot_number' => 'NOPE'], $this->almacenA->id)->assertStatus(422);
        $this->mover($this->tipoSalida->id, ['lot_number' => 'L1'], $this->almacenA->id)->assertCreated();
    }

    public function test_salida_de_lote_vencido_da_422_pero_merma_pasa(): void
    {
        $this->darStock($this->almacenA, $this->insumo, 5, 'LV', now()->subDay()->toDateString());

        $this->mover($this->tipoSalida->id, ['lot_number' => 'LV'], $this->almacenA->id)
            ->assertStatus(422)->assertJsonFragment(['status' => 'error']);
        $this->mover($this->tipoMerma->id, ['lot_number' => 'LV'], $this->almacenA->id)->assertCreated();
    }

    public function test_aprobar_salida_de_lote_que_vencio_despues_de_capturarla_da_422(): void
    {
        $stock = $this->darStock($this->almacenA, $this->insumo, 5, 'L1', now()->addDay()->toDateString());
        $id = $this->mover($this->tipoSalida->id, ['lot_number' => 'L1'], $this->almacenA->id)->assertCreated()->json('data.id');

        $stock->update(['expiry_date' => now()->subDay()->toDateString()]);

        $this->postJson(self::URL . "/{$id}/approve", [], $this->headersEmpresa())->assertStatus(422);
        $this->assertSame('pending', InventoryMovement::find($id)->status);
    }

    public function test_transferencia_conserva_lote_y_caducidad_en_destino(): void
    {
        $fecha = now()->addMonths(3)->toDateString();
        $this->darStock($this->almacenA, $this->insumo, 5, 'L1', $fecha);

        $id = $this->mover($this->tipoTransferencia->id, ['lot_number' => 'L1'], $this->almacenA->id, $this->almacenB->id)
            ->assertCreated()->json('data.id');
        $this->postJson(self::URL . "/{$id}/approve", [], $this->headersEmpresa())->assertOk();

        $destino = InventoryStock::where('entity_id', $this->almacenB->id)->where('lot_number', 'L1')->first();
        $this->assertNotNull($destino);
        $this->assertSame($fecha, $destino->expiry_date->toDateString());
        $this->assertEquals(1, $destino->quantity);
    }

    public function test_articulo_sin_control_de_lotes_no_exige_nada(): void
    {
        $this->insumo->update(['track_lots' => false, 'track_expiry' => false]);

        $this->mover($this->tipoEntrada->id, [], null, $this->almacenA->id)->assertCreated();
    }

    public function test_transferencia_a_lote_existente_con_otra_caducidad_da_422_y_no_pisa_destino(): void
    {
        $fechaDestino = now()->addMonths(2)->toDateString();
        $fechaOrigen = now()->addMonths(5)->toDateString();
        $this->darStock($this->almacenB, $this->insumo, 3, 'L1', $fechaDestino);
        $this->darStock($this->almacenA, $this->insumo, 5, 'L1', $fechaOrigen);

        $this->mover($this->tipoTransferencia->id, ['lot_number' => 'L1'], $this->almacenA->id, $this->almacenB->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.lot_number'], 'errors');

        $destino = InventoryStock::where('entity_id', $this->almacenB->id)->where('lot_number', 'L1')->first();
        $this->assertSame($fechaDestino, $destino->expiry_date->toDateString());
        $this->assertEquals(3, $destino->quantity);
    }

    public function test_ajuste_negativo_puede_tomar_lote_vencido(): void
    {
        $this->darStock($this->almacenA, $this->insumo, 5, 'LV', now()->subDay()->toDateString());

        $this->mover($this->tipoAjusteMenos->id, ['lot_number' => 'LV'], $this->almacenA->id)->assertCreated();
    }

    public function test_lote_que_vence_hoy_no_se_considera_vencido_en_salida(): void
    {
        $this->darStock($this->almacenA, $this->insumo, 5, 'HOY', now()->toDateString());

        $this->mover($this->tipoSalida->id, ['lot_number' => 'HOY'], $this->almacenA->id)->assertCreated();
    }
}
