<?php

namespace App\Events;

use App\Services\ActivosFijos\AlcanceActivos;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Alta, cambio o baja de un activo fijo. Viaja por el canal del módulo de la
 * empresa dueña y por el de Grupo Espléndido (vista corporativa). Solo manda
 * el id: la tabla está paginada en el servidor y el front recarga la página.
 */
class FixedAssetUpdated extends ModelBroadcastEvent
{
    public function __construct(string $action, array $data, public string $empresaActivo)
    {
        parent::__construct($action, $data, $empresaActivo, 'administration', 'activos-fijos');
    }

    public function broadcastAs(): string
    {
        return 'fixed-asset.updated';
    }

    public function broadcastOn(): array
    {
        return collect([$this->empresaActivo, AlcanceActivos::EMPRESA_CORPORATIVA])
            ->unique()
            ->map(fn ($slug) => new PrivateChannel("module.{$slug}.administration.activos-fijos"))
            ->values()
            ->all();
    }
}
