<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Aviso de que el Programa semanal de una semana cambió.
 *
 * El canal es fijo, no sale de las cabeceras `X-*`: el programa solo existe en
 * Splendid Farms y el front escucha en el módulo `planeacion` de
 * `administration` (lo autoriza `ModuleChannel`).
 *
 * El payload es mínimo a propósito (Reverb rechaza mensajes de más de 10 KB):
 * `data.lunes` y `data.programa_id`. El front, si es su semana, vuelve a pedir
 * `GET planeacion/programa`.
 */
class ProgramaSemanalUpdated extends ModelBroadcastEvent
{
    public const ENTERPRISE = 'splendidfarms';
    public const APPLICATION = 'administration';
    public const MODULO = 'planeacion';

    public function __construct(string $lunes, int $programaId)
    {
        parent::__construct(
            'updated',
            ['lunes' => $lunes, 'programa_id' => $programaId],
            self::ENTERPRISE,
            self::APPLICATION,
            self::MODULO
        );
    }

    public function broadcastAs(): string
    {
        return 'programa.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(sprintf('module.%s.%s.%s', self::ENTERPRISE, self::APPLICATION, self::MODULO)),
        ];
    }
}
