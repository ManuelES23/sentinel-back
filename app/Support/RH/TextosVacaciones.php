<?php

namespace App\Support\RH;

use App\Models\VacationNotification;
use App\Models\VacationRequest;

/**
 * Textos fijos de los avisos al empleado, para correo y WhatsApp. Van en código a
 * propósito (spec D11): las plantillas editables quedan para después.
 */
final class TextosVacaciones
{
    public static function eventoDeEstado(string $estado): string
    {
        return match ($estado) {
            'approved' => VacationNotification::EVENTO_APROBADA,
            'rejected' => VacationNotification::EVENTO_RECHAZADA,
            'cancelled' => VacationNotification::EVENTO_CANCELADA_POR_RH,
            default => VacationNotification::EVENTO_RECIBIDA,
        };
    }

    /** «del 02/11/2026 al 06/11/2026» */
    public static function periodo(VacationRequest $solicitud): string
    {
        return 'del '.$solicitud->start_date->format('d/m/Y').' al '.$solicitud->end_date->format('d/m/Y');
    }

    /** «5 días» / «1 día» */
    public static function dias(VacationRequest $solicitud): string
    {
        $n = (int) $solicitud->days_requested;

        return $n.' '.($n === 1 ? 'día' : 'días');
    }

    public static function asunto(string $evento): string
    {
        return match ($evento) {
            VacationNotification::EVENTO_APROBADA => 'Tus vacaciones fueron aprobadas',
            VacationNotification::EVENTO_RECHAZADA => 'Tu solicitud de vacaciones fue rechazada',
            VacationNotification::EVENTO_CANCELADA_POR_RH => 'Recursos Humanos canceló tu solicitud de vacaciones',
            default => 'Recibimos tu solicitud de vacaciones',
        };
    }

    public static function titulo(string $evento): string
    {
        return match ($evento) {
            VacationNotification::EVENTO_APROBADA => 'Vacaciones aprobadas',
            VacationNotification::EVENTO_RECHAZADA => 'Solicitud rechazada',
            VacationNotification::EVENTO_CANCELADA_POR_RH => 'Solicitud cancelada',
            default => 'Recibimos tu solicitud',
        };
    }

    /** @return array<int, string> */
    public static function lineas(VacationRequest $solicitud, string $evento): array
    {
        $base = 'Periodo: '.self::periodo($solicitud).' ('.self::dias($solicitud).' descontados).';

        return match ($evento) {
            VacationNotification::EVENTO_APROBADA => [$base, 'Tu solicitud fue aprobada. ¡Disfruta tu descanso!'],
            VacationNotification::EVENTO_RECHAZADA => [$base, 'Motivo del rechazo: '.($solicitud->rejection_reason ?: 'sin motivo registrado').'.'],
            VacationNotification::EVENTO_CANCELADA_POR_RH => [$base, 'Si tienes dudas, acércate a Recursos Humanos.'],
            default => [$base, 'Te avisaremos cuando tenga respuesta.'],
        };
    }

    public static function whatsapp(VacationRequest $solicitud, string $evento): string
    {
        $nombre = $solicitud->employee?->first_name ?: 'compañero';
        $periodo = self::periodo($solicitud);
        $dias = self::dias($solicitud);

        $cuerpo = match ($evento) {
            VacationNotification::EVENTO_APROBADA => "tu solicitud de vacaciones {$periodo} ({$dias}) fue APROBADA. ¡Disfruta tu descanso!",
            VacationNotification::EVENTO_RECHAZADA => "tu solicitud de vacaciones {$periodo} fue RECHAZADA. Motivo: "
                .($solicitud->rejection_reason ?: 'sin motivo registrado').'. Si tienes dudas, acércate a Recursos Humanos.',
            VacationNotification::EVENTO_CANCELADA_POR_RH => "tu solicitud de vacaciones {$periodo} fue CANCELADA. Si tienes dudas, acércate a Recursos Humanos.",
            default => "recibimos tu solicitud de vacaciones {$periodo} ({$dias}). Te avisaremos cuando tenga respuesta. Puedes revisar el estado en ".config('vacaciones.portal_url'),
        };

        return "Hola {$nombre}, {$cuerpo}";
    }
}
