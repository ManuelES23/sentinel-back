<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

class LiquidacionConsignacionUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'liquidacion-consignacion.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.compras-agricolas"),
        ];
    }
}
