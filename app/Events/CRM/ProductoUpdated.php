<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Productos del CRM.
 * Emite en el canal module.{empresa}.crm.catalogos con nombre 'producto.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class ProductoUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'catalogos';

    public function broadcastAs(): string
    {
        return 'producto.updated';
    }
}
