<?php

namespace App\Support\RH;

use App\Models\Employee;
use App\Models\VacationHistoryEntry;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\VacationCalculatorService;
use Illuminate\Support\Collection;

/**
 * Lo único que devuelve el portal de vacaciones (spec §3.5). Lista blanca
 * explícita: nunca se serializa un modelo completo, así que un campo nuevo en
 * employees o users no se filtra solo al portal.
 */
class PresentadorPortalVacaciones
{
    public static function empleado(Employee $empleado): array
    {
        $empleado->loadMissing(['enterprise:id,name', 'position:id,name', 'department:id,name']);

        return [
            'nombre' => $empleado->full_name,
            'numero' => $empleado->employee_number,
            'empresa' => $empleado->enterprise?->name,
            'puesto' => $empleado->position?->name,
            'departamento' => $empleado->department?->name,
            'fecha_ingreso' => $empleado->hire_date?->format('Y-m-d'),
        ];
    }

    public static function saldo(Employee $empleado): array
    {
        $info = VacationCalculatorService::getEmployeeVacationInfo($empleado);

        if (isset($info['error'])) {
            return [
                'disponibles' => 0, 'acumulados' => 0, 'ajustes' => 0, 'usados' => 0, 'de_historial' => 0, 'en_revision' => 0,
                'antiguedad' => null, 'anios_servicio' => 0, 'proximo_aniversario' => null,
                'dias_para_aniversario' => null, 'dias_proximo_periodo' => null, 'sin_fecha_ingreso' => true,
            ];
        }

        $acumulado = $info['accumulated'];

        return [
            'disponibles' => (int) $acumulado['total_available_days'],
            'acumulados' => (int) $acumulado['total_accumulated_days'],
            'ajustes' => (int) $acumulado['total_adjustment_days'],
            'usados' => (int) $acumulado['total_used_days'],
            // Parte de «usados» que viene del historial capturado por RH.
            'de_historial' => (int) ($acumulado['total_history_days'] ?? 0),
            'en_revision' => (int) $acumulado['total_pending_days'],
            'antiguedad' => $info['seniority_text'],
            'anios_servicio' => (int) $info['years_of_service'],
            'proximo_aniversario' => $info['next_anniversary'],
            'dias_para_aniversario' => (int) $info['days_until_anniversary'],
            'dias_proximo_periodo' => (int) $info['next_year_entitled_days'],
            'sin_fecha_ingreso' => false,
        ];
    }

