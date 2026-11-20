<?php

namespace App\Services\SplendidFarms;

use App\Models\SfAttendanceRecord;
use App\Models\SfEmployee;
use App\Models\SfPosition;
use App\Models\SfPositionAssignment;

/**
 * Pago de Nómina SF día por día.
 *
 * Cada día con asistencia (1, o 0.5 si es medio día) se paga según el puesto de
 * ese día:
 *  - con asignación guardada ese día → salario del grupo de ese puesto;
 *  - grupo con salario 0 (Empacadores) → destajo: $0 aquí, se paga por fuera y
 *    se cuenta en destajo_days;
 *  - sin asignación → salario diario del alta (comportamiento anterior);
 *  - sin asignación y sin salario en el alta → $0, contado en days_without_rate.
 *
 * El puesto fijo del alta de un permanente NO cuenta como asignación: sin fila
 * ese día se le paga con su salario del alta.
 */
class SfPayrollCalculator
{
    public const KIND_GROUP = 'grupo';
    public const KIND_ALTA = 'alta';
    public const KIND_PIECEWORK = 'destajo';
    public const KIND_NO_RATE = 'sin_tarifa';

    /**
     * @return array{amount: float, kind: string}
     */
    public function dailyPay(SfEmployee $employee, ?SfPosition $assignedPosition): array
    {
        if ($assignedPosition && $assignedPosition->group) {
            $salary = (float) $assignedPosition->group->salary;

            return $salary > 0
                ? ['amount' => $salary, 'kind' => self::KIND_GROUP]
                : ['amount' => 0.0, 'kind' => self::KIND_PIECEWORK];
        }

        $rate = $this->altaRate($employee);

        return $rate > 0
            ? ['amount' => $rate, 'kind' => self::KIND_ALTA]
            : ['amount' => 0.0, 'kind' => self::KIND_NO_RATE];
    }

    /** Salario diario del alta, con el respaldo por salario y frecuencia de siempre. */
    public function altaRate(SfEmployee $employee): float
    {
        $dailyRate = (float) ($employee->daily_rate ?? 0);
        if ($dailyRate <= 0 && $employee->salary) {
            $dailyRate = match ($employee->payment_frequency) {
                'weekly' => ((float) $employee->salary) / 7,
                'monthly' => ((float) $employee->salary) / 30,
                default => ((float) $employee->salary) / 15,
            };
        }

        return $dailyRate;
    }

    /**
     * Renglones de nómina de los empleados activos de la empresa en el periodo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(int $enterpriseId, string $startDate, string $endDate): array
    {
        $employees = SfEmployee::query()
            ->where('enterprise_id', $enterpriseId)
            ->where('status', SfEmployee::STATUS_ACTIVE)
            ->get();

        $ids = $employees->pluck('id');

        $records = SfAttendanceRecord::query()
            ->whereIn('sf_employee_id', $ids)
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->get()
            ->groupBy('sf_employee_id');

        $assignments = SfPositionAssignment::query()
            ->with('position.group')
            ->whereIn('sf_employee_id', $ids)
            ->whereDate('assignment_date', '>=', $startDate)
            ->whereDate('assignment_date', '<=', $endDate)
            ->get()
            ->keyBy(fn (SfPositionAssignment $a) => $a->sf_employee_id . '|' . $a->assignment_date->toDateString());

        $rows = [];

        foreach ($employees as $employee) {
            $effectiveDays = 0.0;
            $paidDays = 0.0;
            $piecework = 0.0;
            $withoutRate = 0.0;
            $gross = 0.0;

            foreach ($records->get($employee->id, collect()) as $record) {
                $weight = match ($record->status) {
                    'present', 'late', 'early_leave' => 1.0,
                    'half_day' => 0.5,
                    default => 0.0,
                };
                if ($weight <= 0) {
                    continue;
                }

                $assignment = $assignments->get($employee->id . '|' . $record->date->toDateString());
                $pay = $this->dailyPay($employee, $assignment?->position);

                $effectiveDays += $weight;
                $gross += $weight * $pay['amount'];

                match ($pay['kind']) {
                    self::KIND_PIECEWORK => $piecework += $weight,
                    self::KIND_NO_RATE => $withoutRate += $weight,
                    default => $paidDays += $weight,
                };
            }

            $rows[] = [
                'sf_employee_id' => $employee->id,
                'code' => $employee->code,
                'checker_key' => $employee->checker_key,
                'full_name' => $employee->full_name,
                'payment_frequency' => $employee->payment_frequency,
                'salary' => (float) ($employee->salary ?? 0),
                // Promedio de los días pagados por día (varía si cambió de puesto).
                'daily_rate' => round($paidDays > 0 ? $gross / $paidDays : $this->altaRate($employee), 4),
                'effective_days' => round($effectiveDays, 2),
                'destajo_days' => round($piecework, 2),
                'days_without_rate' => round($withoutRate, 2),
                'gross_pay' => round($gross, 2),
            ];
        }

        return $rows;
    }
}
