<?php

namespace App\Services\SplendidFarms;

use App\Models\SfAttendanceRecord;
use App\Models\SfEmployee;
use App\Models\SfPosition;
use App\Models\SfPositionAssignment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puestos del día de Asistencia SF: una asignación por empleado y fecha en
 * sf_position_assignments.
 *
 * - Permanente: su puesto del alta (texto sf_employees.position, buscado por nombre
 *   en el catálogo) es su puesto por defecto. Solo se guarda fila si ese día se
 *   cambia; volver a su puesto fijo borra la fila.
 * - Temporal: sin puesto hasta que se le asigne; quitarlo borra la fila.
 *
 * El pago que se muestra sale de SfPayrollCalculator, igual que en Nómina SF.
 */
class SfDailyPositionService
{
    /** Días hacia atrás en que «copiar del día anterior» busca asignaciones. */
    public const COPY_LOOKBACK_DAYS = 7;

    public function __construct(private readonly SfPayrollCalculator $calculator)
    {
    }

    /**
     * @return Collection<int, SfPosition>
     */
    public function positions(int $enterpriseId): Collection
    {
        return SfPosition::query()
            ->with('group:id,code,name,salary')
            ->where('enterprise_id', $enterpriseId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'sf_position_group_id']);
    }

    /**
     * Puesto fijo de cada permanente, por nombre exacto (sin mayúsculas ni espacios extra).
     *
     * @param Collection<int, SfPosition> $positions
     * @return array<int, SfPosition> sf_employee_id => puesto
     */
    public function fixedPositions(Collection $employees, Collection $positions): array
    {
        $byName = $positions->keyBy(fn (SfPosition $p) => $this->normalize($p->name));

        $fixed = [];
        foreach ($employees as $employee) {
            if ($employee->employee_type !== SfEmployee::TYPE_PERMANENT || ! $employee->position) {
                continue;
            }
            $position = $byName->get($this->normalize($employee->position));
            if ($position) {
                $fixed[$employee->id] = $position;
            }
        }

        return $fixed;
    }

    /**
     * @return array{date: string, positions: array<int, array<string, mixed>>, employees: array<int, array<string, mixed>>}
     */
    public function sheet(int $enterpriseId, string $date): array
    {
        $positions = $this->positions($enterpriseId);
        $employees = $this->activeEmployees($enterpriseId);

        return [
            'date' => $date,
            'positions' => $positions->map(fn (SfPosition $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'group' => $p->group ? [
                    'code' => $p->group->code,
                    'name' => $p->group->name,
                    'salary' => (float) $p->group->salary,
                ] : null,
            ])->values()->all(),
            'employees' => $this->rowsFor($employees, $date, $positions),
        ];
    }

    /**
     * Asigna (o quita, con $positionId null) el puesto del día a varios empleados.
     *
     * @param array<int, int> $employeeIds
     * @return array<int, array<string, mixed>> renglones actualizados de esos empleados
     */
    public function assign(int $enterpriseId, string $date, array $employeeIds, ?int $positionId, ?int $userId): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        $employees = SfEmployee::query()
            ->where('enterprise_id', $enterpriseId)
            ->whereIn('id', $employeeIds)
            ->get();

        if ($employees->count() !== count($employeeIds)) {
            throw ValidationException::withMessages([
                'employee_ids' => 'Hay empleados que no pertenecen a la empresa.',
            ]);
        }

        $positions = $this->positions($enterpriseId);
        $position = null;
        if ($positionId !== null) {
            $position = $positions->firstWhere('id', $positionId);
            if (! $position) {
                throw ValidationException::withMessages([
                    'sf_position_id' => 'El puesto no existe o no está activo en la empresa.',
                ]);
            }
        }

        $fixed = $this->fixedPositions($employees, $positions);

        DB::transaction(function () use ($employees, $date, $position, $fixed, $userId) {
            foreach ($employees as $employee) {
                $existing = SfPositionAssignment::query()
                    ->where('sf_employee_id', $employee->id)
                    ->whereDate('assignment_date', $date)
                    ->first();

                $backToDefault = $position === null
                    || (isset($fixed[$employee->id]) && $fixed[$employee->id]->id === $position->id);

                if ($backToDefault) {
                    $existing?->delete();
                    continue;
                }

                $values = [
                    'sf_position_id' => $position->id,
                    'assigned_at' => now(),
                    'assigned_by_user_id' => $userId,
                    'source_device' => 'puestos-del-dia',
                ];

                if ($existing) {
                    $existing->update($values);
                } else {
                    SfPositionAssignment::create($values + [
                        'sf_employee_id' => $employee->id,
                        'assignment_date' => $date,
                    ]);
                }
            }
        });

        return $this->rowsFor($employees, $date, $positions);
    }

    /**
     * Copia a los temporales sin puesto el puesto que tuvieron el día más reciente
     * con asignaciones (hasta COPY_LOOKBACK_DAYS días antes).
     *
     * @return array{copied: int, source_date: ?string}
     */
    public function copyPrevious(int $enterpriseId, string $date, ?int $userId): array
    {
        $from = Carbon::parse($date)->subDays(self::COPY_LOOKBACK_DAYS)->toDateString();

        $sourceDate = SfPositionAssignment::query()
            ->whereHas('employee', fn ($q) => $q->where('enterprise_id', $enterpriseId))
            ->whereDate('assignment_date', '<', $date)
            ->whereDate('assignment_date', '>=', $from)
            ->orderByDesc('assignment_date')
            ->first()?->assignment_date?->toDateString();

        if (! $sourceDate) {
            return ['copied' => 0, 'source_date' => null];
        }

        $activePositionIds = $this->positions($enterpriseId)->pluck('id')->all();

        $temporaries = SfEmployee::query()
            ->where('enterprise_id', $enterpriseId)
            ->where('status', SfEmployee::STATUS_ACTIVE)
            ->where('employee_type', SfEmployee::TYPE_TEMPORARY)
            ->pluck('id');

        $alreadyAssigned = SfPositionAssignment::query()
            ->whereIn('sf_employee_id', $temporaries)
            ->whereDate('assignment_date', $date)
            ->pluck('sf_employee_id')
            ->all();

        $source = SfPositionAssignment::query()
            ->whereIn('sf_employee_id', $temporaries)
            ->whereNotIn('sf_employee_id', $alreadyAssigned)
            ->whereIn('sf_position_id', $activePositionIds)
            ->whereDate('assignment_date', $sourceDate)
            ->get();

        DB::transaction(function () use ($source, $date, $userId) {
            foreach ($source as $previous) {
                SfPositionAssignment::create([
                    'sf_employee_id' => $previous->sf_employee_id,
                    'sf_position_id' => $previous->sf_position_id,
                    'assignment_date' => $date,
                    'assigned_at' => now(),
                    'assigned_by_user_id' => $userId,
                    'source_device' => 'puestos-del-dia:copia',
                ]);
            }
        });

        return ['copied' => $source->count(), 'source_date' => $sourceDate];
    }

    /**
     * @return Collection<int, SfEmployee>
     */
    private function activeEmployees(int $enterpriseId): Collection
    {
        return SfEmployee::query()
            ->where('enterprise_id', $enterpriseId)
            ->where('status', SfEmployee::STATUS_ACTIVE)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    /**
     * @param Collection<int, SfEmployee> $employees
     * @param Collection<int, SfPosition> $positions
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(Collection $employees, string $date, Collection $positions): array
    {
        $ids = $employees->pluck('id');
        $fixed = $this->fixedPositions($employees, $positions);

        $assignments = SfPositionAssignment::query()
            ->with('position.group')
            ->whereIn('sf_employee_id', $ids)
            ->whereDate('assignment_date', $date)
            ->get()
            ->keyBy('sf_employee_id');

        $checkedIn = SfAttendanceRecord::query()
            ->whereIn('sf_employee_id', $ids)
            ->whereDate('date', $date)
            ->pluck('sf_employee_id')
            ->flip();

        return $employees->map(function (SfEmployee $employee) use ($assignments, $fixed, $checkedIn) {
            $assignment = $assignments->get($employee->id);
            $fixedPosition = $fixed[$employee->id] ?? null;

            return [
                'id' => $employee->id,
                'code' => $employee->code,
                'checker_key' => $employee->checker_key,
                'full_name' => $employee->full_name,
                'employee_type' => $employee->employee_type,
                'fixed_position_id' => $fixedPosition?->id,
                'position_id' => $assignment?->sf_position_id ?? $fixedPosition?->id,
                'source' => $assignment ? 'dia' : ($fixedPosition ? 'alta' : null),
                'pay' => $this->calculator->dailyPay($employee, $assignment?->position),
                'checked_in' => $checkedIn->has($employee->id),
            ];
        })->values()->all();
    }

    private function normalize(?string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) $value)) ?? '');
    }
}
