<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Cotizaciones del CRM.
 * Emite en el canal module.{empresa}.crm.cotizaciones con nombre 'cotizacion.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class CotizacionUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'cotizaciones';

    public function broadcastAs(): string
    {
        return 'cotizacion.updated';
    }
}
