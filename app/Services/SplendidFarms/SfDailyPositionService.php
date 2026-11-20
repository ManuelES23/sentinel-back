<?php

namespace App\Services\SplendidFarms;

use App\Models\SfAttendanceRecord;
use App\Models\SfEmployee;
use App\Models\SfPosition;
use App\Models\SfPositionAssignment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puestos del día de Asistencia SF: una asignación por empleado y fecha en
 * sf_position_assignments (unique sf_employee_id + assignment_date).
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
     * Puestos activos de la empresa, con su grupo.
     *
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
     * Puesto fijo de cada permanente, por nombre (sin mayúsculas ni espacios extra).
     * Si dos puestos activos se llaman igual, gana el más antiguo (menor id).
     *
     * @param Collection<int, SfPosition> $positions
     * @return array<int, SfPosition> sf_employee_id => puesto
     */
    public function fixedPositions(Collection $employees, Collection $positions): array
    {
        $byName = [];
        foreach ($positions->sortBy('id') as $position) {
            $byName[$this->normalize($position->name)] ??= $position;
        }

        $fixed = [];
        foreach ($employees as $employee) {
            if ($employee->employee_type !== SfEmployee::TYPE_PERMANENT || ! $employee->position) {
                continue;
            }
            $position = $byName[$this->normalize($employee->position)] ?? null;
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
        $assignments = $this->assignmentsOn($employees->pluck('id'), $date);

        // Un puesto dado de baja después de asignarlo sigue en la lista (marcado) para
        // que el renglón muestre lo que se guardó y se pague con su grupo.
        $listed = $positions->keyBy('id');
        $retired = $assignments->pluck('position')->filter()
            ->reject(fn (SfPosition $p) => $listed->has($p->id))
            ->unique('id');

        return [
            'date' => $date,
            'positions' => $positions->map(fn (SfPosition $p) => $this->positionPayload($p, false))
                ->concat($retired->map(fn (SfPosition $p) => $this->positionPayload($p, true)))
                ->values()->all(),
            'employees' => $this->rowsFor($employees, $assignments, $date, $positions),
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
                $backToDefault = $position === null
                    || (isset($fixed[$employee->id]) && $fixed[$employee->id]->id === $position->id);

                if ($backToDefault) {
                    $this->onDate(SfPositionAssignment::query(), 'assignment_date', $date)
                        ->where('sf_employee_id', $employee->id)
                        ->delete();
                    continue;
                }

                $this->saveAssignment($employee->id, $date, [
                    'sf_position_id' => $position->id,
                    'assigned_at' => now(),
                    'assigned_by_user_id' => $userId,
                    'source_device' => 'puestos-del-dia',
                ]);
            }
        });

        $assignments = $this->assignmentsOn($employees->pluck('id'), $date);

        return $this->rowsFor($employees, $assignments, $date, $positions);
    }

    /**
     * Copia a los temporales activos sin puesto el puesto que tuvieron el día más
     * reciente en que algún temporal activo tuvo puesto activo (hasta
     * COPY_LOOKBACK_DAYS días antes). Los que ya tienen puesto no se tocan.
     *
     * @return array{copied: int, source_date: ?string}
     */
    public function copyPrevious(int $enterpriseId, string $date, ?int $userId): array
    {
        $from = Carbon::parse($date)->subDays(self::COPY_LOOKBACK_DAYS)->toDateString();

        $temporaries = SfEmployee::query()
            ->where('enterprise_id', $enterpriseId)
            ->where('status', SfEmployee::STATUS_ACTIVE)
            ->where('employee_type', SfEmployee::TYPE_TEMPORARY)
            ->pluck('id');

        $activePositionIds = $this->positions($enterpriseId)->pluck('id');

        // Mismo filtro para elegir el día y para copiar: así un día en que solo se
        // movió a permanentes no gana y deja la copia en cero.
        $candidates = fn () => SfPositionAssignment::query()
            ->whereIn('sf_employee_id', $temporaries)
            ->whereIn('sf_position_id', $activePositionIds)
            ->where('assignment_date', '>=', $from)
            ->where('assignment_date', '<', $date);

        $sourceDate = $candidates()->orderByDesc('assignment_date')->first()?->assignment_date?->toDateString();

        if (! $sourceDate) {
            return ['copied' => 0, 'source_date' => null];
        }

        $alreadyAssigned = $this->onDate(SfPositionAssignment::query(), 'assignment_date', $date)
            ->whereIn('sf_employee_id', $temporaries)
            ->pluck('sf_employee_id');

        $source = $this->onDate($candidates(), 'assignment_date', $sourceDate)
            ->whereNotIn('sf_employee_id', $alreadyAssigned)
            ->get();

        $copied = 0;
        foreach ($source as $previous) {
            try {
                SfPositionAssignment::create([
                    'sf_employee_id' => $previous->sf_employee_id,
                    'sf_position_id' => $previous->sf_position_id,
                    'assignment_date' => $date,
                    'assigned_at' => now(),
                    'assigned_by_user_id' => $userId,
                    'source_device' => 'puestos-del-dia:copia',
                ]);
                $copied++;
            } catch (UniqueConstraintViolationException) {
                // Alguien le asignó puesto mientras corría la copia: se respeta el suyo.
            }
        }

        return ['copied' => $copied, 'source_date' => $sourceDate];
    }

    /**
     * Guarda la asignación del día. Si dos peticiones llegan casi juntas para la
     * misma persona, la segunda encuentra la fila recién creada y la actualiza.
     *
     * @param array<string, mixed> $values
     */
    private function saveAssignment(int $employeeId, string $date, array $values): void
    {
        $find = fn () => $this->onDate(SfPositionAssignment::query(), 'assignment_date', $date)
            ->where('sf_employee_id', $employeeId)
            ->first();

        $existing = $find();
        if (! $existing) {
            try {
                SfPositionAssignment::create($values + [
                    'sf_employee_id' => $employeeId,
                    'assignment_date' => $date,
                ]);

                return;
            } catch (UniqueConstraintViolationException) {
                $existing = $find();
            }
        }

        $existing?->update($values);
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
     * @return Collection<int, SfPositionAssignment> por sf_employee_id
     */
    private function assignmentsOn(Collection $employeeIds, string $date): Collection
    {
        return $this->onDate(
            SfPayrollCalculator::withPositionAndGroup(SfPositionAssignment::query()),
            'assignment_date',
            $date
        )
            ->whereIn('sf_employee_id', $employeeIds)
            ->get()
            ->keyBy('sf_employee_id');
    }

    /**
     * @param Collection<int, SfEmployee> $employees
     * @param Collection<int, SfPositionAssignment> $assignments
     * @param Collection<int, SfPosition> $positions
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(Collection $employees, Collection $assignments, string $date, Collection $positions): array
    {
        $fixed = $this->fixedPositions($employees, $positions);

        $checkedIn = $this->onDate(SfAttendanceRecord::query(), 'date', $date)
            ->whereIn('sf_employee_id', $employees->pluck('id'))
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

    /**
     * @return array<string, mixed>
     */
    private function positionPayload(SfPosition $position, bool $retired): array
    {
        return [
            'id' => $position->id,
            'code' => $position->code,
            'name' => $position->name,
            'retired' => $retired,
            'group' => $position->group ? [
                'code' => $position->group->code,
                'name' => $position->group->name,
                'salary' => (float) $position->group->salary,
            ] : null,
        ];
    }

    /**
     * Filtra un día con [fecha, fecha + 1): usa el índice de la columna DATE en MySQL
     * y cubre el «Y-m-d 00:00:00» que guarda SQLite en las pruebas.
     */
    private function onDate(Builder $query, string $column, string $date): Builder
    {
        return $query
            ->where($column, '>=', $date)
            ->where($column, '<', Carbon::parse($date)->addDay()->toDateString());
    }

    private function normalize(?string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) $value)) ?? '');
    }
}
