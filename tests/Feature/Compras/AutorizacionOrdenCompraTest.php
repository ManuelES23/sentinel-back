<?php

namespace Tests\Feature\Compras;

use App\Models\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class AutorizacionOrdenCompraTest extends TestCase
{
    use RefreshDatabase, CreatesComprasFixtures;

    private const URL = '/api/splendidfarms/inventario/compras/ordenes';

    private $compras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpComprasFixtures();
        $this->compras = $this->crearUsuarioDeCampo();
        $this->otorgarVerTodos($this->compras);
    }

    public function test_mandar_a_autorizar_sin_flujo_responde_422(): void
    {
        $oc = $this->crearOrden(['created_by' => $this->compras->id]);

        $this->actingAs($this->compras)->postJson(self::URL . "/{$oc->id}/submit", [], $this->headersEmpresa())
            ->assertStatus(422)->assertJsonFragment(['message' => 'Configura el flujo de aprobación de órdenes de compra']);
    }

    public function test_flujo_de_autorizacion(): void
    {
        $gerente = $this->crearAprobador('enterprise');
        $oc = $this->crearOrden(['created_by' => $this->compras->id]);

        $this->actingAs($this->compras)->postJson(self::URL . "/{$oc->id}/submit", [], $this->headersEmpresa())->assertOk();
        $this->assertSame('pending', $oc->fresh()->status);
        $this->assertSame(1, SystemNotification::where('user_id', $gerente->id)->count());

        $this->actingAs($this->compras)->postJson(self::URL . "/{$oc->id}/approve", [], $this->headersEmpresa())->assertForbidden();
        $this->actingAs($gerente)->getJson(self::URL . "/{$oc->id}/capacidades", $this->headersEmpresa())
            ->assertOk()->assertJsonPath('data.puede_aprobar', true);

        $this->actingAs($gerente)->postJson(self::URL . "/{$oc->id}/approve", [], $this->headersEmpresa())->assertOk();
        $this->assertSame('approved', $oc->fresh()->status);
        $this->assertNotNull($oc->fresh()->approved_at);
    }

    public function test_rechazo_y_reedicion(): void
    {
        $gerente = $this->crearAprobador('enterprise');
        $oc = $this->crearOrden(['created_by' => $this->compras->id, 'status' => 'pending']);

        $this->actingAs($gerente)->postJson(self::URL . "/{$oc->id}/reject", ['reason' => 'Muy caro'], $this->headersEmpresa())->assertOk();
        $this->assertSame('rejected', $oc->fresh()->status);

        $this->actingAs($this->compras)->putJson(self::URL . "/{$oc->id}", ['notes' => 'Ajustada'], $this->headersEmpresa())->assertOk();
        $this->assertSame('draft', $oc->fresh()->status);
    }

    public function test_encargado_solo_ve_oc_de_sus_almacenes(): void
    {
        $encargado = $this->crearUsuarioDeCampo([$this->almacenA]);
        $ocA = $this->crearOrden();
        $ocB = $this->crearOrden(['almacen_destino_id' => $this->almacenB->id]);

        $ids = collect($this->actingAs($encargado)->getJson(self::URL, $this->headersEmpresa())->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertSame([$ocA->id], $ids);
        $this->actingAs($encargado)->getJson(self::URL . "/{$ocB->id}", $this->headersEmpresa())->assertForbidden();
        $this->actingAs($encargado)->postJson(self::URL, [
            'supplier_id' => $this->proveedor->id, 'order_date' => now()->toDateString(), 'almacen_destino_id' => $this->almacenB->id,
            'details' => [['product_id' => $this->insumo->id, 'quantity_ordered' => 1, 'unit_price' => 1]],
        ], $this->headersEmpresa())->assertForbidden();
        $this->actingAs($encargado)->putJson(self::URL . "/{$ocA->id}", ['notes' => 'x'], $this->headersEmpresa())->assertForbidden();
    }

    public function test_aprobaciones_pendientes_lista_y_aprueba_oc(): void
    {
        $gerente = $this->crearAprobador('enterprise');
        $oc = $this->crearOrden(['created_by' => $this->compras->id, 'status' => 'pending']);

        $items = collect($this->actingAs($gerente)->getJson('/api/pending-approvals')->assertOk()->json('data.processes'))
            ->flatMap(fn ($p) => $p['items'] ?? [])->where('type', 'purchase_order');
        $this->assertSame([$oc->id], $items->pluck('id')->values()->all());

        $otro = $this->crearUsuarioDeCampo();
        $this->actingAs($otro)->postJson("/api/pending-approvals/purchase_order/{$oc->id}/approve")->assertForbidden();
        $this->actingAs($gerente)->postJson("/api/pending-approvals/purchase_order/{$oc->id}/approve")->assertOk();
        $this->assertSame('approved', $oc->fresh()->status);
    }
}
