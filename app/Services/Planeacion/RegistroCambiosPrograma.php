<?php

namespace App\Services\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Planeacion\ProgramaCambio;
use App\Models\Planeacion\ProgramaSemanal;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Historial de cambios del Programa semanal y aviso en tiempo real.
 *
 * Solo se guardan cambios cuando el programa ya tiene junta registrada: antes
 * de la junta todo es borrador. El aviso sale siempre, haya junta o no.
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
     * Guarda el cambio (si hay junta registrada) y avisa a quien tenga abierta la semana.
     *
     * @param  string  $accion  creado | actualizado | borrado | activado | desactivado
     * @param  array<string, mixed>|null  $antes
     * @param  array<string, mixed>|null  $despues
     */
    public function registrar(ProgramaSemanal $programa, string $entidad, int $entidadId, string $accion, ?array $antes, ?array $despues): void
    {
        if ($programa->junta_registrada_at !== null) {
            ProgramaCambio::create([
                'programa_id' => $programa->id,
                'entidad' => $entidad,
                'entidad_id' => $entidadId,
                'accion' => $accion,
                'antes' => $antes,
                'despues' => $despues,
                'user_id' => Auth::id(),
                'created_at' => now(),
            ]);
        }

        $this->emitir($programa);
    }

    /**
     * Solo avisa: para escrituras que no son cambios del programa (p. ej.
     * registrar la junta). Si hay una transacción abierta, el aviso sale al
     * confirmarla, uno solo por programa aunque se pida varias veces. Un Reverb
     * caído (o un mensaje demasiado grande) nunca convierte en 500 una
     * escritura ya guardada: se anota y se sigue (mismo criterio que
     * `EtapaController::difundir()`).
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
