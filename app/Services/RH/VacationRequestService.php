<?php

namespace App\Services\RH;

use App\Exceptions\RH\VacacionesException;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\VacationCalculatorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Punto único de las reglas de vacaciones (spec §4). Lo usan RH, el perfil,
 * la bandeja de pendientes y (plan 2) el portal del empleado.
 */
class VacationRequestService
{
    public const MAX_DIAS_NATURALES = 60;

    public const CANCELA_EMPLEADO = 'empleado';

    public const CANCELA_RH = 'rh';

    public function __construct(
        private WorkingDaysCalculator $dias,
        private CadenaAprobacionVacaciones $cadena,
        private AprobadorVacaciones $aprobador,
        private AsistenciaVacaciones $asistencia,
        private AvisosVacaciones $avisos,
    ) {}

    /**
     * Vista previa sin escribir. Opciones: omitir_antiguedad y permitir_pasado
     * (solo RH), excluir_id (al reprogramar, la propia solicitud no cuenta para
     * el saldo ni los empalmes).
     */
    public function calcular(Employee $empleado, Carbon $inicio, Carbon $fin, array $opciones = []): array
    {
        $inicio = $inicio->copy()->startOfDay();
        $fin = $fin->copy()->startOfDay();
        $resultado = [
            'dias_naturales' => 0, 'dias_descontables' => 0, 'descontables' => [], 'excluidos' => [],
            'disponibles' => 0, 'disponibles_despues' => 0, 'errores' => [],
        ];

        if ($fin->lt($inicio)) {
            $resultado['errores'][] = 'La fecha de fin debe ser igual o posterior a la de inicio.';

            return $resultado;
        }

        $resultado['dias_naturales'] = (int) $inicio->diffInDays($fin) + 1;
        if ($resultado['dias_naturales'] > self::MAX_DIAS_NATURALES) {
            $resultado['errores'][] = 'La solicitud no puede abarcar más de '.self::MAX_DIAS_NATURALES.' días naturales.';

            return $resultado;
        }

        if (empty($opciones['permitir_pasado']) && $inicio->lt(today())) {
            $resultado['errores'][] = 'La fecha de inicio no puede ser anterior a hoy.';
        }

        if (empty($opciones['omitir_antiguedad'])
            && (! $empleado->hire_date || (int) $empleado->hire_date->diffInYears($inicio) < 1)) {
            $resultado['errores'][] = 'Se requiere al menos 1 año de antigüedad para solicitar vacaciones.';
        }

        $calculo = $this->dias->calcular($empleado, $inicio, $fin);
        $resultado['descontables'] = $calculo['descontables'];
        $resultado['excluidos'] = $calculo['excluidos'];
        $resultado['dias_descontables'] = count($calculo['descontables']);
        if ($resultado['dias_descontables'] === 0) {
            $resultado['errores'][] = 'El periodo elegido no incluye días laborables.';
        }

        $excluirId = $opciones['excluir_id'] ?? null;
        $propia = $excluirId
            ? (int) VacationRequest::whereKey($excluirId)->where('employee_id', $empleado->id)->where('status', 'pending')->value('days_requested')
            : 0;
        $resultado['disponibles'] = VacationCalculatorService::diasDisponibles($empleado) + $propia;
        $resultado['disponibles_despues'] = $resultado['disponibles'] - $resultado['dias_descontables'];

        if ($resultado['dias_descontables'] > 0 && $resultado['disponibles_despues'] < 0) {
            $resultado['errores'][] = "No tiene suficientes días disponibles. Disponibles: {$resultado['disponibles']}, solicitados: {$resultado['dias_descontables']}.";
        }

        if ($this->seEmpalma($empleado, $inicio, $fin, $excluirId)) {
            $resultado['errores'][] = 'Ya existe una solicitud pendiente o aprobada que se empalma con esas fechas.';
        }

        return $resultado;
    }

