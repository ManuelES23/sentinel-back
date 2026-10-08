<?php

namespace App\Services\ActivosFijos;

use App\Models\FixedAssetReceiptUnit;
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
        $sinLote = $lote === null || $lote === '' || $lote === self::SIN_LOTE;

        return FixedAssetReceiptUnit::vigentes()
            ->where('product_id', $productId)
            ->whereHas('receipt', fn ($r) => $r->where('almacen_id', $entityId))
            ->when(
                $sinLote,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('lot_number')->orWhereIn('lot_number', ['', self::SIN_LOTE])),
                fn ($q) => $q->where('lot_number', $lote),
            )
            ->count();
    }

    public function hayVigentesEnRecepcion(int $receiptId): bool
    {
        return FixedAssetReceiptUnit::vigentes()->where('purchase_receipt_id', $receiptId)->exists();
    }

    /**
     * @param float $stockActual Existencia de la fila de stock antes del cambio.
     * @param float $cambio      Cambio negativo que se pretende aplicar.
     */
    public function exigirRespaldo(int $productId, int $entityId, ?string $lote, float $stockActual, float $cambio): void
    {
        $reservadas = $this->reservadas($productId, $entityId, $lote);

        if ($reservadas > 0 && ($stockActual + $cambio) < $reservadas) {
            $libres = max(0.0, round($stockActual - $reservadas, 4));

            throw ValidationException::withMessages([
                'stock' => "No se puede dar salida a esa cantidad: {$reservadas} unidad(es) de este artículo respaldan activos fijos "
                    . "(vigentes o por dar de alta) y solo hay {$libres} libres. Dalas de alta, descártalas o dalas de baja primero.",
            ]);
        }
    }
}
