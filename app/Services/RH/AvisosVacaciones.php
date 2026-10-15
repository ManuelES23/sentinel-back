<?php

namespace App\Services\RH;

use App\Events\VacationRequestUpdated;
use App\Models\VacationNotification;
use App\Models\VacationRequest;
use App\Services\NotificationService;
use App\Support\RH\TextosVacaciones;

/**
 * Avisos de vacaciones: internos (aprobadores y empleado con usuario), tiempo real y
 * correo al empleado. Se llaman DESPUÉS de confirmar la transacción. El WhatsApp es
 * manual: lo arma VacationAvisoController.
 */
class AvisosVacaciones
{
    public function __construct(
        private AprobadorVacaciones $aprobador,
        private CadenaAprobacionVacaciones $cadena,
        private CorreoVacaciones $correo,
    ) {}

    public function solicitudCreada(VacationRequest $solicitud): void
    {
        $this->emitir('created', $solicitud);
        $this->avisarAprobadoresDelPasoActual($solicitud);
        $this->correo->avisar($solicitud, VacationNotification::EVENTO_RECIBIDA);
    }

    /** Al empleado solo se le avisa si la canceló RH; si la canceló él mismo ya lo sabe. */
    public function cancelada(VacationRequest $solicitud, string $quien): void
    {
        $this->emitir('cancelled', $solicitud);

        if ($quien === VacationRequestService::CANCELA_RH) {
            $this->correo->avisar($solicitud, VacationNotification::EVENTO_CANCELADA_POR_RH);
        }
    }

    public function pasoAvanzado(VacationRequest $solicitud): void
    {
        $this->emitir('updated', $solicitud);
        $this->avisarAprobadoresDelPasoActual($solicitud);
    }

    /** Aviso interno al empleado (si tiene usuario) de una solicitud aprobada o rechazada. */
    public function resuelta(VacationRequest $solicitud): void
    {
        $this->emitir($solicitud->status, $solicitud);
        $this->correo->avisar($solicitud, TextosVacaciones::eventoDeEstado($solicitud->status));

        $usuario = $solicitud->employee?->user;
        if (! $usuario) {
            return;
        }

        $periodo = "del {$solicitud->start_date->format('d/m/Y')} al {$solicitud->end_date->format('d/m/Y')}";

        if ($solicitud->status === 'approved') {
            NotificationService::toUser($usuario)
                ->withAction('/profile', 'Ver mis vacaciones')
                ->vacation('¡Vacaciones aprobadas!', "Tu solicitud de vacaciones {$periodo} ({$solicitud->days_requested} días) fue aprobada.");

            return;
        }

        NotificationService::toUser($usuario)
            ->high()
            ->withAction('/profile', 'Ver detalles')
            ->vacation('Solicitud de vacaciones rechazada', "Tu solicitud {$periodo} fue rechazada. Motivo: {$solicitud->rejection_reason}");
    }

    protected function avisarAprobadoresDelPasoActual(VacationRequest $solicitud): void
    {
        $paso = $this->cadena->pasoActual($solicitud);
        if (! $paso) {
            return;
        }

        $empleado = $solicitud->employee;
        $texto = "{$empleado->full_name} solicitó {$solicitud->days_requested} día(s) de vacaciones del "
            ."{$solicitud->start_date->format('d/m/Y')} al {$solicitud->end_date->format('d/m/Y')}.";

        foreach ($this->aprobador->elegiblesDelPaso($solicitud, $paso) as $usuario) {
            $aviso = NotificationService::toUser($usuario)->icon('ClipboardCheck', 'amber')->high();
            if (! $paso->is_rh_final) {
                $aviso->withAction('/profile?tab=approvals', 'Ver solicitud');
            }
            $aviso->vacation('Solicitud de vacaciones por aprobar', $texto);
        }
    }

    protected function emitir(string $accion, VacationRequest $solicitud): void
    {
        // RH corporativo atiende a todas las empresas: siempre se emite a su módulo, sin importar la empresa del empleado.
        broadcast(new VacationRequestUpdated(
            $accion,
            $solicitud->toArray(),
            AprobadorVacaciones::EMPRESA_CORPORATIVA,
            'rh',
            'gestion'
        ))->toOthers();
    }
}
