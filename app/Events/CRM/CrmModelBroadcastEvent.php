<?php

namespace App\Events\CRM;

use App\Events\ModelBroadcastEvent;
use App\Models\Enterprise;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Base de los eventos del CRM.
 *
 * El canal sale del registro que cambió (`empresa_id`) y de una constante por
 * evento, no de las cabeceras `X-*` que mandó el cliente: antes, una petición
 * con otro `X-Module-Slug` publicaba en un canal donde nadie escuchaba y la
 * pantalla se quedaba vieja sin ningún error visible.
 *
 * `MODULO` es el módulo en cuyo canal escucha el front, que no siempre coincide
 * con el submódulo: vendedores, zonas, regiones, bodegas y productos viven
 * todos en `catalogos`.
 */
abstract class CrmModelBroadcastEvent extends ModelBroadcastEvent
{
    protected const MODULO = '';

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(sprintf('module.%s.crm.%s', $this->empresaSlug(), static::MODULO)),
        ];
    }

    private function empresaSlug(): ?string
    {
        $empresaId = $this->data['empresa_id'] ?? null;

        if (! $empresaId) {
            // Payloads sin empresa (algún `deleted` recortado): queda la
            // cabecera, que es lo que se usaba antes en todos los casos.
            return $this->enterprise;
        }

        // Sin cache estático a propósito: una consulta por evento no se nota
        // al lado de la petición HTTP al servidor de websockets, y un cache de
        // proceso devolvería el slug de otra empresa en cuanto se reusara un id
        // (los tests lo destaparon en cuestión de minutos).
        return Enterprise::whereKey($empresaId)->value('slug');
    }
}
