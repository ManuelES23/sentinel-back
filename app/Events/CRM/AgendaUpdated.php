<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Agenda del CRM.
 * Emite en el canal module.{empresa}.crm.agenda con nombre 'agenda.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class AgendaUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'agenda';

    public function broadcastAs(): string
    {
        return 'agenda.updated';
    }
}
