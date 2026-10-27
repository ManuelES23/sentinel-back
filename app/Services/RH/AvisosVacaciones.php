<?php

namespace App\Services\RH;

use App\Events\VacationRequestUpdated;
use App\Mail\RH\VacationReviewMail;
use App\Models\VacationNotification;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use App\Support\RH\TextosVacaciones;
use Throwable;

/**
 * Avisos de vacaciones: internos (aprobadores y empleado con usuario), tiempo real y
 * correo al empleado y a cada aprobador cuando le toca su paso. Se llaman DESPUÉS de
 * confirmar la transacción. El WhatsApp es manual: lo arma VacationAvisoController.
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
        $this->aislado('emitir (creada)', fn () => $this->emitir('created', $solicitud));
        $this->aislado('aprobadores (creada)', fn () => $this->avisarAprobadoresDelPasoActual($solicitud));
        $this->aislado('correo (recibida)', fn () => $this->correo->avisar($solicitud, VacationNotification::EVENTO_RECIBIDA));
    }

    /** Al empleado solo se le avisa si la canceló RH; si la canceló él mismo ya lo sabe. */
    public function cancelada(VacationRequest $solicitud, string $quien): void
    {
        $this->aislado('emitir (cancelada)', fn () => $this->emitir('cancelled', $solicitud));

        if ($quien === VacationRequestService::CANCELA_RH) {
            $this->aislado('correo (cancelada por RH)', fn () => $this->correo->avisar($solicitud, VacationNotification::EVENTO_CANCELADA_POR_RH));
        }
    }

    /** Cambió algo del paso actual (p. ej. se reprogramaron las fechas): sus aprobadores vuelven a enterarse. */
    public function pasoAvanzado(VacationRequest $solicitud): void
    {
        $this->aislado('emitir (paso avanzado)', fn () => $this->emitir('updated', $solicitud));
        $this->aislado('aprobadores (paso avanzado)', fn () => $this->avisarAprobadoresDelPasoActual($solicitud));
    }

    /** Un paso intermedio aprobó: avisa al siguiente aprobador y al empleado le dice quién la revisa ahora. */
    public function pasoAprobado(VacationRequest $solicitud): void
    {
        $this->pasoAvanzado($solicitud);
        $this->aislado('correo (en revisión)', fn () => $this->correo->avisar($solicitud, VacationNotification::EVENTO_EN_REVISION));
    }

    /** Ya estaba autorizada fuera del sistema: solo se refrescan las pantallas, sin correo ni avisos. */
    public function capturadaAutorizada(VacationRequest $solicitud): void
    {
        $this->aislado('emitir (ya autorizada)', fn () => $this->emitir('approved', $solicitud));
    }

    /** Aviso interno al empleado (si tiene usuario) de una solicitud aprobada o rechazada. */
    public function resuelta(VacationRequest $solicitud): void
    {
        $this->aislado('emitir (resuelta)', fn () => $this->emitir($solicitud->status, $solicitud));
        $this->aislado('correo (resuelta)', fn () => $this->correo->avisar($solicitud, TextosVacaciones::eventoDeEstado($solicitud->status)));
        $this->aislado('aviso interno al empleado', fn () => $this->avisarAlEmpleadoResuelta($solicitud));
    }

    private function avisarAlEmpleadoResuelta(VacationRequest $solicitud): void
    {
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

        $elegibles = $this->aprobador->elegiblesDelPaso($solicitud, $paso);
        foreach ($elegibles as $usuario) {
            $aviso = NotificationService::toUser($usuario)->icon('ClipboardCheck', 'amber')->high();
            if (! $paso->is_rh_final) {
                $aviso->withAction('/profile?tab=approvals', 'Ver solicitud');
            }
            $aviso->vacation('Solicitud de vacaciones por aprobar', $texto);
        }

        foreach ($this->destinatariosDeCorreo($solicitud, $paso, $elegibles) as $correo => [$nombre, $via]) {
            $this->aislado('correo al aprobador', fn () => $this->correo->avisarAprobador($solicitud, $correo, $nombre, $via));
        }
    }

    /**
     * A quién se le manda correo en el paso, una vez por dirección: el jefe del portal (su correo de
     * empleado o, si no tiene, el de su usuario) y cada usuario elegible que no sea ese mismo jefe.
     *
     * @return array<string, array{0: ?string, 1: string}> correo => [nombre, vía]
     */
    private function destinatariosDeCorreo(VacationRequest $solicitud, VacationRequestApproval $paso, Collection $elegibles): array
    {
        $destinos = [];
        $usuariosDeJefes = [];

        foreach ($this->aprobador->jefesDelPortal($solicitud, $paso) as $jefe) {
            $correo = CorreoVacaciones::correoValido($jefe->email) ?? CorreoVacaciones::correoValido($jefe->user?->email);
            if ($jefe->user_id !== null) {
                $usuariosDeJefes[] = (int) $jefe->user_id;
            }
            if ($correo !== null) {
                $destinos[mb_strtolower($correo)] ??= [$jefe->first_name, $jefe->user_id !== null ? VacationReviewMail::VIA_PORTAL_O_SENTINEL : VacationReviewMail::VIA_PORTAL];
            }
        }

        foreach ($elegibles as $usuario) {
            $correo = CorreoVacaciones::correoValido($usuario->email);
            if ($correo === null || in_array((int) $usuario->id, $usuariosDeJefes, true)) {
                continue;
            }
            $destinos[mb_strtolower($correo)] ??= [$usuario->name, $paso->is_rh_final ? VacationReviewMail::VIA_RH : VacationReviewMail::VIA_SENTINEL];
        }

        return $destinos;
    }

    /** Un aviso que falla (Reverb, notificaciones, correo) no debe romper la operación ya confirmada ni los demás avisos. */
    private function aislado(string $que, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning("Aviso de vacaciones falló: {$que}", ['error' => $e->getMessage()]);
        }
    }

    protected function emitir(string $accion, VacationRequest $solicitud): void
    {
        // RH corporativo atiende a todas las empresas: siempre se emite a su módulo, sin importar la empresa del empleado.
        broadcast(new VacationRequestUpdated(
            $accion,
            // Solo lo mínimo: el canal lo alcanza cualquiera con acceso al módulo, así que nada de datos personales.
            // employee_id es necesario: broadcastOn lo usa para el canal employee.{id}.vacations.
            [
                'id' => $solicitud->id,
                'employee_id' => $solicitud->employee_id,
                'status' => $solicitud->status,
                'current_step' => $solicitud->current_step,
            ],
            AprobadorVacaciones::EMPRESA_CORPORATIVA,
            'rh',
            'gestion'
        ))->toOthers();
    }
}
