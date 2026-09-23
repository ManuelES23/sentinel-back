<?php

namespace Tests\Feature\Compras;

use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptDetail;
use App\Models\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class RecepcionesCapturaTest extends TestCase
{
    use RefreshDatabase, CreatesComprasFixtures;

    private const URL = '/api/splendidfarms/administration/compras/recepciones';

    private $encargado;
    private $oc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpComprasFixtures();
        $this->encargado = $this->crearUsuarioDeCampo([$this->almacenA]);
        $this->oc = $this->crearOrden(['status' => 'approved'], 10, 100);
    }

    private function payload(array $linea = []): array
    {
        return [
            'purchase_order_id' => $this->oc->id,
            'receipt_date' => now()->toDateString(),
            'details' => [array_merge([
                'purchase_order_detail_id' => $this->oc->details->first()->id,
                'quantity_received' => 6,
                'lot_number' => 'L-100',
                'expiry_date' => now()->addYear()->toDateString(),
            ], $linea)],
        ];
    }

    public function test_encargado_captura_contra_oc_aprobada(): void
    {
        $this->actingAs($this->encargado)->postJson(self::URL, $this->payload(), $this->headersEmpresa())->assertCreated();

        $rec = PurchaseReceipt::with('details')->first();
        $this->assertSame('draft', $rec->status);
        $this->assertSame($this->almacenA->id, $rec->almacen_id);
        $this->assertSame($this->empresa->id, $rec->enterprise_id);
        $this->assertSame($this->encargado->id, $rec->capturada_por);
        $this->assertSame($this->proveedor->id, $rec->supplier_id);
        $this->assertEquals(100, (float) $rec->details->first()->unit_cost);
    }

    public function test_reglas_de_captura(): void
    {
        $borrador = $this->crearOrden(['status' => 'draft']);
        $this->actingAs($this->encargado)->postJson(self::URL, array_merge($this->payload(), [
            'purchase_order_id' => $borrador->id,
            'details' => [['purchase_order_detail_id' => $borrador->details->first()->id, 'quantity_received' => 1, 'lot_number' => 'L', 'expiry_date' => now()->addYear()->toDateString()]],
        ]), $this->headersEmpresa())->assertStatus(422);

        $ajena = $this->crearOrden(['status' => 'approved', 'almacen_destino_id' => $this->almacenB->id]);
        $this->actingAs($this->encargado)->postJson(self::URL, array_merge($this->payload(), [
            'purchase_order_id' => $ajena->id,
            'details' => [['purchase_order_detail_id' => $ajena->details->first()->id, 'quantity_received' => 1]],
        ]), $this->headersEmpresa())->assertForbidden();

        $this->actingAs($this->encargado)->postJson(self::URL, $this->payload(['quantity_received' => 11]), $this->headersEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors(['details.0.quantity_accepted']);
        $this->actingAs($this->encargado)->postJson(self::URL, $this->payload(['lot_number' => null]), $this->headersEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors(['details.0.lot_number']);
        $this->actingAs($this->encargado)->postJson(self::URL, $this->payload(['expiry_date' => now()->subDay()->toDateString()]), $this->headersEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors(['details.0.expiry_date']);

        // Lo ya capturado en otra recepción abierta cuenta como comprometido
        $this->actingAs($this->encargado)->postJson(self::URL, $this->payload(['quantity_received' => 6]), $this->headersEmpresa())->assertCreated();
        $this->actingAs($this->encargado)->postJson(self::URL, $this->payload(['quantity_received' => 5]), $this->headersEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors(['details.0.quantity_accepted']);
    }

    public function test_quantity_accepted_cero_explicito_no_dispara_fallback_del_modelo(): void
    {
        // Solo 2 unidades disponibles en la OC.
        $oc = $this->crearOrden(['status' => 'approved'], 2, 100);

        $this->actingAs($this->encargado)->postJson(self::URL, [
            'purchase_order_id' => $oc->id,
            'receipt_date' => now()->toDateString(),
            'details' => [[
                'purchase_order_detail_id' => $oc->details->first()->id,
                'quantity_received' => 5,
                'quantity_accepted' => 0,
                'lot_number' => 'L-100',
                'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ], $this->headersEmpresa())->assertCreated();

        $detalle = PurchaseReceiptDetail::first();
        $this->assertEquals(0, (float) $detalle->quantity_accepted);
        $this->assertEquals(5, (float) $detalle->quantity_received);
    }

    public function test_dos_lineas_del_mismo_renglon_en_el_mismo_envio_exceden_lo_disponible(): void
    {
        $renglonId = $this->oc->details->first()->id;

        $this->actingAs($this->encargado)->postJson(self::URL, [
            'purchase_order_id' => $this->oc->id,
            'receipt_date' => now()->toDateString(),
            'details' => [
                ['purchase_order_detail_id' => $renglonId, 'quantity_received' => 6, 'lot_number' => 'L-100', 'expiry_date' => now()->addYear()->toDateString()],
                ['purchase_order_detail_id' => $renglonId, 'quantity_received' => 6, 'lot_number' => 'L-200', 'expiry_date' => now()->addYear()->toDateString()],
            ],
        ], $this->headersEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors(['details.1.quantity_accepted']);
    }

    public function test_enviar_a_confirmar_bandeja_y_regresar(): void
    {
        $compras = $this->crearUsuarioDeCampo();
        $this->otorgarConfirmar($compras);
        $this->otorgarVerTodos($compras);
        $id = $this->actingAs($this->encargado)->postJson(self::URL, $this->payload(), $this->headersEmpresa())->json('data.id');

        $this->actingAs($this->encargado)->postJson(self::URL . "/$id/submit", [], $this->headersEmpresa())->assertOk();
        $this->assertSame('pending', PurchaseReceipt::find($id)->status);
        $this->assertSame(1, SystemNotification::where('user_id', $compras->id)->count());

        $this->actingAs($this->encargado)->getJson(self::URL . '?bandeja=por_confirmar', $this->headersEmpresa())->assertForbidden();
        $this->assertCount(1, $this->actingAs($compras)->getJson(self::URL . '?bandeja=por_confirmar', $this->headersEmpresa())->json('data.data'));

        $this->actingAs($this->encargado)->postJson(self::URL . "/$id/regresar", ['motivo' => 'x'], $this->headersEmpresa())->assertForbidden();
        $this->actingAs($compras)->postJson(self::URL . "/$id/regresar", ['motivo' => 'Lote ilegible'], $this->headersEmpresa())->assertOk();
        $rec = PurchaseReceipt::find($id);
        $this->assertSame('draft', $rec->status);
        $this->assertSame('Lote ilegible', $rec->motivo_rechazo);
        $this->assertSame(1, SystemNotification::where('user_id', $this->encargado->id)->count());
    }

    /**
     * Regresión: una recepción `pending` es territorio de Compras (confirmar
     * o regresar) — el encargado de almacén no debe poder cancelarla. El
     * controller ya devolvía 422 (status !== draft), pero is_editable seguía
     * reportando true para pending, lo cual es inconsistente y es lo que
     * consume el frontend para mostrar/ocultar el botón Cancelar.
     */
    public function test_pending_no_es_editable_y_cancel_falla(): void
    {
        $id = $this->actingAs($this->encargado)->postJson(self::URL, $this->payload(), $this->headersEmpresa())->json('data.id');
        $this->actingAs($this->encargado)->postJson(self::URL . "/$id/submit", [], $this->headersEmpresa())->assertOk();

        $rec = PurchaseReceipt::find($id);
        $this->assertSame('pending', $rec->status);
        $this->assertFalse($rec->is_editable);
        $this->assertFalse($rec->cancel($this->encargado->id, 'Ya no se necesita'));
        $this->assertSame('pending', $rec->fresh()->status);

        // El controller mantiene su propio 422 explícito para pending.
        $this->actingAs($this->encargado)->postJson(self::URL . "/$id/cancel", ['reason' => 'Ya no se necesita'], $this->headersEmpresa())
            ->assertStatus(422);
    }

    public function test_ordenes_recibibles_y_desde_orden(): void
    {
        $this->crearOrden(['status' => 'approved', 'almacen_destino_id' => $this->almacenB->id]);

        $ids = collect($this->actingAs($this->encargado)->getJson(self::URL . '/ordenes-recibibles', $this->headersEmpresa())->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$this->oc->id], $ids);

        $this->actingAs($this->encargado)->postJson(self::URL . "/from-order/{$this->oc->id}", [], $this->headersEmpresa())->assertCreated();
        $this->assertEquals(10, (float) PurchaseReceipt::first()->details()->first()->quantity_received);
    }
}
