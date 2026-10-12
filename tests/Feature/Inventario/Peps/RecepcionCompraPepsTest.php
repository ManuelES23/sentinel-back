<?php

namespace Tests\Feature\Inventario\Peps;

use App\Models\CapaCosto;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Services\Compras\ConfirmadorRecepcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class RecepcionCompraPepsTest extends TestCase
{
    use RefreshDatabase, CreatesComprasFixtures;

    private const URL = '/api/splendidfarms/administration/compras/recepciones';

    /** Recepción capturada por un encargado del almacén y enviada (estado por confirmar). */
    private function crearRecepcionEnviada(PurchaseOrder $oc, float $cantidad, string $lote, string $caducidad): PurchaseReceipt
    {
        $encargado = $this->crearUsuarioDeCampo([$this->almacenA]);
        $id = $this->actingAs($encargado)->postJson(self::URL, [
            'purchase_order_id' => $oc->id,
            'receipt_date' => now()->toDateString(),
            'details' => [[
                'purchase_order_detail_id' => $oc->details->first()->id,
                'quantity_received' => $cantidad, 'lot_number' => $lote, 'expiry_date' => $caducidad,
            ]],
        ], $this->headersEmpresa())->assertCreated()->json('data.id');
        $this->actingAs($encargado)->postJson(self::URL . "/$id/submit", [], $this->headersEmpresa())->assertOk();

        return PurchaseReceipt::findOrFail($id);
    }

    public function test_confirmar_recepcion_crea_capa_de_compra_con_su_costo_y_fecha(): void
    {
        $this->setUpComprasFixtures();
        $compras = $this->crearUsuarioDeCampo();
        $this->otorgarConfirmar($compras);
        $oc = $this->crearOrden(['status' => 'approved'], 10, 100);
        $rec = $this->crearRecepcionEnviada($oc, 10, 'L-PEPS', '2027-06-30');

        app(ConfirmadorRecepcion::class)->confirmar($rec, $compras);

        $capa = CapaCosto::where('lot_number', 'L-PEPS')->firstOrFail();
        $this->assertSame(CapaCosto::ORIGEN_COMPRA, $capa->origen);
        $this->assertEqualsWithDelta(100, (float) $capa->unit_cost, 0.0001);
        $this->assertSame(PurchaseReceipt::find($rec->id)->receipt_date->toDateString(), $capa->fecha_entrada->toDateString());
    }
}