    public function crear(Employee $empleado, Carbon $inicio, Carbon $fin, ?string $motivo, string $origen, ?User $capturadoPor = null, array $opciones = []): VacationRequest
    {
        $solicitud = DB::transaction(function () use ($empleado, $inicio, $fin, $motivo, $origen, $capturadoPor, $opciones) {
            $empleado = Employee::whereKey($empleado->id)->lockForUpdate()->firstOrFail();
            $calculo = $this->exigirSinErrores($this->calcular($empleado, $inicio, $fin, $opciones));

            $solicitud = VacationRequest::create([
                'employee_id' => $empleado->id,
                'enterprise_id' => $empleado->enterprise_id,
                'start_date' => $inicio->format('Y-m-d'),
                'end_date' => $fin->format('Y-m-d'),
                'days_requested' => $calculo['dias_descontables'],
                'reason' => $motivo,
                'vacation_year' => $inicio->year,
                'status' => 'pending',
                'source' => $origen,
                'created_by' => $capturadoPor?->id,
            ]);
            $this->cadena->construir($solicitud);

            return $solicitud;
        });

        $this->avisos->solicitudCreada($solicitud->fresh(['employee.enterprise']));

        return $solicitud->fresh(['employee', 'approvals']);
    }

    public function reprogramar(VacationRequest $solicitud, Carbon $inicio, Carbon $fin, ?string $motivo, array $opciones = []): VacationRequest
    {
        return DB::transaction(function () use ($solicitud, $inicio, $fin, $motivo, $opciones) {
            $solicitud = $this->bloquearPendiente($solicitud);
            $empleado = Employee::whereKey($solicitud->employee_id)->lockForUpdate()->firstOrFail();
            $calculo = $this->exigirSinErrores($this->calcular($empleado, $inicio, $fin, ['excluir_id' => $solicitud->id] + $opciones));

            $solicitud->update([
                'start_date' => $inicio->format('Y-m-d'),
                'end_date' => $fin->format('Y-m-d'),
                'days_requested' => $calculo['dias_descontables'],
                'reason' => $motivo ?? $solicitud->reason,
                'vacation_year' => $inicio->year,
            ]);

            return $solicitud->fresh(['employee', 'approvals']);
        });
    }

    public function cancelar(VacationRequest $solicitud, string $quien): VacationRequest
    {
        $solicitud = DB::transaction(function () use ($solicitud, $quien) {
            $solicitud = VacationRequest::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();
            $aprobada = $solicitud->status === 'approved';
            $permitido = $solicitud->status === 'pending'
                || ($quien === self::CANCELA_RH && $aprobada && $solicitud->start_date->isAfter(today()));

            if (! $permitido) {
                throw new VacacionesException($quien === self::CANCELA_RH
                    ? 'Solo se pueden cancelar solicitudes pendientes o aprobadas que aún no empiezan.'
                    : 'Solo puedes cancelar solicitudes que siguen en revisión.');
            }

            if ($aprobada) {
                $this->asistencia->revertir($solicitud);
            }
            $this->cadena->cerrarPendientes($solicitud, 'Solicitud cancelada');
            $solicitud->update(['status' => 'cancelled', 'current_step' => null]);

            return $solicitud;
        });

        $this->avisos->cancelada($solicitud->fresh(['employee.enterprise']));

        return $solicitud->fresh(['employee', 'approvals']);
    }

    public function aprobarPaso(VacationRequest $solicitud, User $usuario): VacationRequest
    {
        [$solicitud, $terminada] = DB::transaction(function () use ($solicitud, $usuario) {
            $solicitud = $this->bloquearPendiente($solicitud);
            $paso = $this->pasoDecidible($solicitud, $usuario, 'aprobar');
            $paso->update(['decision' => VacationRequestApproval::APPROVED, 'decided_by' => $usuario->id, 'decided_at' => now()]);

            $siguiente = $this->cadena->avanzar($solicitud);
            if ($siguiente === null) {
                $this->marcarAprobada($solicitud, $usuario);
            }

            return [$solicitud, $siguiente === null];
        });

        $solicitud = $solicitud->fresh(['employee.user', 'employee.enterprise', 'approvals']);
        $terminada ? $this->avisos->resuelta($solicitud) : $this->avisos->pasoAvanzado($solicitud);

        return $solicitud;
    }

    public function rechazar(VacationRequest $solicitud, User $usuario, string $motivo): VacationRequest
    {
        $motivo = $this->exigirMotivo($motivo);

        $solicitud = DB::transaction(function () use ($solicitud, $usuario, $motivo) {
            $solicitud = $this->bloquearPendiente($solicitud);
            $paso = $this->pasoDecidible($solicitud, $usuario, 'rechazar');
            $paso->update(['decision' => VacationRequestApproval::REJECTED, 'decided_by' => $usuario->id, 'decided_at' => now(), 'comment' => $motivo]);
            $this->cadena->cerrarPendientes($solicitud, 'Solicitud rechazada');
            $this->marcarRechazada($solicitud, $usuario, $motivo);

            return $solicitud;
        });

        $solicitud = $solicitud->fresh(['employee.user', 'employee.enterprise', 'approvals']);
        $this->avisos->resuelta($solicitud);

        return $solicitud;
    }

