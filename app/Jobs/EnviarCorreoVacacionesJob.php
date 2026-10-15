<?php

namespace App\Jobs;

use App\Models\VacationRequest;
use App\Services\RH\CorreoVacaciones;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Envío del correo de vacaciones por la cola (solo con VACACIONES_CORREO_EN_COLA=true). */
class EnviarCorreoVacacionesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Un correo duplicado molesta más que uno perdido: no se reintenta (el error queda en la bitácora). */
    public int $tries = 1;

    public function __construct(public readonly int $solicitudId, public readonly string $evento) {}

    public function handle(CorreoVacaciones $correo): void
    {
        $solicitud = VacationRequest::with('employee.enterprise')->find($this->solicitudId);
        if (! $solicitud) {
            return;
        }

        $correo->enviar($solicitud, $this->evento);
    }
}
