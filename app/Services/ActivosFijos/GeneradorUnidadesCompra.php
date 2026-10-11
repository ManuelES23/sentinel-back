<?php

namespace App\Services\ActivosFijos;

use App\Models\FixedAssetReceiptUnit;
use App\Models\PurchaseReceipt;

/**
 * Al confirmar una recepción, crea una unidad pendiente de alta por cada unidad
 * entera aceptada de un producto marcado como activo fijo. Idempotente: el
 * índice único (renglón, número de unidad) impide duplicarlas.
 */
class GeneradorUnidadesCompra
{
    /** @return int Unidades creadas en esta llamada. */
    public function generar(PurchaseReceipt $recepcion): int
    {
        // Una recepción heredada sin empresa no tiene bandeja a la cual asignarse
        // (AlcanceCompras ya la trata aparte): se confirma sin generar unidades.
        if ($recepcion->enterprise_id === null) {
            return 0;
        }

        $creadas = 0;

        $renglones = $recepcion->details()->with('product:id,is_fixed_asset')->get();

        foreach ($renglones as $renglon) {
            if (! $renglon->product?->is_fixed_asset) {
                continue;
            }

            $unidades = (int) floor((float) $renglon->quantity_accepted);

            for ($numero = 1; $numero <= $unidades; $numero++) {
                $unidad = FixedAssetReceiptUnit::firstOrCreate(
                    ['purchase_receipt_detail_id' => $renglon->id, 'unit_number' => $numero],
                    [
                        'enterprise_id' => $recepcion->enterprise_id,
                        'purchase_receipt_id' => $recepcion->id,
                        'product_id' => $renglon->product_id,
                        'lot_number' => $renglon->lot_number,
                        'unit_cost' => $renglon->unit_cost,
                        'status' => FixedAssetReceiptUnit::STATUS_PENDING,
                    ],
                );

                if ($unidad->wasRecentlyCreated) {
                    $creadas++;
                }
            }
        }

        return $creadas;
    }
}
