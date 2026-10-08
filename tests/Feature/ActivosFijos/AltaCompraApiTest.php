<?php

namespace Tests\Feature\ActivosFijos;

use App\Events\FixedAssetUnitUpdated;
use App\Events\FixedAssetUpdated;
use App\Models\FixedAsset;
use App\Models\FixedAssetReceiptUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAltaCompraFixtures;
use Tests\TestCase;

class AltaCompraApiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAltaCompraFixtures;

    private const URL_GE = '/api/grupoesplendido/administration/activos-fijos/altas-pendientes';

    private $rec;
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAltaCompraFixtures();
        $this->rec = $this->recepcionConfirmada(3, 'L-100');
        $this->ids = FixedAssetReceiptUnit::where('purchase_receipt_id', $this->rec->id)->orderBy('unit_number')->pluck('id')->all();
    }

    public function test_lista_las_pendientes_con_conteos_y_relaciones(): void
    {
        Sanctum::actingAs($this->usuarioActivos());

        $r = $this->getJson(self::URL_ALTAS)->assertOk();

        $r->assertJsonPath('meta.conteos.pending', 3)
            ->assertJsonPath('meta.conteos.registered', 0)
            ->assertJsonPath('meta.conteos.discarded', 0)
            ->assertJsonCount(3, 'data.data')
            ->assertJsonPath('data.data.0.product.name', $this->insumo->name)
            ->assertJsonPath('data.data.0.receipt.receipt_number', $this->rec->receipt_number)
            ->assertJsonPath('data.data.0.receipt.supplier.business_name', $this->proveedor->business_name)
            ->assertJsonPath('data.data.0.receipt.almacen.name', $this->almacenA->name);
    }

    public function test_filtra_por_estado_y_busca(): void
    {
        FixedAssetReceiptUnit::whereKey($this->ids[0])->update(['status' => 'discarded', 'discarded_reason' => 'x']);
        Sanctum::actingAs($this->usuarioActivos());

        $this->getJson(self::URL_ALTAS . '?status=discarded')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson(self::URL_ALTAS . '?status=all')->assertOk()->assertJsonCount(3, 'data.data');
        $this->getJson(self::URL_ALTAS . '?search=' . urlencode($this->rec->receipt_number))->assertOk()->assertJsonCount(2, 'data.data');
        $this->getJson(self::URL_ALTAS . '?search=Agroqu')->assertOk()->assertJsonCount(2, 'data.data'); // proveedor
        $this->getJson(self::URL_ALTAS . '?search=no-existe-xyz')->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson(self::URL_ALTAS . '?status=invalido')->assertStatus(422);
    }

    public function test_sin_activos_create_es_403_en_todo(): void
    {
        Sanctum::actingAs($this->usuarioActivos($this->empresa, ['view']));

        $this->getJson(self::URL_ALTAS)->assertForbidden();
        $this->postJson(self::URL_ALTAS . '/alta', ['units' => [['id' => $this->ids[0]]], 'category_id' => $this->tipoActivo->id])->assertForbidden();
        $this->postJson(self::URL_ALTAS . '/descartar', ['unit_ids' => [$this->ids[0]], 'reason' => 'x'])->assertForbidden();
    }

    public function test_cada_empresa_solo_ve_lo_suyo_y_ge_ve_todo(): void
    {
        $ajena = FixedAssetReceiptUnit::create([
            'enterprise_id' => $this->corporativo->id,
            'purchase_receipt_id' => $this->rec->id,
            'purchase_receipt_detail_id' => $this->rec->details()->first()->id,
            'product_id' => $this->insumo->id, 'unit_number' => 99, 'unit_cost' => 1, 'status' => 'pending',
        ]);

        Sanctum::actingAs($this->usuarioActivos($this->empresa));
        $this->getJson(self::URL_ALTAS)->assertOk()->assertJsonCount(3, 'data.data');
        $this->postJson(self::URL_ALTAS . '/descartar', ['unit_ids' => [$ajena->id], 'reason' => 'x'])->assertNotFound();

        Sanctum::actingAs($this->usuarioActivos($this->corporativo));
        $this->getJson(self::URL_GE)->assertOk()->assertJsonCount(4, 'data.data');
        $this->getJson(self::URL_GE . '?enterprise_id=' . $this->empresa->id)->assertOk()->assertJsonCount(3, 'data.data');
    }

    public function test_alta_crea_los_activos_y_responde_los_codigos(): void
    {
        Event::fake([FixedAssetUnitUpdated::class, FixedAssetUpdated::class]);
        Sanctum::actingAs($this->usuarioActivos());

        $this->postJson(self::URL_ALTAS . '/alta', [
            'units' => [['id' => $this->ids[0], 'serial_number' => 'SN-1'], ['id' => $this->ids[1]]],
            'category_id' => $this->tipoActivo->id,
            'subcategory_id' => $this->subtipoActivo->id,
            'model' => 'Latitude',
        ])->assertCreated()
            ->assertJsonCount(2, 'data.assets')
            ->assertJsonPath('data.assets.0.code', 'SF-AF-000001');

        $this->assertSame(2, FixedAsset::count());
        $this->assertSame('registered', FixedAssetReceiptUnit::find($this->ids[0])->status);
        $this->assertSame('pending', FixedAssetReceiptUnit::find($this->ids[2])->status);

        Event::assertDispatched(FixedAssetUnitUpdated::class, function (FixedAssetUnitUpdated $e) {
            return $e->action === 'registered'
                && $e->broadcastAs() === 'fixed-asset-unit.updated'
                && collect($e->broadcastOn())->map->name->all() === [
                    'private-module.splendidfarms.administration.activos-fijos',
                    'private-module.grupoesplendido.administration.activos-fijos',
                ];
        });
        Event::assertDispatched(FixedAssetUpdated::class, fn ($e) => $e->action === 'created');
    }

    public function test_alta_desde_ge_crea_el_activo_en_la_empresa_dueña_de_la_unidad(): void
    {
        Sanctum::actingAs($this->usuarioActivos($this->corporativo));

        $this->postJson(self::URL_GE . '/alta', [
            'units' => [['id' => $this->ids[0]]], 'category_id' => $this->tipoActivo->id,
        ])->assertCreated()->assertJsonPath('data.assets.0.code', 'SF-AF-000001');

        $this->assertSame($this->empresa->id, FixedAsset::first()->enterprise_id);
    }

    public function test_alta_valida_los_datos(): void
    {
        Sanctum::actingAs($this->usuarioActivos());

        $this->postJson(self::URL_ALTAS . '/alta', ['units' => [], 'category_id' => $this->tipoActivo->id])->assertStatus(422)->assertJsonValidationErrors('units');
        $this->postJson(self::URL_ALTAS . '/alta', ['units' => [['id' => $this->ids[0]]]])->assertStatus(422)->assertJsonValidationErrors('category_id');
        $this->postJson(self::URL_ALTAS . '/alta', [
            'units' => [['id' => $this->ids[0]]], 'category_id' => $this->tipoActivo->id, 'subcategory_id' => $this->tipoActivo->id,
        ])->assertStatus(422)->assertJsonValidationErrors('subcategory_id');
        $this->assertSame(0, FixedAsset::count());
    }

    public function test_alta_de_una_unidad_ya_registrada_da_422(): void
    {
        Sanctum::actingAs($this->usuarioActivos());
        $cuerpo = ['units' => [['id' => $this->ids[0]]], 'category_id' => $this->tipoActivo->id];

        $this->postJson(self::URL_ALTAS . '/alta', $cuerpo)->assertCreated();
        $this->postJson(self::URL_ALTAS . '/alta', $cuerpo)->assertStatus(422)->assertJsonValidationErrors('units');
        $this->assertSame(1, FixedAsset::count());
    }

    public function test_descartar_exige_motivo_y_emite_el_evento(): void
    {
        Event::fake([FixedAssetUnitUpdated::class]);
        Sanctum::actingAs($this->usuarioActivos());

        $this->postJson(self::URL_ALTAS . '/descartar', ['unit_ids' => [$this->ids[0]]])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson(self::URL_ALTAS . '/descartar', ['unit_ids' => [$this->ids[0], $this->ids[1]], 'reason' => 'Es consumible'])
            ->assertOk()->assertJsonPath('data.descartadas', 2);

        $this->assertSame('discarded', FixedAssetReceiptUnit::find($this->ids[0])->status);
        Event::assertDispatched(FixedAssetUnitUpdated::class, fn ($e) => $e->action === 'discarded');
    }

    public function test_confirmar_una_recepcion_con_activos_emite_el_evento_created(): void
    {
        Event::fake([FixedAssetUnitUpdated::class]);

        $this->recepcionConfirmada(2, 'L-200');

        Event::assertDispatched(FixedAssetUnitUpdated::class, fn ($e) => $e->action === 'created');
    }

    public function test_confirmar_emite_el_evento_por_el_canal_de_la_empresa_dueña(): void
    {
        Event::fake([FixedAssetUnitUpdated::class]);

        $rec = $this->recepcionConfirmada(2, 'L-400');

        Event::assertDispatched(FixedAssetUnitUpdated::class, fn ($e) => $e->action === 'created'
            && $e->empresaActivo === 'splendidfarms'
            && $e->data === ['purchase_receipt_id' => $rec->id]);
    }

    public function test_confirmar_una_recepcion_sin_empresa_no_emite_y_no_rompe(): void
    {
        // El evento sale con el slug de la empresa dueña de la recepción, no con el del header:
        // una recepción heredada sin empresa no tiene canal, así que no se emite nada.
        $this->insumo->update(['is_fixed_asset' => false]);
        $req = $this->crearRequisicion($this->encargadoCompra, $this->almacenA, 'orden_generada');
        $oc = $this->crearOrden(['status' => 'approved', 'requisicion_campo_id' => $req->id], 10, 100);
        $id = $this->actingAs($this->encargadoCompra)->postJson(self::URL_RECEPCIONES, [
            'purchase_order_id' => $oc->id, 'receipt_date' => now()->toDateString(),
            'details' => [[
                'purchase_order_detail_id' => $oc->details->first()->id,
                'quantity_received' => 2, 'lot_number' => 'L-400', 'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ], $this->headersEmpresa())->assertCreated()->json('data.id');
        $this->actingAs($this->encargadoCompra)->postJson(self::URL_RECEPCIONES . "/$id/submit", [], $this->headersEmpresa())->assertOk();

        $recepcion = \App\Models\PurchaseReceipt::find($id);
        $recepcion->forceFill(['enterprise_id' => null])->save();
        FixedAssetReceiptUnit::create([
            'enterprise_id' => $this->empresa->id, 'purchase_receipt_id' => $id,
            'purchase_receipt_detail_id' => $recepcion->details()->first()->id,
            'product_id' => $this->insumo->id, 'unit_number' => 1, 'unit_cost' => 1, 'status' => 'pending',
        ]);
        Event::fake([FixedAssetUnitUpdated::class]);

        $this->actingAs($this->comprasUser)->postJson(self::URL_RECEPCIONES . "/$id/confirmar", [], $this->headersEmpresa())
            ->assertOk();

        Event::assertNotDispatched(FixedAssetUnitUpdated::class);
    }

    public function test_descartar_unidades_de_varias_empresas_emite_un_evento_por_empresa(): void
    {
        $ajena = FixedAssetReceiptUnit::create([
            'enterprise_id' => $this->corporativo->id,
            'purchase_receipt_id' => $this->rec->id,
            'purchase_receipt_detail_id' => $this->rec->details()->first()->id,
            'product_id' => $this->insumo->id, 'unit_number' => 99, 'unit_cost' => 1, 'status' => 'pending',
        ]);
        Event::fake([FixedAssetUnitUpdated::class]);
        Sanctum::actingAs($this->usuarioActivos($this->corporativo));

        $this->postJson(self::URL_GE . '/descartar', ['unit_ids' => [$this->ids[0], $ajena->id, $this->ids[1]], 'reason' => 'No son activos'])
            ->assertOk()->assertJsonPath('data.descartadas', 3);

        Event::assertDispatchedTimes(FixedAssetUnitUpdated::class, 2);
        Event::assertDispatched(FixedAssetUnitUpdated::class, fn ($e) => $e->action === 'discarded'
            && $e->empresaActivo === 'splendidfarms'
            && collect($e->data['unit_ids'])->sort()->values()->all() === collect([$this->ids[0], $this->ids[1]])->sort()->values()->all());
        Event::assertDispatched(FixedAssetUnitUpdated::class, fn ($e) => $e->action === 'discarded'
            && $e->empresaActivo === 'grupoesplendido'
            && $e->data['unit_ids'] === [$ajena->id]);
    }

    public function test_confirmar_una_recepcion_sin_activos_no_emite_nada(): void
    {
        $this->insumo->update(['is_fixed_asset' => false]);
        Event::fake([FixedAssetUnitUpdated::class]);

        $this->recepcionConfirmada(2, 'L-300');

        Event::assertNotDispatched(FixedAssetUnitUpdated::class);
    }
}
