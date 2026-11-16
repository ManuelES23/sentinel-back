<?php

namespace App\Services\RH;

use App\Events\VacationRequestUpdated;
use App\Mail\RH\VacationReviewMail;
use App\Models\ApprovalFlowStep;
use App\Models\User;
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
        private InformadosVacaciones $informados,
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
            if (! $paso->is_rh_final && ! $paso->is_rh_review) {
                $aviso->withAction('/profile?tab=approvals', 'Ver solicitud');
            }
            $aviso->vacation('Solicitud de vacaciones por aprobar', $texto);
        }

        // Quien está informado del paso (p. ej. la asistente del director) se entera, sin que se le pida nada.
        // Si además puede aprobarlo, ya recibió el aviso de aprobador.
        // Sin informados (o si no se pueden consultar) el resto de los avisos sigue igual.
        $informados = collect();
        $aprobador = '';
        $this->aislado('informados del paso', function () use ($solicitud, $paso, $elegibles, &$informados, &$aprobador) {
            $informados = $this->informados->informadosDelPaso($solicitud, $paso)
                ->reject(fn (User $usuario) => $elegibles->contains('id', $usuario->id))
                ->values();
            $aprobador = $informados->isEmpty() ? '' : $this->nombresDeAprobadores($paso);
        });
        foreach ($informados as $usuario) {
            $this->aislado('aviso interno al informado', fn () => NotificationService::toUser($usuario)
                ->icon('Info', 'blue')
                ->withAction('/profile?tab=approvals', 'Ver solicitud')
                ->vacation('Solicitud de vacaciones para tu información', "{$empleado->full_name} solicitó {$solicitud->days_requested} día(s) de vacaciones del "
                    ."{$solicitud->start_date->format('d/m/Y')} al {$solicitud->end_date->format('d/m/Y')} y está esperando a {$aprobador}."));
        }

        foreach ($this->destinatariosDeCorreo($solicitud, $paso, $elegibles, $informados, $aprobador) as $correo => [$nombre, $via, $quien]) {
            $this->aislado('correo al aprobador', fn () => $this->correo->avisarAprobador($solicitud, $correo, $nombre, $via, $quien));
        }
    }

    /**
     * Nombres de los usuarios de las reglas «Usuario específico» activas del paso, unidos con « o ».
     * Si no hay ninguno (regla sin usuario), «quien autoriza este paso»: el texto nunca queda «está esperando a .».
     */
    private function nombresDeAprobadores(VacationRequestApproval $paso): string
    {
        $nombres = ApprovalFlowStep::with('user:id,name')
            ->whereIn('id', $paso->rules ?? [])
            ->where('is_active', true)
            ->where('approver_type', ApprovalFlowStep::TYPE_USER)
            ->get()
            ->map(fn (ApprovalFlowStep $regla) => $regla->user?->name)
            ->filter()
            ->unique()
            ->implode(' o ');

        return $nombres !== '' ? $nombres : 'quien autoriza este paso';
    }

    /**
     * A quién se le manda correo en el paso, una vez por dirección: cada revisor del portal (jefe o director; su correo de
     * empleado o, si no tiene, el de su usuario), cada usuario elegible que no sea ese mismo jefe y, al final, cada persona
     * informada del paso (solo informativo; si ya recibe el de aprobador, ese gana).
     *
     * @param  Collection<int, User>  $informados
     * @return array<string, array{0: ?string, 1: string, 2: ?string}> correo => [nombre, vía, aprobador (solo los informados)]
     */
    private function destinatariosDeCorreo(VacationRequest $solicitud, VacationRequestApproval $paso, Collection $elegibles, Collection $informados, string $aprobador): array
    {
        $destinos = [];
        $usuariosDeRevisores = [];

        foreach ($this->aprobador->revisoresDelPortal($solicitud, $paso) as $revisor) {
            $correo = CorreoVacaciones::correoValido($revisor->email) ?? CorreoVacaciones::correoValido($revisor->user?->email);
            if ($revisor->user_id !== null) {
                $usuariosDeRevisores[] = (int) $revisor->user_id;
            }
            if ($correo !== null) {
                $destinos[mb_strtolower($correo)] ??= [$revisor->first_name, $revisor->user_id !== null ? VacationReviewMail::VIA_PORTAL_O_SENTINEL : VacationReviewMail::VIA_PORTAL, null];
            }
        }

        foreach ($elegibles as $usuario) {
            $correo = CorreoVacaciones::correoValido($usuario->email);
            if ($correo === null || in_array((int) $usuario->id, $usuariosDeRevisores, true)) {
                continue;
            }
            $destinos[mb_strtolower($correo)] ??= [$usuario->name, ($paso->is_rh_final || $paso->is_rh_review) ? VacationReviewMail::VIA_RH : VacationReviewMail::VIA_SENTINEL, null];
        }

        // Los informados van al final: si la misma dirección ya es de un aprobador, gana el correo de aprobador.
        foreach ($informados as $usuario) {
            $correo = CorreoVacaciones::correoValido($usuario->email);
            if ($correo !== null) {
                $destinos[mb_strtolower($correo)] ??= [$usuario->name, VacationReviewMail::VIA_INFORMADO, $aprobador];
            }
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
