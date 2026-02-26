<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de Cultivo actualizado
 * Se dispara cuando un cultivo es creado, actualizado o eliminado
 */
class CultivoUpdated extends ModelBroadcastEvent
{
    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'cultivo.updated';
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.agricola"),
        ];
    }
}
