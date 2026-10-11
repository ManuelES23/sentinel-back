<?php

namespace App\Events;

/**
 * Cambio en las unidades de compra por dar de alta (nuevas al confirmar una
 * recepción, dadas de alta o descartadas). Viaja por los mismos canales que
 * FixedAssetUpdated (empresa dueña + Grupo Espléndido); solo manda ids.
 */
class FixedAssetUnitUpdated extends FixedAssetUpdated
{
    public function broadcastAs(): string
    {
        return 'fixed-asset-unit.updated';
    }
}
