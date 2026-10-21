<?php

namespace App\Services\RH;

use App\Models\Employee;
use App\Models\VacationBalance;
use App\Models\VacationHistoryEntry;
use App\Models\VacationRequest;
use App\Services\VacationCalculatorService;
use Carbon\Carbon;

/**
 * Saldo de vacaciones de todos los empleados (pestaña Saldos de RH) con las mismas cuentas
 * que VacationCalculatorService::diasDisponibles, pero en tres consultas agrupadas en vez
 * de tres por empleado. Entran los activos y los que están de permiso.
 */
class SaldosVacaciones
{
    public function todos(?int $empresaId = null): array
    {
        $empleados = Employee::whereIn('status', [Employee::STATUS_ACTIVE, Employee::STATUS_ON_LEAVE])
            ->when($empresaId, fn ($q) => $q->where('enterprise_id', $empresaId))
            ->with(['enterprise:id,name', 'department:id,name', 'position:id,name'])
            ->orderBy('first_name')->orderBy('last_name')
            ->get();
        $ids = $empleados->pluck('id');

        $solicitudes = VacationRequest::whereIn('employee_id', $ids)
            ->whereIn('status', ['approved', 'pending'])
            ->selectRaw('employee_id, status, SUM(days_requested) as dias')
            ->groupBy('employee_id', 'status')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($filas) => $filas->pluck('dias', 'status'));
        $historial = VacationHistoryEntry::whereIn('employee_id', $ids)
            ->selectRaw('employee_id, SUM(days) as dias')->groupBy('employee_id')->pluck('dias', 'employee_id');
        $ajustes = VacationBalance::whereIn('employee_id', $ids)
            ->selectRaw('employee_id, SUM(adjustment_days) as dias')->groupBy('employee_id')->pluck('dias', 'employee_id');

        $hoy = now();

        return $empleados->map(function (Employee $empleado) use ($solicitudes, $historial, $ajustes, $hoy) {
            $ingreso = Carbon::parse($empleado->hire_date);
            $anios = max(0, (int) $ingreso->diffInYears($hoy));
            $acumulado = VacationCalculatorService::calculateTotalAccumulatedDays($ingreso, $hoy)['total_accumulated_days'];
            $porEstado = $solicitudes->get($empleado->id, collect());
            $delHistorial = (int) ($historial[$empleado->id] ?? 0);
            $tomados = (int) ($porEstado['approved'] ?? 0) + $delHistorial;
            $enRevision = (int) ($porEstado['pending'] ?? 0);
            $ajuste = (int) ($ajustes[$empleado->id] ?? 0);

            return [
                'id' => $empleado->id,
                'nombre' => $empleado->full_name,
                'numero' => $empleado->employee_number,
                'empresa' => $empleado->enterprise ? ['id' => $empleado->enterprise->id, 'nombre' => $empleado->enterprise->name] : null,
                'departamento' => $empleado->department ? ['id' => $empleado->department->id, 'nombre' => $empleado->department->name] : null,
                'puesto' => $empleado->position?->name,
                'fecha_ingreso' => $ingreso->toDateString(),
                'anios' => $anios,
                'le_corresponden' => $acumulado,
                'ajustes' => $ajuste,
                'tomados' => $tomados,
                'del_historial' => $delHistorial,
                'en_revision' => $enRevision,
                'disponibles' => $acumulado + $ajuste - $tomados - $enRevision,
                'proximo_aniversario' => [
                    'fecha' => $ingreso->copy()->addYears($anios + 1)->toDateString(),
                    'dias' => VacationCalculatorService::calculateEntitledDays($anios + 1),
                ],
            ];
        })->values()->all();
    }
}
