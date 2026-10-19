<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Altas, cambios y bajas de etapas (Plan de siembra, Etapas y las vistas que
 * eligen etapa en Operación Agrícola).
 *
 * El canal es fijo, no sale de las cabeceras `X-*`: las etapas solo existen en
 * Splendid Farms y el front escucha en el módulo `agricola` de
 * `operacion-agricola` (lo autoriza `ModuleChannel`).
 *
 * `data.etapas` siempre es una lista:
 * - `created`/`updated` con la etapa completa (relaciones incluidas) y
 *   `temporada_ids`, las temporadas donde su lote está activo; el front la
 *   agrega o la quita según la temporada que tiene abierta.
 * - `updated` con un parche (`id`, fechas de siembra y `updated_at`) en el
 *   registro de siembra en bloque: sin `temporada_ids`, solo se aplica a
 *   etapas que el front ya tiene.
 * - `deleted` con `id` y `lote_id`.
 */
class EtapaUpdated extends ModelBroadcastEvent
{
    public const ENTERPRISE = 'splendidfarms';
    public const APPLICATION = 'operacion-agricola';
    public const MODULO = 'agricola';

    /**
     * Reverb rechaza mensajes de más de 10 KB: los parches del registro en
     * bloque (hasta 100 etapas) se mandan en eventos de este tamaño.
     */
    public const PARCHES_POR_EVENTO = 40;

    public function __construct(string $action, array $etapas)
    {
        parent::__construct(
            $action,
            ['etapas' => array_values($etapas)],
            self::ENTERPRISE,
            self::APPLICATION,
            self::MODULO
        );
    }

    public function broadcastAs(): string
    {
        return 'etapa.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(sprintf('module.%s.%s.%s', self::ENTERPRISE, self::APPLICATION, self::MODULO)),
        ];
    }
}
