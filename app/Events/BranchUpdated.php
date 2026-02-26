<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de Sucursal actualizada
 * Se dispara cuando una sucursal es creada, actualizada o eliminada
 */
class BranchUpdated extends ModelBroadcastEvent
{
    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'branch.updated';
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.organizacion"),
        ];
    }
}
