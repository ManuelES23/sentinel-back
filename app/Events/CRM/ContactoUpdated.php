<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Contactos del CRM.
 * Emite en el canal module.{empresa}.crm.contactos con nombre 'contacto.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class ContactoUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'contactos';

    public function broadcastAs(): string
    {
        return 'contacto.updated';
    }
}
