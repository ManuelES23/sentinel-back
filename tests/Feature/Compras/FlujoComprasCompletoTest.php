<?php

namespace Tests\Feature\Compras;

use App\Models\AccountPayable;
use App\Models\InventoryStock;
use App\Models\PurchaseOrder;
use App\Models\RequisicionCampo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class FlujoComprasCompletoTest extends TestCase
{
    use RefreshDatabase, CreatesComprasFixtures;

    public function test_requisicion_a_entrada_en_almacen_de_campo(): void
    {
        $this->setUpComprasFixtures();
        $h = $this->headersEmpresa();
        $req = '/api/splendidfarms/operacion-agricola/agricola/requisiciones';
        $oc = '/api/splendidfarms/inventario/compras/ordenes';
        $rec = '/api/splendidfarms/inventario/compras/recepciones';

        $ingeniero = $this->crearUsuarioDeCampo([$this->almacenA]);
        $compras = $this->crearUsuarioDeCampo();
        $this->otorgarCotizar($compras);
        $this->otorgarConfirmar($compras);
        $this->otorgarVerTodos($compras);
        $gerente = $this->crearAprobador('enterprise');

        // 1. El ingeniero pide y envía
        $reqId = $this->actingAs($ingeniero)->postJson($req, [
            'temporada_id' => $this->temporada->id, 'almacen_id' => $this->almacenA->id,
            'fecha_solicitud' => now()->toDateString(), 'prioridad' => 'alta',
            'detalles' => [['product_id' => $this->insumo->id, 'cantidad' => 10]],
        ], $h)->assertCreated()->json('data.id');
        $this->actingAs($ingeniero)->postJson("$req/$reqId/enviar", [], $h)->assertOk();
        $detalleId = RequisicionCampo::find($reqId)->detalles()->value('id');

        // 2. Compras cotiza con dos proveedores y elige la más barata
        $cotizar = fn ($prov, $precio) => $this->actingAs($compras)->postJson("$req/$reqId/cotizaciones", [
            'supplier_id' => $prov, 'fecha' => now()->toDateString(), 'dias_entrega' => 3,
            'detalles' => [['requisicion_detalle_id' => $detalleId, 'disponible' => true, 'cantidad' => 10, 'precio_unitario' => $precio, 'tax_rate' => 16]],
        ], $h)->assertCreated()->json('data.id');
        $cotizar($this->proveedor->id, 120);
        $barata = $cotizar($this->proveedor2->id, 100);
        $this->actingAs($compras)->postJson("$req/$reqId/cotizaciones/$barata/ganadora", [], $h)->assertOk();

        // 3. Genera la OC y la manda a autorizar; el gerente aprueba
        $ocId = $this->actingAs($compras)->postJson("$req/$reqId/generar-orden", ['order_date' => now()->toDateString()], $h)
            ->assertOk()->json('data.purchase_order.id');
        $this->actingAs($compras)->postJson("$oc/$ocId/submit", [], $h)->assertOk();
        $this->actingAs($gerente)->postJson("$oc/$ocId/approve", [], $h)->assertOk();

        // El ingeniero ve su OC aprobada
        $this->actingAs($ingeniero)->getJson("$oc/$ocId", $h)->assertOk()->assertJsonPath('data.status', 'approved');

        // 4. El encargado recibe parcial y Compras confirma; luego el resto
        $lineaId = PurchaseOrder::find($ocId)->details()->value('id');
        foreach ([[6, 'L-1'], [4, 'L-2']] as [$cantidad, $lote]) {
            $recId = $this->actingAs($ingeniero)->postJson($rec, [
                'purchase_order_id' => $ocId, 'receipt_date' => now()->toDateString(),
                'details' => [['purchase_order_detail_id' => $lineaId, 'quantity_received' => $cantidad, 'lot_number' => $lote, 'expiry_date' => now()->addYear()->toDateString()]],
            ], $h)->assertCreated()->json('data.id');
            $this->actingAs($ingeniero)->postJson("$rec/$recId/submit", [], $h)->assertOk();
            $this->actingAs($compras)->postJson("$rec/$recId/confirmar", [], $h)->assertOk();
        }

        $this->assertEquals(10, (float) InventoryStock::where('entity_id', $this->almacenA->id)->sum('quantity'));
        $this->assertSame(2, InventoryStock::where('entity_id', $this->almacenA->id)->count());
        $this->assertSame('completed', PurchaseOrder::find($ocId)->status);
        $this->assertSame('completada', RequisicionCampo::find($reqId)->status);
        $this->assertSame(2, AccountPayable::where('purchase_order_id', $ocId)->count());
        $montos = AccountPayable::where('purchase_order_id', $ocId)
            ->pluck('total_amount')
            ->map(fn ($v) => round((float) $v, 2))
            ->sort()
            ->values()
            ->all();
        $this->assertEquals([464.0, 696.0], $montos);
        $this->assertEquals(100, (float) PurchaseOrder::find($ocId)->details()->value('unit_price'));

        $tipos = collect($this->actingAs($ingeniero)->getJson("$req/$reqId/seguimiento", $h)->assertOk()->json('data.eventos'))->pluck('tipo');
        $this->assertTrue($tipos->contains('aprobada'));
        $this->assertSame(2, $tipos->filter(fn ($t) => $t === 'recepcion')->count());
    }
}
