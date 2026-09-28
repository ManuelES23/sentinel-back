<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Presupuestos del CRM.
 * Emite en el canal module.{empresa}.crm.presupuestos con nombre 'presupuesto.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class PresupuestoUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'presupuestos';

    public function broadcastAs(): string
    {
        return 'presupuesto.updated';
    }
}
