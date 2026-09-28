<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Oportunidades del CRM.
 * Emite en el canal module.{empresa}.crm.oportunidades con nombre 'oportunidad.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class OportunidadUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'oportunidades';

    public function broadcastAs(): string
    {
        return 'oportunidad.updated';
    }
}
