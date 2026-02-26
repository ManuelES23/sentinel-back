<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de broadcast para Productores
 */
class ProductorUpdated extends ModelBroadcastEvent
{
    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'productor.updated';
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.{$this->module}"),
        ];
    }
}
