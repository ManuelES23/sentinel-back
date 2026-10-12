<?php

namespace App\Services\Inventory;

use App\Models\CapaCosto;
use App\Models\InventoryKardex;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementDetail;
use App\Models\InventoryStock;
use App\Models\Lote;
use App\Support\Inventario\Cubeta;
use App\Support\Inventario\ResultadoMovimiento;

/**
 * Efecto de un renglón de movimiento: motor PEPS → cantidad de la fila →
 * kardex con la dirección física real y el costo real de la operación.
 */
class AplicadorStock
{
    public function __construct(private CapasCosto $capas)
    {
    }

    public function aumentar(InventoryMovementDetail $d, int $entityId, ?string $entityType, InventoryMovement $m, string $origen = CapaCosto::ORIGEN_ENTRADA): ResultadoMovimiento
    {
        $c = $this->cubeta($d, $entityId);
        $r = $this->capas->entrar($c, $this->cantidad($d), (float) ($d->unit_cost ?? 0), $m->movement_date ?? now(), $origen, $m->id, $d->id);
        InventoryStock::ajustarCantidad($c, $r->cantidad, $d->expiry_date, $m->id);
        $this->kardex($d, $entityId, $entityType, $m, 'increase', $r);

        return $r;
    }

    public function disminuir(InventoryMovementDetail $d, int $entityId, ?string $entityType, InventoryMovement $m): ResultadoMovimiento
    {
        $c = $this->cubeta($d, $entityId);
        $r = $this->capas->salir($c, $this->cantidad($d), $m->id, $d->id);
        InventoryStock::ajustarCantidad($c, -$r->cantidad, null, $m->id);
        $d->forceFill(['unit_cost' => round($r->costoUnitario(), 4), 'total_cost' => round($r->importe, 4)])->save();
        $this->kardex($d, $entityId, $entityType, $m, 'decrease', $r);

        return $r;
    }

    public function devolver(InventoryMovementDetail $salida, int $origenId, ?string $origenType, InventoryMovement $m, float $cantidad,
        ?int $destinoId = null, ?string $destinoType = null, ?string $descripcion = null): ResultadoMovimiento
    {
        $origen = $this->cubeta($salida, $origenId);
        $destino = $destinoId ? $this->cubeta($salida, $destinoId) : null;
        $r = $this->capas->devolver($origen, $cantidad, $salida->id, null, $destino, (float) ($salida->unit_cost ?? 0), $m->id);
        $entrega = $destino ?? $origen;
        InventoryStock::ajustarCantidad($entrega, $r->cantidad, $salida->expiry_date, $m->id);
        $this->kardex($salida, $entrega->entityId, $destino ? $destinoType : $origenType, $m, 'increase', $r, $descripcion);

        return $r;
    }

    public function revertirEntrada(InventoryMovementDetail $entrada, int $entityId, ?string $entityType, InventoryMovement $m): ResultadoMovimiento
    {
        $c = $this->cubeta($entrada, $entityId);
        $r = $this->capas->revertirEntrada($c, $entrada->id, $this->cantidad($entrada));
        InventoryStock::ajustarCantidad($c, -$r->cantidad, null, $m->id);
        $this->kardex($entrada, $entityId, $entityType, $m, 'decrease', $r, 'Cancelación de ' . ($m->document_number ?? 'movimiento'));

        return $r;
    }

    public function deshacerTransferencia(InventoryMovementDetail $d, int $origenId, ?string $origenType, int $destinoId, ?string $destinoType, InventoryMovement $m): ResultadoMovimiento
    {
        $origen = $this->cubeta($d, $origenId);
        $destino = $this->cubeta($d, $destinoId);
        $r = $this->capas->deshacerTransferencia($origen, $destino, $d->id, $this->cantidad($d), (float) ($d->unit_cost ?? 0));
        InventoryStock::ajustarCantidad($destino, -$r->cantidad, null, $m->id);
        InventoryStock::ajustarCantidad($origen, $r->cantidad, $d->expiry_date, $m->id);
        $texto = 'Cancelación de ' . ($m->document_number ?? 'transferencia');
        $this->kardex($d, $destinoId, $destinoType, $m, 'decrease', $r, $texto);
        $this->kardex($d, $origenId, $origenType, $m, 'increase', $r, $texto);

        return $r;
    }

    private function cubeta(InventoryMovementDetail $d, int $entityId): Cubeta
    {
        return new Cubeta((int) $d->product_id, $entityId, null, $d->lot_number);
    }

    private function cantidad(InventoryMovementDetail $d): float
    {
        return (float) ($d->base_quantity ?? $d->quantity);
    }

    private function kardex(InventoryMovementDetail $d, int $entityId, ?string $entityType, InventoryMovement $m, string $tipo, ResultadoMovimiento $r, ?string $descripcion = null): void
    {
        if ($r->cantidad <= 0) {
            return;
        }

        InventoryKardex::recordEntry(
            $d->product_id, $entityId, $entityType, $m->id, $tipo, $r->cantidad, $r->costoUnitario(),
            $d->lot_number, $d->serial_number ?? null, null, $this->productorId($d), $descripcion,
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
