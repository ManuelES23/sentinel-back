<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Bodegas del CRM.
 * Emite en el canal module.{empresa}.crm.catalogos con nombre 'bodega.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class BodegaUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'catalogos';

    public function broadcastAs(): string
    {
        return 'bodega.updated';
    }
}
