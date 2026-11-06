<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Alta, cambio o baja de un registro de Abastecimiento. Viaja por el canal del
 * módulo de la empresa; `data.entidad` dice qué cambió
 * (convenio|recepcion|productor|temporada|cultivo|tipo_carga|liquidacion|abono).
 */
class AbastecimientoUpdated extends ModelBroadcastEvent
{
    public function __construct(string $accion, array $data, public string $empresaSlug, string $entidad)
    {
        parent::__construct($accion, array_merge($data, ['entidad' => $entidad]), $empresaSlug, 'administration', 'abastecimiento');
    }

    public function broadcastAs(): string
    {
        return 'abastecimiento.updated';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("module.{$this->empresaSlug}.administration.abastecimiento")];
    }
}