    /** RH (permiso `aprobar`) decide en cualquier paso; los pasos restantes se cierran. */
    public function resolverComoRh(VacationRequest $solicitud, User $usuario, string $decision, ?string $motivo = null): VacationRequest
    {
        if (! in_array($decision, [VacationRequestApproval::APPROVED, VacationRequestApproval::REJECTED], true)) {
            throw new VacacionesException('Decisión no válida.');
        }
        $motivo = $decision === VacationRequestApproval::REJECTED ? $this->exigirMotivo($motivo) : $motivo;

        $solicitud = DB::transaction(function () use ($solicitud, $usuario, $decision, $motivo) {
            $solicitud = $this->bloquearPendiente($solicitud);

            if (! $this->aprobador->esRh($usuario) || (int) $usuario->employee?->id === (int) $solicitud->employee_id) {
                throw new VacacionesException('No tiene permiso para resolver esta solicitud como RH.', 403);
            }

            $this->cadena->pasoActual($solicitud)?->update([
                'decision' => $decision,
                'decided_by' => $usuario->id,
                'decided_at' => now(),
                'comment' => 'Resuelto por RH'.($motivo ? ': '.$motivo : ''),
            ]);
            $this->cadena->cerrarPendientes($solicitud, 'Resuelto por RH');
            $solicitud->update(['resolved_by_rh' => true]);

            $decision === VacationRequestApproval::APPROVED
                ? $this->marcarAprobada($solicitud, $usuario)
                : $this->marcarRechazada($solicitud, $usuario, $motivo);

            return $solicitud;
        });

        $solicitud = $solicitud->fresh(['employee.user', 'employee.enterprise', 'approvals']);
        $this->avisos->resuelta($solicitud);

        return $solicitud;
    }

    private function pasoDecidible(VacationRequest $solicitud, User $usuario, string $accion): VacationRequestApproval
    {
        $paso = $this->cadena->pasoActual($solicitud);
        if (! $paso || ! $this->aprobador->puedeDecidir($usuario, $solicitud, $paso, $accion)) {
            throw new VacacionesException('No puede '.($accion === 'rechazar' ? 'rechazar' : 'aprobar').' el paso actual de esta solicitud.', 403);
        }

        return $paso;
    }

    private function marcarAprobada(VacationRequest $solicitud, User $usuario): void
    {
        $solicitud->update(['status' => 'approved', 'approved_by' => $usuario->id, 'approved_at' => now(), 'current_step' => null]);
        $this->asistencia->registrar($solicitud->fresh('employee'), $usuario);
    }

    private function marcarRechazada(VacationRequest $solicitud, User $usuario, string $motivo): void
    {
        $solicitud->update([
            'status' => 'rejected', 'approved_by' => $usuario->id, 'approved_at' => now(),
            'rejection_reason' => $motivo, 'current_step' => null,
        ]);
    }

    private function exigirMotivo(?string $motivo): string
    {
        $motivo = trim((string) $motivo);
        if ($motivo === '') {
            throw new VacacionesException('El motivo del rechazo es obligatorio.');
        }

        return mb_substr($motivo, 0, 500);
    }

    protected function bloquearPendiente(VacationRequest $solicitud): VacationRequest
    {
        $solicitud = VacationRequest::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();
        if ($solicitud->status !== 'pending') {
            throw new VacacionesException('Solo se pueden modificar o decidir solicitudes pendientes.');
        }

        return $solicitud;
    }

    private function exigirSinErrores(array $calculo): array
    {
        if ($calculo['errores'] !== []) {
            throw new VacacionesException($calculo['errores'][0]);
        }

        return $calculo;
    }

    private function seEmpalma(Employee $empleado, Carbon $inicio, Carbon $fin, ?int $excluirId): bool
    {
        return VacationRequest::where('employee_id', $empleado->id)
            ->whereIn('status', ['pending', 'approved'])
            ->when($excluirId, fn ($q) => $q->whereKeyNot($excluirId))
            ->whereDate('start_date', '<=', $fin->format('Y-m-d'))
            ->whereDate('end_date', '>=', $inicio->format('Y-m-d'))
            ->exists();
    }
}
