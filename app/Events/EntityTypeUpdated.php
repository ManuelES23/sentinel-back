<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de Tipo de Entidad actualizado
 * Se dispara cuando un tipo de entidad es creado, actualizado o eliminado
 */
class EntityTypeUpdated extends ModelBroadcastEvent
{
    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'entity-type.updated';
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
