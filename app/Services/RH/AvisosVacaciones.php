<?php

namespace App\Services\RH;

use App\Events\VacationRequestUpdated;
use App\Models\VacationRequest;
use App\Services\NotificationService;

/**
 * Avisos internos y tiempo real de vacaciones. Se llaman DESPUÉS de confirmar
 * la transacción. Correo y WhatsApp al empleado llegan en el plan 3.
 */
class AvisosVacaciones
{
    public function __construct(
        private AprobadorVacaciones $aprobador,
        private CadenaAprobacionVacaciones $cadena,
    ) {}

    public function solicitudCreada(VacationRequest $solicitud): void
    {
        $this->emitir('created', $solicitud);
        $this->avisarAprobadoresDelPasoActual($solicitud);
    }

    public function cancelada(VacationRequest $solicitud): void
    {
        $this->emitir('cancelled', $solicitud);
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
        broadcast(new VacationRequestUpdated(
            $accion,
            $solicitud->toArray(),
            $solicitud->employee?->enterprise?->slug ?? AprobadorVacaciones::EMPRESA_CORPORATIVA,
            'administration',
            'rh'
        ))->toOthers();
    }
}
