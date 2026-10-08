<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\FixedAssetReceiptUnit;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
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

    /**
     * La validación de caducidad rechaza la confirmación antes de escribir nada:
     * prueba que una confirmación rechazada no genera unidades, no el rollback
     * (eso lo cubre test_si_la_confirmacion_falla_despues_de_generar_se_revierte_todo).
     */
    public function test_una_confirmacion_rechazada_por_validacion_no_genera_unidades(): void
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

    public function test_si_la_confirmacion_falla_despues_de_generar_se_revierte_todo(): void
    {
        // Generador que sí escribe las unidades y luego truena: la transacción debe revertirlo todo.
        $generador = new class extends GeneradorUnidadesCompra {
            public int $creadas = 0;

            public function generar(PurchaseReceipt $recepcion): int
            {
                $this->creadas = parent::generar($recepcion);

                throw new \RuntimeException('Falla simulada después de generar las unidades');
            }
        };
        $this->app->instance(GeneradorUnidadesCompra::class, $generador);

        $req = $this->crearRequisicion($this->encargadoCompra, $this->almacenA, 'orden_generada');
        $oc = $this->crearOrden(['status' => 'approved', 'requisicion_campo_id' => $req->id], 10, 100);
        $id = $this->actingAs($this->encargadoCompra)->postJson(self::URL_RECEPCIONES, [
            'purchase_order_id' => $oc->id, 'receipt_date' => now()->toDateString(),
            'details' => [[
                'purchase_order_detail_id' => $oc->details->first()->id,
                'quantity_received' => 4, 'lot_number' => 'L-RB', 'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ], $this->headersEmpresa())->assertCreated()->json('data.id');
        $this->actingAs($this->encargadoCompra)->postJson(self::URL_RECEPCIONES . "/$id/submit", [], $this->headersEmpresa())->assertOk();

        $this->actingAs($this->comprasUser)->postJson(self::URL_RECEPCIONES . "/$id/confirmar", [], $this->headersEmpresa())->assertStatus(500);

        $this->assertSame(4, $generador->creadas, 'El generador debió escribir las unidades antes de fallar.');
        $this->assertSame(0, FixedAssetReceiptUnit::count());
        $this->assertEquals(0, (float) InventoryStock::where('product_id', $this->insumo->id)->sum('quantity'));
        $this->assertFalse(InventoryMovement::where('reference_type', 'purchase_receipt')->where('reference_id', $id)->exists());
        $this->assertSame(PurchaseReceipt::STATUS_PENDING, PurchaseReceipt::find($id)->status);
    }
}
