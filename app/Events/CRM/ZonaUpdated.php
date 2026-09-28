<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Zonas del CRM.
 * Emite en el canal module.{empresa}.crm.catalogos con nombre 'zona.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class ZonaUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'catalogos';

    public function broadcastAs(): string
    {
        return 'zona.updated';
    }
}
