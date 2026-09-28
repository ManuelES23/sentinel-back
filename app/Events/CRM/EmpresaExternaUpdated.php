<?php

namespace App\Events\CRM;

/**
 * Evento de broadcast para Empresas externas del CRM.
 * Emite en el canal module.{empresa}.crm.empresas-externas con nombre 'empresa-externa.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class EmpresaExternaUpdated extends CrmModelBroadcastEvent
{
    protected const MODULO = 'empresas-externas';

    public function broadcastAs(): string
    {
        return 'empresa-externa.updated';
    }
}
