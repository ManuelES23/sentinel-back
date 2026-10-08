<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\FixedAssetReceiptUnit;
use App\Models\PurchaseReceipt;
use App\Services\ActivosFijos\GeneradorUnidadesCompra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAltaCompraFixtures;
use Tests\TestCase;

class AltaCompraGeneracionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAltaCompraFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAltaCompraFixtures();
    }

    public function test_producto_marcado_genera_una_unidad_pendiente_por_cada_aceptada(): void
    {
        $rec = $this->recepcionConfirmada(6, 'L-100');

        $unidades = FixedAssetReceiptUnit::where('purchase_receipt_id', $rec->id)->orderBy('unit_number')->get();

        $this->assertCount(6, $unidades);
        $this->assertSame([1, 2, 3, 4, 5, 6], $unidades->pluck('unit_number')->all());
        $primera = $unidades->first();
        $this->assertSame('pending', $primera->status);
        $this->assertSame($this->empresa->id, $primera->enterprise_id);
        $this->assertSame($this->insumo->id, $primera->product_id);
        $this->assertSame('L-100', $primera->lot_number);
        $this->assertEquals(100, (float) $primera->unit_cost);
        $this->assertNull($primera->fixed_asset_id);
    }

    public function test_producto_sin_marca_no_genera_unidades(): void
    {
        $this->insumo->update(['is_fixed_asset' => false]);

        $this->recepcionConfirmada(6);

        $this->assertSame(0, FixedAssetReceiptUnit::count());
    }

    public function test_una_cantidad_fraccionaria_se_trunca(): void
    {
        $rec = $this->recepcionConfirmada(2.5);

        $this->assertSame(2, FixedAssetReceiptUnit::where('purchase_receipt_id', $rec->id)->count());
    }

    public function test_solo_cuentan_las_unidades_aceptadas(): void
    {
        $req = $this->crearRequisicion($this->encargadoCompra, $this->almacenA, 'orden_generada');
        $oc = $this->crearOrden(['status' => 'approved', 'requisicion_campo_id' => $req->id], 10, 100);
        $id = $this->actingAs($this->encargadoCompra)->postJson(self::URL_RECEPCIONES, [
            'purchase_order_id' => $oc->id, 'receipt_date' => now()->toDateString(),
            'details' => [[
                'purchase_order_detail_id' => $oc->details->first()->id,
                'quantity_received' => 6, 'lot_number' => 'L-ACE', 'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ], $this->headersEmpresa())->assertCreated()->json('data.id');
        $this->actingAs($this->encargadoCompra)->postJson(self::URL_RECEPCIONES . "/$id/submit", [], $this->headersEmpresa())->assertOk();

        // Compras acepta solo 2 de las 6 recibidas.
        PurchaseReceipt::find($id)->details()->update(['quantity_accepted' => 2, 'quantity_rejected' => 4]);
        $this->actingAs($this->comprasUser)->postJson(self::URL_RECEPCIONES . "/$id/confirmar", [], $this->headersEmpresa())->assertOk();

        $this->assertSame(2, FixedAssetReceiptUnit::where('purchase_receipt_id', $id)->count());
    }

    public function test_generar_dos_veces_no_duplica(): void
    {
        $rec = $this->recepcionConfirmada(3);

        $this->assertSame(0, app(GeneradorUnidadesCompra::class)->generar($rec->fresh()));
        $this->assertSame(3, FixedAssetReceiptUnit::where('purchase_receipt_id', $rec->id)->count());
    }

    public function test_si_la_confirmacion_falla_no_queda_ninguna_unidad(): void
    {
        $req = $this->crearRequisicion($this->encargadoCompra, $this->almacenA, 'orden_generada');
        $oc = $this->crearOrden(['status' => 'approved', 'requisicion_campo_id' => $req->id], 10, 100);
        $id = $this->actingAs($this->encargadoCompra)->postJson(self::URL_RECEPCIONES, [
            'purchase_order_id' => $oc->id, 'receipt_date' => now()->toDateString(),
            'details' => [[
                'purchase_order_detail_id' => $oc->details->first()->id,
                'quantity_received' => 4, 'lot_number' => 'L-X', 'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ], $this->headersEmpresa())->assertCreated()->json('data.id');
        $this->actingAs($this->encargadoCompra)->postJson(self::URL_RECEPCIONES . "/$id/submit", [], $this->headersEmpresa())->assertOk();
        // El lote se venció entre la captura y la confirmación (mismo truco que ConfirmarRecepcionTest).
        PurchaseReceipt::find($id)->details()->update(['expiry_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->comprasUser)->postJson(self::URL_RECEPCIONES . "/$id/confirmar", [], $this->headersEmpresa())->assertStatus(422);

        $this->assertSame(0, FixedAssetReceiptUnit::count());
    }
}
