<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Regiones del CRM.
 * Emite en el canal module.{empresa}.crm.catalogos con nombre 'region.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class RegionUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'catalogos';

    public function broadcastAs(): string
    {
        return 'region.updated';
    }
}
