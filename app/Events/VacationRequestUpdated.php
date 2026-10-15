<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento para broadcasting de solicitudes de vacaciones.
 * Se emite cuando se crea, avanza, se resuelve o se cancela una solicitud.
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

        // Módulo donde RH escucha. Tiene que ser un módulo que exista (empresa → aplicación →
        // módulo): ModuleChannel solo autoriza esos, y quien tiene acceso al módulo puede entrar.
        if ($this->enterprise && $this->application && $this->module) {
            $channels[] = new PrivateChannel("module.{$this->enterprise}.{$this->application}.{$this->module}");
        }

        // Canal del empleado específico si viene en los datos
        if (isset($this->data['employee_id'])) {
            $channels[] = new PrivateChannel("employee.{$this->data['employee_id']}.vacations");
        }

        return $channels;
    }
}
