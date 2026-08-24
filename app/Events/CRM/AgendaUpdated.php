<?php

namespace App\Events\CRM;

use App\Events\ModelBroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de broadcast para eventos de Agenda del CRM.
 * Emite en el canal module.{enterprise}.crm.agenda con nombre 'agenda.updated'.
 */
class AgendaUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'agenda.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.{$this->module}"),
        ];
    }
}
