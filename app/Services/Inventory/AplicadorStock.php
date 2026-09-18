<?php

namespace App\Services\Inventory;

use App\Models\InventoryKardex;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Lote;

/**
 * Aplica el efecto de un renglón de movimiento sobre el stock (por lote) y
 * lo registra en el kardex. Extraído de InventoryMovementController para que
 * las recepciones de compra usen exactamente el mismo camino.
 */
class AplicadorStock
{
    public function aumentar(object $detail, ?int $entityId, ?string $entityType, InventoryMovement $movement, bool $esReversa = false): void
    {
        $this->aplicar($detail, $entityId, $entityType, $movement, 1, $esReversa ? 'decrease' : 'increase');
    }

    public function disminuir(object $detail, ?int $entityId, ?string $entityType, InventoryMovement $movement, bool $esReversa = false): void
    {
        $this->aplicar($detail, $entityId, $entityType, $movement, -1, $esReversa ? 'increase' : 'decrease');
    }

    private function aplicar(object $detail, ?int $entityId, ?string $entityType, InventoryMovement $movement, int $signo, string $efectoKardex): void
    {
        $quantity = $detail->base_quantity ?? $detail->quantity;

        InventoryStock::updateStock(
            $detail->product_id,
            $entityId,
            null, // area_id (no manejado en este contexto)
            $signo * $quantity,
            $detail->unit_cost ?? 0,
            $detail->lot_number,
            $detail->expiry_date,
            $movement->id
        );

        InventoryKardex::recordEntry(
            $detail->product_id,
            $entityId,
            $entityType,
            $movement->id,
            $efectoKardex,
            $quantity,
            $detail->unit_cost ?? 0,
            $detail->lot_number,
            $detail->serial_number ?? null,
            null, // area_id
            $this->productorId($detail)
        );
    }

    private function productorId(object $detail): ?int
    {
        if (! empty($detail->productor_id)) {
            return (int) $detail->productor_id;
        }

        if (! empty($detail->lote_id)) {
            $lote = Lote::find($detail->lote_id);

            return $lote?->productor_id ? (int) $lote->productor_id : null;
        }

        if (! empty($detail->lot_number) && is_numeric($detail->lot_number)) {
            $lote = Lote::where('numero_lote', (int) $detail->lot_number)->first();

            return $lote?->productor_id ? (int) $lote->productor_id : null;
        }

        return null;
    }
}
