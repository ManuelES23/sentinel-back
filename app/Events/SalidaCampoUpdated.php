<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de Salida de Campo actualizada
 * Se dispara cuando una salida es creada, actualizada, eliminada o recibida
 */
class SalidaCampoUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'salida-campo.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.cosecha"),
        ];
    }
}
