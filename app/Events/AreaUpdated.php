<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de Área actualizada
 * Se dispara cuando un área es creada, actualizada o eliminada
 */
class AreaUpdated extends ModelBroadcastEvent
{
    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'area.updated';
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
