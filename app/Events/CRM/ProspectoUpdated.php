<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Prospectos del CRM.
 * Emite en el canal module.{empresa}.crm.prospectos con nombre 'prospecto.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class ProspectoUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'prospectos';

    public function broadcastAs(): string
    {
        return 'prospecto.updated';
    }
}
