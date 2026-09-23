<?php

namespace Tests\Feature\Compras;

use App\Models\CosteoAgricola;
use App\Models\PurchaseOrder;
use App\Models\RequisicionCotizacion;
use App\Services\Compras\CotizacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class GenerarOrdenTest extends TestCase
{
    use RefreshDatabase, CreatesComprasFixtures;

    private $compras;
    private $req;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpComprasFixtures();
        $this->compras = $this->crearUsuarioDeCampo();
        $this->otorgarCotizar($this->compras);
        $this->otorgarVerTodos($this->compras);
        $this->req = $this->crearRequisicion($this->crearUsuarioDeCampo([$this->almacenA]), $this->almacenA, 'enviada', 10);
        // segundo renglón que el proveedor ganador no surte
        $this->req->detalles()->create(['product_id' => $this->insumo->id, 'nombre_producto' => 'Clorotalonil 720', 'cantidad' => 3, 'unit_id' => $this->unidad->id]);
    }

    private function url(): string
    {
        return "/api/splendidfarms/administration/compras/requisiciones/{$this->req->id}/generar-orden";
    }

    public function test_sin_ganadora_responde_422(): void
    {
        $this->actingAs($this->compras)->postJson($this->url(), ['order_date' => now()->toDateString()], $this->headersEmpresa())
            ->assertStatus(422);
    }

    public function test_genera_oc_con_proveedor_precios_y_almacen_de_la_ganadora(): void
    {
        [$d1, $d2] = $this->req->detalles()->orderBy('id')->get()->all();
        $servicio = app(CotizacionService::class);

        // Perdedora: proveedor2, más barata y con otras condiciones — no debe
        // ser la que use la OC.
        $perdedora = $servicio->guardar($this->req, [
            'supplier_id' => $this->proveedor2->id, 'fecha' => now()->toDateString(), 'dias_entrega' => 10, 'condiciones_pago' => 'Contado',
            'detalles' => [
                ['requisicion_detalle_id' => $d1->id, 'disponible' => true, 'cantidad' => 10, 'precio_unitario' => 70, 'tax_rate' => 16],
                ['requisicion_detalle_id' => $d2->id, 'disponible' => false, 'cantidad' => 3, 'precio_unitario' => 0, 'tax_rate' => 16],
            ],
        ], $this->compras);

        // Ganadora: proveedor, marcada explícitamente con marcarGanadora().
        $cot = $servicio->guardar($this->req, [
            'supplier_id' => $this->proveedor->id, 'fecha' => now()->toDateString(), 'dias_entrega' => 4, 'condiciones_pago' => '15 días',
            'detalles' => [
                ['requisicion_detalle_id' => $d1->id, 'disponible' => true, 'cantidad' => 10, 'precio_unitario' => 95, 'tax_rate' => 16],
                ['requisicion_detalle_id' => $d2->id, 'disponible' => false, 'cantidad' => 3, 'precio_unitario' => 0, 'tax_rate' => 16],
            ],
        ], $this->compras);
        $servicio->marcarGanadora($cot);

        $ing = $this->crearUsuarioDeCampo([$this->almacenA]);
        $this->actingAs($ing)->postJson($this->url(), ['order_date' => now()->toDateString()], $this->headersEmpresa())->assertForbidden();

        $res = $this->actingAs($this->compras)->postJson($this->url(), ['order_date' => now()->toDateString()], $this->headersEmpresa());

        $res->assertOk();
        $oc = PurchaseOrder::with('details')->first();
        // Datos de la GANADORA, no de la perdedora (más barata).
        $this->assertSame($this->proveedor->id, $oc->supplier_id);
        $this->assertNotSame($perdedora->id, $oc->cotizacion_id);
        $this->assertSame($this->empresa->id, $oc->enterprise_id);
        $this->assertSame($this->almacenA->id, $oc->almacen_destino_id);
        $this->assertSame($this->req->id, $oc->requisicion_campo_id);
        $this->assertSame($cot->id, $oc->cotizacion_id);
        $this->assertSame('draft', $oc->status);
        $this->assertSame('15 días', $oc->payment_conditions);
        $this->assertSame(now()->addDays(4)->toDateString(), $oc->expected_date->toDateString());
        $this->assertCount(1, $oc->details);
        $this->assertEquals(95, (float) $oc->details->first()->unit_price);
        $this->assertSame('orden_generada', $this->req->fresh()->status);
        $this->assertSame(1, CosteoAgricola::where('fuente_id', $this->req->id)->count());
    }

    /**
     * Regresión: duplicar una OC generada desde una cotización NO debe
     * arrastrar el vínculo requisicion_campo_id/cotizacion_id (ni el
     * metadata que también lo trae) — si lo hiciera, la OC duplicada podría
     * terminar completando la requisición original en lugar de la OC que
     * de verdad la surtió (ver ConfirmadorRecepcion::confirmar()).
     */
    public function test_duplicate_no_copia_el_vinculo_a_requisicion_ni_cotizacion(): void
    {
        [$d1, $d2] = $this->req->detalles()->orderBy('id')->get()->all();
        $servicio = app(CotizacionService::class);

        $cot = $servicio->guardar($this->req, [
            'supplier_id' => $this->proveedor->id, 'fecha' => now()->toDateString(), 'dias_entrega' => 4, 'condiciones_pago' => '15 días',
            'detalles' => [
                ['requisicion_detalle_id' => $d1->id, 'disponible' => true, 'cantidad' => 10, 'precio_unitario' => 95, 'tax_rate' => 16],
                ['requisicion_detalle_id' => $d2->id, 'disponible' => false, 'cantidad' => 3, 'precio_unitario' => 0, 'tax_rate' => 16],
            ],
        ], $this->compras);
        $servicio->marcarGanadora($cot);

        $this->actingAs($this->compras)->postJson($this->url(), ['order_date' => now()->toDateString()], $this->headersEmpresa())->assertOk();

        $oc = PurchaseOrder::with('details')->first();
        $this->assertNotNull($oc->requisicion_campo_id);
        $this->assertNotNull($oc->cotizacion_id);
        $this->assertNotEmpty($oc->metadata);

        $dupId = $this->actingAs($this->compras)
            ->postJson("/api/splendidfarms/administration/compras/ordenes/{$oc->id}/duplicate", [], $this->headersEmpresa())
            ->assertStatus(201)
            ->json('data.id');

        $duplicado = PurchaseOrder::find($dupId);
        $this->assertNull($duplicado->requisicion_campo_id);
        $this->assertNull($duplicado->cotizacion_id);
        $this->assertEmpty($duplicado->metadata);

        // La original conserva su vínculo intacto.
        $original = $oc->fresh();
        $this->assertSame($oc->requisicion_campo_id, $original->requisicion_campo_id);
        $this->assertSame($oc->cotizacion_id, $original->cotizacion_id);
    }
}
