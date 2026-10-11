<?php

namespace App\Services\ActivosFijos;

use App\Models\FixedAssetReceiptUnit;
use App\Models\InventoryStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Consultas sobre las unidades de compra que respaldan activos fijos y la regla
 * que protege el stock: mientras haya unidades vigentes de un producto en un
 * almacén y lote, no se puede dejar el stock por debajo de esa cantidad.
 *
 * Un lote vacío, nulo o 'SIN-LOTE' es la misma cubeta (así normaliza el
 * catálogo las existencias sin lote cuando se activa el control por lotes).
 */
class UnidadesVigentes
{
    private const SIN_LOTE = 'SIN-LOTE';

    public function reservadas(int $productId, int $entityId, ?string $lote): int
    {
        return $this->filtrarCubeta(
            FixedAssetReceiptUnit::vigentes()
                ->where('product_id', $productId)
                ->whereHas('receipt', fn ($r) => $r->where('almacen_id', $entityId)),
            $lote,
        )->count();
    }

    /**
     * Existencia total de la cubeta (producto, almacén y lote normalizado),
     * sumando todas sus filas de stock sin importar el área.
     */
    public function existenciaEnCubeta(int $productId, int $entityId, ?string $lote): float
    {
        return (float) $this->filtrarCubeta(
            InventoryStock::where('product_id', $productId)->where('entity_id', $entityId),
            $lote,
        )->sum('quantity');
    }

    public function hayVigentesEnRecepcion(int $receiptId): bool
    {
        return FixedAssetReceiptUnit::vigentes()->where('purchase_receipt_id', $receiptId)->exists();
    }

    /**
     * Rechaza el cambio si deja la cubeta por debajo de las unidades vigentes.
     * La existencia se lee de toda la cubeta (no solo de la fila que se mueve),
     * y solo cuando hay reservas: sin unidades vigentes basta una consulta.
     *
     * @param float $cambio Cambio negativo que se pretende aplicar.
     */
    public function exigirRespaldo(int $productId, int $entityId, ?string $lote, float $cambio): void
    {
        $reservadas = $this->reservadas($productId, $entityId, $lote);
        if ($reservadas === 0) {
            return;
        }

        $existencia = $this->existenciaEnCubeta($productId, $entityId, $lote);

        // Redondeo a 4 decimales (la precisión de inventory_stock.quantity): 10.2 - 4.2 debe valer 6.
        if (round($existencia + $cambio, 4) < round($reservadas, 4)) {
            $libres = max(0.0, round($existencia - $reservadas, 4));

            throw ValidationException::withMessages([
                'stock' => "No se puede dar salida a esa cantidad: {$reservadas} unidad(es) de este artículo respaldan activos fijos "
                    . "(vigentes o por dar de alta) y solo hay {$libres} libres. Dalas de alta, descártalas o dalas de baja primero.",
            ]);
        }
    }

    /** Nulo, vacío y SIN-LOTE son la misma cubeta; cualquier otro lote se compara exacto. */
    private function filtrarCubeta(Builder $query, ?string $lote): Builder
    {
        if ($lote === null || $lote === '' || $lote === self::SIN_LOTE) {
            return $query->where(fn ($w) => $w->whereNull('lot_number')->orWhereIn('lot_number', ['', self::SIN_LOTE]));
        }

        return $query->where('lot_number', $lote);
    }
}
