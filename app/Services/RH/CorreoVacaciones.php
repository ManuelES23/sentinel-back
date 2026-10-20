<?php

namespace App\Services\RH;

use App\Jobs\EnviarCorreoVacacionesJob;
use App\Mail\RH\VacationReviewMail;
use App\Mail\RH\VacationStatusMail;
use App\Models\Employee;
use App\Models\VacationNotification;
use App\Models\VacationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Correo automático al empleado (spec §7.1). Nada de lo que pase aquí puede romper la
 * operación que lo disparó: un correo faltante o un SMTP caído se registra y se sigue.
 */
class CorreoVacaciones
{
    /** Se llama después de confirmar la transacción de la solicitud. Nunca lanza. */
    public function avisar(VacationRequest $solicitud, string $evento): void
    {
        try {
            $solicitud->loadMissing('employee');
            if ($this->direccion($solicitud) === null) {
                return;
            }

            DB::afterCommit(function () use ($solicitud, $evento) {
                try {
                    config('vacaciones.correo_en_cola')
                        ? EnviarCorreoVacacionesJob::dispatch($solicitud->id, $evento)
                        : $this->enviar($solicitud, $evento);
                } catch (Throwable $e) {
                    Log::warning('No se pudo despachar el correo de vacaciones', ['solicitud' => $solicitud->id, 'evento' => $evento, 'error' => $e->getMessage()]);
                }
            });
        } catch (Throwable $e) {
            Log::warning('No se pudo preparar el correo de vacaciones', ['solicitud' => $solicitud->id, 'evento' => $evento, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Aviso al jefe de departamento sin usuario (no ve la bandeja de Sentinel): tiene una solicitud
     * por revisar en el portal. Nunca lanza; un fallo solo queda en el log.
     */
    public function avisarJefe(VacationRequest $solicitud, Employee $jefe): void
    {
        try {
            $correo = trim((string) $jefe->email);
            if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $solicitud->loadMissing('employee');
            $envio = Mail::to($correo);
            $mensaje = new VacationReviewMail($solicitud, $jefe);
            config('vacaciones.correo_en_cola') ? $envio->queue($mensaje) : $envio->send($mensaje);
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar al jefe de departamento', ['solicitud' => $solicitud->id, 'jefe' => $jefe->id, 'error' => $e->getMessage()]);
        }
    }

    /** Envía y deja constancia en vacation_notifications (con el error, si hubo). */
    public function enviar(VacationRequest $solicitud, string $evento): void
    {
        $solicitud->loadMissing('employee');
        $correo = $this->direccion($solicitud);
        if ($correo === null) {
            return;
        }

        $error = null;
        try {
            Mail::to($correo)->send(new VacationStatusMail($solicitud, $evento));
        } catch (Throwable $e) {
            $error = $e->getMessage();
            Log::warning('No se pudo enviar el correo de vacaciones', ['solicitud' => $solicitud->id, 'evento' => $evento, 'error' => $error]);
        }

        VacationNotification::create([
            'vacation_request_id' => $solicitud->id,
            'channel' => VacationNotification::CANAL_EMAIL,
            'event' => $evento,
            'sent_by' => null,
            'sent_at' => now(),
            'error' => $error !== null ? mb_substr($error, 0, 500) : null,
        ]);
    }

    private function direccion(VacationRequest $solicitud): ?string
    {
        $correo = trim((string) $solicitud->employee?->email);

        return filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : null;
    }
}
