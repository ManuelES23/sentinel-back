<?php

namespace App\Services\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Planeacion\ProgramaCambio;
use App\Models\Planeacion\ProgramaSemanal;
use Illuminate\Support\Facades\Auth;

/**
 * Historial de cambios del Programa semanal y aviso en tiempo real.
 *
 * Solo se guardan cambios cuando el programa ya tiene junta registrada: antes
 * de la junta todo es borrador. El aviso sale siempre, haya junta o no.
 */
class RegistroCambiosPrograma
{
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

    /** Solo avisa: para escrituras que no son cambios del programa (p. ej. registrar la junta). */
    public function emitir(ProgramaSemanal $programa): void
    {
        ProgramaSemanalUpdated::dispatch($programa->lunes->toDateString(), $programa->id);
    }
}