    /**
     * Historial que capturó RH, del más reciente al más antiguo. Sin la nota: es
     * interna de RH y puede traer comentarios que no son para el empleado.
     *
     * @return list<array{id: int, anio: int, fecha_inicio: ?string, fecha_fin: ?string, dias: int}>
     */
    public static function historial(Employee $empleado): array
    {
        return VacationHistoryEntry::where('employee_id', $empleado->id)
            ->orderByDesc('vacation_year')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (VacationHistoryEntry $entrada) => [
                'id' => $entrada->id,
                'anio' => $entrada->vacation_year,
                'fecha_inicio' => $entrada->start_date?->format('Y-m-d'),
                'fecha_fin' => $entrada->end_date?->format('Y-m-d'),
                'dias' => $entrada->days,
            ])
            ->values()
            ->all();
    }

    /** Vista previa de calcular() sin la lista de días descontables (el portal solo muestra los excluidos). */
    public static function calculo(array $calculo): array
    {
        return [
            'dias_naturales' => $calculo['dias_naturales'],
            'dias_descontables' => $calculo['dias_descontables'],
            'excluidos' => $calculo['excluidos'],
            'disponibles' => $calculo['disponibles'],
            'disponibles_despues' => $calculo['disponibles_despues'],
            // Días que se adelantan del siguiente periodo y cuántos puede adelantar.
            'adelanto' => $calculo['adelanto'],
            'limite_adelanto' => $calculo['limite_adelanto'],
            'proximo_aniversario' => $calculo['proximo_aniversario'],
            'errores' => $calculo['errores'],
        ];
    }

    public static function resumen(VacationRequest $solicitud): array
    {
        $pasos = self::pasosVisibles($solicitud);
        $actual = $solicitud->status === 'pending' && $solicitud->current_step !== null
            ? $pasos->search(fn (VacationRequestApproval $paso) => $paso->step_order === $solicitud->current_step)
            : false;

        return [
            'id' => $solicitud->id,
            'folio' => self::folio($solicitud),
            'fecha_inicio' => $solicitud->start_date->format('Y-m-d'),
            'fecha_fin' => $solicitud->end_date->format('Y-m-d'),
            'dias' => (int) $solicitud->days_requested,
            'adelanto' => (int) $solicitud->advance_days,
            'motivo' => $solicitud->reason,
            'estado' => $solicitud->status,
            'motivo_rechazo' => $solicitud->status === 'rejected' ? $solicitud->rejection_reason : null,
            'solicitada_el' => $solicitud->created_at?->toIso8601String(),
            'paso_actual' => $actual === false ? null : $actual + 1,
            'total_pasos' => $pasos->count(),
            'revisor_actual' => $actual === false ? null : $pasos[$actual]->description,
        ];
    }

    /** Resumen + línea de tiempo. De quien decidió solo sale el nombre (spec §6.1). */
    public static function detalle(VacationRequest $solicitud): array
    {
        return self::resumen($solicitud) + [
            'pasos' => self::pasosVisibles($solicitud)->map(fn (VacationRequestApproval $paso) => [
                'orden' => $paso->step_order,
                'descripcion' => $paso->description,
                'decision' => $paso->decision,
                'decidido_por' => $paso->nombreDeQuienDecidio(),
                'decidido_el' => $paso->decided_at?->toIso8601String(),
                'comentario' => $paso->decision === VacationRequestApproval::REJECTED ? $paso->comment : null,
                'es_rh' => $paso->is_rh_final,
            ])->all(),
        ];
    }

    /**
     * Solicitud ajena que le toca revisar al jefe del portal (lista blanca): el solicitante, su
     * puesto y departamento, el periodo y el motivo. Nada de saldo, ingreso ni datos personales.
     */
    public static function paraRevisar(VacationRequest $solicitud): array
    {
        $solicitante = $solicitud->employee;
        $solicitante->loadMissing(['position:id,name', 'department:id,name']);

        return [
            'id' => $solicitud->id,
            'folio' => self::folio($solicitud),
            'solicitante' => $solicitante->full_name,
            'numero' => $solicitante->employee_number,
            'puesto' => $solicitante->position?->name,
            'departamento' => $solicitante->department?->name,
            'fecha_inicio' => $solicitud->start_date->format('Y-m-d'),
            'fecha_fin' => $solicitud->end_date->format('Y-m-d'),
            'dias' => (int) $solicitud->days_requested,
            // El jefe ve que es adelanto (no el saldo).
            'adelanto' => (int) $solicitud->advance_days,
            'motivo' => $solicitud->reason,
            'solicitada_el' => $solicitud->created_at?->toIso8601String(),
            'pasos' => self::pasosVisibles($solicitud)->map(fn (VacationRequestApproval $paso) => [
                'orden' => $paso->step_order,
                'descripcion' => $paso->description,
                'decision' => $paso->decision,
                'decidido_por' => $paso->nombreDeQuienDecidio(),
                'decidido_el' => $paso->decided_at?->toIso8601String(),
                'es_rh' => $paso->is_rh_final,
            ])->all(),
        ];
    }

    public static function formato(VacationRequest $solicitud): array
    {
        $empleado = $solicitud->employee;
        $empleado->loadMissing('enterprise');

        return [
            'folio' => self::folio($solicitud),
            'empresa' => [
                'nombre' => $empleado->enterprise?->name,
                'logo_url' => $empleado->enterprise?->logo_url,
            ],
            'empleado' => self::empleado($empleado),
            'solicitud' => [
                'solicitada_el' => $solicitud->created_at?->format('Y-m-d'),
                'anio_vacacional' => $solicitud->vacation_year,
                'fecha_inicio' => $solicitud->start_date->format('Y-m-d'),
                'fecha_fin' => $solicitud->end_date->format('Y-m-d'),
                'dias' => (int) $solicitud->days_requested,
                'motivo' => $solicitud->reason,
            ],
            // Un jefe del portal sin usuario no deja approved_by: el nombre sale del último paso aprobado.
            'aprobado_por' => $solicitud->approver?->name
                ?? $solicitud->approvals->where('decision', VacationRequestApproval::APPROVED)->last()?->nombreDeQuienDecidio(),
            'aprobado_el' => $solicitud->approved_at?->format('Y-m-d'),
        ];
    }

    /**
     * El paso final de RH solo se activa si todos los anteriores se saltan
     * (spec §5.4): se muestra únicamente si decidió o si es el que espera.
     */
    private static function pasosVisibles(VacationRequest $solicitud): Collection
    {
        return $solicitud->approvals
            ->reject(fn (VacationRequestApproval $paso) => $paso->is_rh_final
                && ! in_array($paso->decision, [VacationRequestApproval::APPROVED, VacationRequestApproval::REJECTED], true)
                && ! ($solicitud->status === 'pending' && $paso->step_order === $solicitud->current_step))
            ->values();
    }

    private static function folio(VacationRequest $solicitud): string
    {
        return 'VAC-'.str_pad((string) $solicitud->id, 6, '0', STR_PAD_LEFT);
    }
}
