<?php

namespace App\Services\Compras;

use App\Models\AccountPayable;
use App\Models\InventoryMovement;
use App\Models\MovementType;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\PurchaseReceipt;
use App\Models\RequisicionCampo;
use App\Models\User;
use App\Services\Inventory\AplicadorStock;
use App\Services\Inventory\LoteCaducidadValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirmación de una recepción por Compras. En una sola transacción:
 * movimiento COMPRA aprobado + stock por lote + kardex + cantidades y estado
 * de la OC + requisición completada + cuenta por pagar.
 */
class ConfirmadorRecepcion
{
    public function __construct(
        private AplicadorStock $stock,
        private LoteCaducidadValidator $lotes,
    ) {
    }

    public function confirmar(PurchaseReceipt $recepcion, User $user): PurchaseReceipt
    {
        return DB::transaction(function () use ($recepcion, $user) {
            $rec = PurchaseReceipt::whereKey($recepcion->id)->lockForUpdate()->firstOrFail();
            abort_if($rec->status !== PurchaseReceipt::STATUS_PENDING, 409, 'La recepción ya no está por confirmar');

            $rec->load(['details', 'purchaseOrder.details', 'supplier']);
            $oc = $rec->purchaseOrder;
            if (! $oc || ! in_array($oc->status, PurchaseOrder::STATUSES_RECIBIBLES, true)) {
                throw ValidationException::withMessages(['purchase_order_id' => 'La orden de compra ya no admite recepciones.']);
            }

            $tipo = MovementType::where('code', 'COMPRA')->first()
                ?? throw ValidationException::withMessages(['movimiento' => 'No existe el tipo de movimiento COMPRA.']);

            $renglones = $rec->details->filter(fn ($d) => (float) $d->quantity_accepted > 0)->values();
            $this->validar($renglones, $oc, $tipo, (int) $rec->almacen_id);

            $movimiento = InventoryMovement::create([
                'document_number' => InventoryMovement::generateDocumentNumber($tipo->direction),
                'movement_type_id' => $tipo->id,
                'movement_date' => $rec->receipt_date,
                'destination_entity_id' => $rec->almacen_id,
                'destination_entity_type' => 'entity',
                'reference_type' => 'purchase_receipt',
                'reference_id' => $rec->id,
                'reference_number' => $rec->receipt_number,
                'description' => "Entrada por compra {$rec->receipt_number} (OC {$oc->order_number})",
                'status' => 'approved',
                'created_by' => $rec->capturada_por ?? $user->id,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'total_quantity' => $renglones->sum(fn ($d) => (float) $d->quantity_accepted),
                'total_amount' => $renglones->sum(fn ($d) => (float) $d->quantity_accepted * (float) $d->unit_cost),
            ]);

            foreach ($renglones as $d) {
                $cantidad = (float) $d->quantity_accepted;
                $detalle = $movimiento->details()->create([
                    'product_id' => $d->product_id,
                    'quantity' => $cantidad,
                    'unit_id' => $d->unit_id,
                    'base_quantity' => $cantidad,
                    'lot_number' => $d->lot_number,
                    'expiry_date' => $d->expiry_date,
                    'unit_cost' => $d->unit_cost,
                    'total_cost' => $cantidad * (float) $d->unit_cost,
                ]);
                $this->stock->aumentar($detalle, (int) $rec->almacen_id, 'entity', $movimiento);

                if ($d->purchase_order_detail_id) {
                    PurchaseOrderDetail::whereKey($d->purchase_order_detail_id)->increment('quantity_received', $cantidad);
                }
            }

            $oc->refresh();
            $oc->updateStatusFromReceipts();
            if ($oc->status === PurchaseOrder::STATUS_COMPLETED && $oc->requisicion_campo_id) {
                RequisicionCampo::whereKey($oc->requisicion_campo_id)
                    ->where('status', RequisicionCampo::STATUS_ORDEN_GENERADA)
                    ->update(['status' => RequisicionCampo::STATUS_COMPLETADA]);
            }

            $this->cuentaPorPagar($rec, $oc, $user);

            $rec->update([
                'status' => PurchaseReceipt::STATUS_COMPLETED,
                'inventory_movement_id' => $movimiento->id,
                'confirmada_por' => $user->id,
                'confirmada_at' => now(),
                'validated_by' => $user->id,
                'validated_at' => now(),
            ]);

            return $rec->fresh(['inventoryMovement', 'accountPayable', 'purchaseOrder']);
        });
    }

    private function validar($renglones, PurchaseOrder $oc, MovementType $tipo, int $almacenId): void
    {
        $errores = [];
        foreach ($renglones as $i => $d) {
            $linea = $oc->details->firstWhere('id', $d->purchase_order_detail_id);
            $pendiente = $linea ? (float) $linea->quantity_ordered - (float) $linea->quantity_received : 0;
            if ((float) $d->quantity_accepted > $pendiente + 0.0001) {
                $errores["details.$i.quantity_accepted"] = 'Excede lo pendiente por recibir (' . max(0, round($pendiente, 4)) . ').';
            }
        }

        $errores += $this->lotes->validar($tipo, null, $almacenId, $renglones->map(fn ($d) => [
            'product_id' => $d->product_id,
            'lot_number' => $d->lot_number,
            'expiry_date' => $d->expiry_date?->toDateString(),
        ])->all(), false);

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }
    }

    private function cuentaPorPagar(PurchaseReceipt $rec, PurchaseOrder $oc, User $user): AccountPayable
    {
        $rec->refresh();
        $dias = $rec->supplier?->has_credit ? (int) ($rec->supplier->payment_terms ?? 0) : 0;

        return AccountPayable::create([
            'document_number' => AccountPayable::generateDocumentNumber(),
            'document_type' => 'invoice',
            'supplier_id' => $rec->supplier_id,
            'supplier_invoice' => $rec->supplier_document,
            'supplier_invoice_date' => $rec->supplier_document_date,
            'purchase_receipt_id' => $rec->id,
            'purchase_order_id' => $oc->id,
            'document_date' => $rec->receipt_date,
            'due_date' => Carbon::parse($rec->receipt_date)->addDays($dias),
            'payment_terms_days' => $dias,
            'currency_code' => $oc->currency_code ?? 'MXN',
            'exchange_rate' => $oc->exchange_rate ?? 1,
            'subtotal' => $rec->subtotal,
            'tax_amount' => $rec->tax_amount,
            'total_amount' => $rec->total_amount,
            'balance' => $rec->total_amount,
            'status' => 'pending',
            'created_by' => $user->id,
        ]);
    }
}
