<?php

namespace App\Support\Inventario;

use App\Models\InventoryStock;
use Illuminate\Database\Eloquent\Builder;

/**
 * Llave de una existencia: producto + almacén + área + lote. El lote vacío
 * y el nulo son la misma cubeta.
 */
final class Cubeta
{
    public readonly ?string $lote;

    public function __construct(
        public readonly int $productId,
        public readonly int $entityId,
        public readonly ?int $areaId = null,
        ?string $lote = null,
    ) {
        $lote = $lote === null ? null : trim($lote);
        $this->lote = $lote === '' ? null : $lote;
    }

    public static function deFila(InventoryStock $fila): self
    {
        return new self((int) $fila->product_id, (int) $fila->entity_id, $fila->area_id ? (int) $fila->area_id : null, $fila->lot_number);
    }

    public function filtrar(Builder $q): Builder
    {
        return $q->where('product_id', $this->productId)
            ->where('entity_id', $this->entityId)
            ->when($this->areaId === null, fn ($w) => $w->whereNull('area_id'), fn ($w) => $w->where('area_id', $this->areaId))
            ->when($this->lote === null,
                fn ($w) => $w->where(fn ($x) => $x->whereNull('lot_number')->orWhere('lot_number', '')),
                fn ($w) => $w->where('lot_number', $this->lote));
    }
}
