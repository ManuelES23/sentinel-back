<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Vendedores del CRM.
 * Emite en el canal module.{empresa}.crm.catalogos con nombre 'vendedor.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class VendedorUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'catalogos';

    public function broadcastAs(): string
    {
        return 'vendedor.updated';
    }
}
