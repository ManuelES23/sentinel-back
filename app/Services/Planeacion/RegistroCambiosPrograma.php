<?php

namespace App\Services\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Planeacion\ProgramaSemanal;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aviso en tiempo real de que el Programa semanal de una semana cambió.
 *
 * Sin junta ya no hay historial de cambios: la tabla `programa_cambios` se
 * queda sin uso hasta la limpieza posterior.
 *
 * Una sola instancia por petición (`Scoped`): los avisos de un mismo programa
 * dentro de una transacción se juntan en uno solo al confirmarla (copiar la
 * semana anterior con 40 renglones avisa una vez, no 40).
 */
#[Scoped]
class RegistroCambiosPrograma
{
    /** @var array<int, true> programa_id => aviso ya programado para el próximo commit */
    private array $pendientes = [];

    /**
     * Avisa a quien tenga abierta la semana. Si hay una transacción abierta,
     * el aviso sale al confirmarla, uno solo por programa aunque se pida
     * varias veces. Un Reverb caído (o un mensaje demasiado grande) nunca
     * convierte en 500 una escritura ya guardada: se anota y se sigue (mismo
     * criterio que `EtapaController::difundir()`).
     */
    public function emitir(ProgramaSemanal $programa): void
    {
        $lunes = $programa->lunes->toDateString();
        $programaId = $programa->id;

        if (isset($this->pendientes[$programaId])) {
            return;
        }
        $this->pendientes[$programaId] = true;

        // Si la transacción se revierte no sale aviso y el siguiente vuelve a programarse.
        DB::afterRollBack(function () use ($programaId) {
            unset($this->pendientes[$programaId]);
        });

        DB::afterCommit(function () use ($lunes, $programaId) {
            unset($this->pendientes[$programaId]);

            try {
                $pendiente = broadcast(new ProgramaSemanalUpdated($lunes, $programaId));
                // PendingBroadcast emite al destruirse; forzarlo aquí mantiene
                // cualquier excepción dentro del try.
                unset($pendiente);
            } catch (\Throwable $e) {
                Log::warning('Programa semanal: no se pudo emitir el evento en tiempo real', [
                    'programa_id' => $programaId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
