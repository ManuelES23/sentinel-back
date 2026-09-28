<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Clientes del CRM.
 * Emite en el canal module.{empresa}.crm.clientes con nombre 'cliente.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class ClienteUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'clientes';

    public function broadcastAs(): string
    {
        return 'cliente.updated';
    }
}
