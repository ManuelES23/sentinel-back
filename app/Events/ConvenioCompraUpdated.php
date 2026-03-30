<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

class ConvenioCompraUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'convenio-compra.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.compras-agricolas"),
        ];
    }
}
