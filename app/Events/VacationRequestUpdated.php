<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento para broadcasting de solicitudes de vacaciones
 * Se emite cuando se crea, actualiza o cancela una solicitud
 */
class VacationRequestUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'vacation-request.updated';
    }

    public function broadcastOn(): array
    {
        $channels = [];

        // Canal del módulo RH para que los administradores vean en tiempo real
        if ($this->enterprise) {
            $channels[] = new PrivateChannel("module.{$this->enterprise}.administration.rh");
        }

        // Canal del empleado específico si viene en los datos
        if (isset($this->data['employee_id'])) {
            $channels[] = new PrivateChannel("employee.{$this->data['employee_id']}.vacations");
        }

        return $channels;
    }
}
