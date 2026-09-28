<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Actividades del CRM.
 * Emite en el canal module.{empresa}.crm.actividades con nombre 'actividad.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class ActividadUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'actividades';

    public function broadcastAs(): string
    {
        return 'actividad.updated';
    }
}
