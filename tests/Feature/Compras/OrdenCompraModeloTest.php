<?php

namespace Tests\Feature\Compras;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class OrdenCompraModeloTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures;

    private PurchaseOrder $oc;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
        $this->user = User::factory()->create();
        $supplier = Supplier::create(['code' => 'PRV-1', 'business_name' => 'Agroquímicos del Norte', 'is_active' => true]);
        $this->oc = PurchaseOrder::create([
            'order_number' => 'OC-2026-00001', 'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'status' => 'pending', 'currency_code' => 'MXN', 'created_by' => $this->user->id,
        ]);
        $this->oc->details()->create(['product_id' => $this->insumo->id, 'quantity_ordered' => 10, 'unit_price' => 100, 'tax_rate' => 16, 'line_number' => 1]);
    }

    public function test_approve_escribe_approved_at(): void
    {
        $this->assertTrue($this->oc->approve($this->user->id));
        $this->oc->refresh();
        $this->assertSame('approved', $this->oc->status);
        $this->assertNotNull($this->oc->approved_at);
    }

    public function test_reject_guarda_motivo_y_estado(): void
    {
        $this->assertTrue($this->oc->reject($this->user->id, 'Precio alto'));
        $this->oc->refresh();
        $this->assertSame('rejected', $this->oc->status);
        $this->assertSame('Precio alto', $this->oc->rejection_reason);
        $this->assertTrue($this->oc->is_editable);
    }

    public function test_mark_as_sent_funciona(): void
    {
        $this->oc->approve($this->user->id);
        $this->assertTrue($this->oc->markAsSent($this->user->id));
        $this->assertNotNull($this->oc->fresh()->sent_at);
    }

    public function test_estado_por_recepciones_usa_quantity_ordered(): void
    {
        $this->oc->approve($this->user->id);
        $det = $this->oc->details()->first();
        $det->update(['quantity_received' => 4]);
        $this->oc->updateStatusFromReceipts();
        $this->assertSame('partial', $this->oc->fresh()->status);

        $det->update(['quantity_received' => 10]);
        $this->oc->updateStatusFromReceipts();
        $this->assertSame('completed', $this->oc->fresh()->status);
    }
}
