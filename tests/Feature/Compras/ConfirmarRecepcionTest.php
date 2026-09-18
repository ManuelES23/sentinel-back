<?php

namespace Tests\Feature\Compras;

use App\Models\AccountPayable;
use App\Models\InventoryKardex;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\PurchaseReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class ConfirmarRecepcionTest extends TestCase
{
    use RefreshDatabase, CreatesComprasFixtures;

    private const URL = '/api/splendidfarms/inventario/compras/recepciones';

    private $encargado;
    private $compras;
    private $oc;
    private $req;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpComprasFixtures();
        $this->encargado = $this->crearUsuarioDeCampo([$this->almacenA]);
        $this->compras = $this->crearUsuarioDeCampo();
        $this->otorgarConfirmar($this->compras);
        $this->otorgarVerTodos($this->compras);
        $this->req = $this->crearRequisicion($this->encargado, $this->almacenA, 'orden_generada');
        $this->oc = $this->crearOrden(['status' => 'approved', 'requisicion_campo_id' => $this->req->id], 10, 100);
    }

    private function capturarYEnviar(float $cantidad, string $lote = 'L-100'): int
    {
        $id = $this->actingAs($this->encargado)->postJson(self::URL, [
            'purchase_order_id' => $this->oc->id,
            'receipt_date' => now()->toDateString(),
            'details' => [[
                'purchase_order_detail_id' => $this->oc->details->first()->id,
                'quantity_received' => $cantidad, 'lot_number' => $lote, 'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ], $this->headersEmpresa())->assertCreated()->json('data.id');
        $this->actingAs($this->encargado)->postJson(self::URL . "/$id/submit", [], $this->headersEmpresa())->assertOk();

        return $id;
    }

    public function test_confirmar_suma_stock_y_actualiza_todo(): void
    {
        $id = $this->capturarYEnviar(6);

        $this->actingAs($this->encargado)->postJson(self::URL . "/$id/confirmar", [], $this->headersEmpresa())->assertForbidden();
        $this->actingAs($this->compras)->postJson(self::URL . "/$id/confirmar", [], $this->headersEmpresa())->assertOk();

        $rec = PurchaseReceipt::find($id);
        $this->assertSame('completed', $rec->status);
        $this->assertSame($this->compras->id, $rec->confirmada_por);

        $mov = InventoryMovement::find($rec->inventory_movement_id);
        $this->assertNotEmpty($mov->document_number);
        $this->assertSame($this->almacenA->id, $mov->destination_entity_id);
        $this->assertSame('approved', $mov->status);
        $this->assertSame('COMPRA', $mov->movementType->code);

        $stock = InventoryStock::where('entity_id', $this->almacenA->id)->where('lot_number', 'L-100')->first();
        $this->assertEquals(6, (float) $stock->quantity);
        $this->assertSame(1, InventoryKardex::where('movement_id', $mov->id)->count());

        $this->assertSame('partial', $this->oc->fresh()->status);
        $this->assertEquals(6, (float) $this->oc->details()->first()->quantity_received);

        $cxp = AccountPayable::where('purchase_receipt_id', $id)->first();
        $this->assertSame(15, $cxp->payment_terms_days);
        $this->assertEquals(696, (float) $cxp->total_amount);

        $this->actingAs($this->compras)->postJson(self::URL . "/$id/confirmar", [], $this->headersEmpresa())->assertStatus(409);
    }

    public function test_segunda_entrada_completa_oc_y_requisicion(): void
    {
        // Se capturan primero los ids (y no en línea, dentro del argumento de la
        // llamada a confirmar): capturarYEnviar hace su propio actingAs($encargado)
        // internamente, y evaluar esa llamada dentro del argumento de postJson()
        // pisaría el actingAs($this->compras) recién puesto antes de que la
        // petición realmente salga.
        $id1 = $this->capturarYEnviar(6);
        $this->actingAs($this->compras)->postJson(self::URL . "/$id1/confirmar", [], $this->headersEmpresa())->assertOk();

        $id2 = $this->capturarYEnviar(4, 'L-200');
        $this->actingAs($this->compras)->postJson(self::URL . "/$id2/confirmar", [], $this->headersEmpresa())->assertOk();

        $this->assertSame('completed', $this->oc->fresh()->status);
        $this->assertSame('completada', $this->req->fresh()->status);
    }

    public function test_si_algo_falla_no_se_aplica_nada(): void
    {
        $id = $this->capturarYEnviar(6);
        // El lote se venció entre la captura y la confirmación
        PurchaseReceipt::find($id)->details()->update(['expiry_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->compras)->postJson(self::URL . "/$id/confirmar", [], $this->headersEmpresa())->assertStatus(422);

        $this->assertSame('pending', PurchaseReceipt::find($id)->status);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, InventoryStock::count());
        $this->assertEquals(0, (float) $this->oc->details()->first()->quantity_received);
        $this->assertSame(0, AccountPayable::count());
    }
}
