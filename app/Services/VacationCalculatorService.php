<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\VacationBalance;
use Carbon\Carbon;

/**
 * Servicio para cálculo de vacaciones según Ley Federal del Trabajo México
 * Reforma "Vacaciones Dignas" 2023
 * 
 * Tabla de días de vacaciones:
 * - 1 año: 12 días
 * - 2 años: 14 días
 * - 3 años: 16 días
 * - 4 años: 18 días
 * - 5 años: 20 días
 * - 6-10 años: 22 días
 * - 11-15 años: 24 días
 * - 16-20 años: 26 días
 * - 21-25 años: 28 días
 * - 26-30 años: 30 días
 * - 31-35 años: 32 días
 * - Por cada 5 años adicionales: +2 días
 */
class VacationCalculatorService
{
    /**
     * Tabla de días de vacaciones según años de servicio (LFT México 2023)
     */
    private const VACATION_TABLE = [
        1 => 12,
        2 => 14,
        3 => 16,
        4 => 18,
        5 => 20,
    ];

    /**
     * Después de 5 años, los días aumentan cada 5 años
     */
    private const DAYS_AFTER_5_YEARS = [
        [6, 10, 22],
        [11, 15, 24],
        [16, 20, 26],
        [21, 25, 28],
        [26, 30, 30],
        [31, 35, 32],
    ];

    /**
     * Calcular días de vacaciones según antigüedad
     */
    public static function calculateEntitledDays(int $yearsOfService): int
    {
        // Menos de 1 año = 0 días (se calculan proporcionales al cumplir el año)
        if ($yearsOfService < 1) {
            return 0;
        }

        // 1-5 años: según tabla fija
        if ($yearsOfService <= 5) {
            return self::VACATION_TABLE[$yearsOfService];
        }

        // 6+ años: según rangos
        foreach (self::DAYS_AFTER_5_YEARS as [$min, $max, $days]) {
            if ($yearsOfService >= $min && $yearsOfService <= $max) {
                return $days;
            }
        }

        // Más de 35 años: 32 días + 2 por cada 5 años adicionales
        $extraYears = $yearsOfService - 35;
        $extraPeriods = (int) ceil($extraYears / 5);
        return 32 + ($extraPeriods * 2);
    }

    /**
     * Calcular días proporcionales para empleados con menos de 1 año
     * (para cuando cumplan su primer aniversario)
     */
    public static function calculateProportionalDays(Carbon $hireDate, ?Carbon $endDate = null): array
    {
        $endDate = $endDate ?? now();
        $monthsWorked = $hireDate->diffInMonths($endDate);
        
        // Días proporcionales = (12 días / 12 meses) * meses trabajados
        $proportionalDays = round(($monthsWorked / 12) * 12, 1);
        
        return [
            'months_worked' => $monthsWorked,
            'proportional_days' => $proportionalDays,
            'full_year_days' => 12,
            'note' => 'Los días proporcionales se otorgan al cumplir el primer año de servicio',
        ];
    }

    /**
     * Calcular el total de días de vacaciones acumulados desde el ingreso
     * Suma los días de cada año completado según la tabla LFT
     */
    public static function calculateTotalAccumulatedDays(Carbon $hireDate, ?Carbon $referenceDate = null): array
    {
        $referenceDate = $referenceDate ?? now();
        $yearsCompleted = $hireDate->diffInYears($referenceDate);
        
        $accumulated = [];
        $totalDays = 0;
        
        for ($year = 1; $year <= $yearsCompleted; $year++) {
            $daysForYear = self::calculateEntitledDays($year);
            $anniversaryDate = $hireDate->copy()->addYears($year);
            
            $accumulated[] = [
                'year' => $year,
                'anniversary_date' => $anniversaryDate->format('Y-m-d'),
                'entitled_days' => $daysForYear,
            ];
            
            $totalDays += $daysForYear;
        }
        
        return [
            'years_completed' => $yearsCompleted,
            'total_accumulated_days' => $totalDays,
            'breakdown' => $accumulated,
        ];
    }

    /**
     * Obtener información completa de vacaciones para un empleado
     */
    public static function getEmployeeVacationInfo(Employee $employee): array
    {
        if (!$employee->hire_date) {
            return [
                'error' => 'El empleado no tiene fecha de ingreso registrada',
                'years_of_service' => 0,
                'entitled_days' => 0,
            ];
        }

        $hireDate = Carbon::parse($employee->hire_date);
        $now = now();
        $yearsOfService = $hireDate->diffInYears($now);
        $monthsOfService = $hireDate->diffInMonths($now) % 12;
        $daysOfService = $hireDate->diffInDays($now);

        // Calcular próximo aniversario
        $nextAnniversary = $hireDate->copy()->year($now->year);
        if ($nextAnniversary->isPast()) {
            $nextAnniversary->addYear();
        }
        $daysUntilAnniversary = $now->diffInDays($nextAnniversary, false);

        // *** CÁLCULO ACUMULADO DE TODOS LOS AÑOS ***
        $accumulatedData = self::calculateTotalAccumulatedDays($hireDate, $now);
        $totalAccumulatedDays = $accumulatedData['total_accumulated_days'];
        
        // Días que le corresponden según LFT para el AÑO ACTUAL
        $currentYearEntitledDays = self::calculateEntitledDays($yearsOfService);
        
        // Días del próximo período (cuando cumpla otro año)
        $nextYearEntitledDays = self::calculateEntitledDays($yearsOfService + 1);

        // Obtener todos los balances del empleado para calcular días usados totales
        $allBalances = VacationBalance::where('employee_id', $employee->id)->get();
        $totalUsedDays = $allBalances->sum('used_days');
        $totalPendingDays = $allBalances->sum('pending_days');
        $totalAdjustmentDays = $allBalances->sum('adjustment_days');

        // Obtener balance del año actual
        $currentYear = $now->year;
        $currentBalance = VacationBalance::where('employee_id', $employee->id)
            ->where('year', $currentYear)
            ->with('adjustedByUser')
            ->first();

        // Días disponibles = Total acumulado + Ajustes - Usados - Pendientes
        $availableDays = $totalAccumulatedDays + $totalAdjustmentDays - $totalUsedDays - $totalPendingDays;

        // Información proporcional si tiene menos de 1 año
        $proportionalInfo = null;
        if ($yearsOfService < 1) {
            $proportionalInfo = self::calculateProportionalDays($hireDate);
        }

        return [
            'employee_id' => $employee->id,
            'employee_name' => $employee->full_name,
            'hire_date' => $hireDate->format('Y-m-d'),
            'years_of_service' => $yearsOfService,
            'months_of_service' => $monthsOfService,
            'total_days_of_service' => $daysOfService,
            'seniority_text' => self::formatSeniority($yearsOfService, $monthsOfService),
            
            // Fechas importantes
            'next_anniversary' => $nextAnniversary->format('Y-m-d'),
            'days_until_anniversary' => max(0, $daysUntilAnniversary),
            
            // Días de vacaciones según LFT (año actual)
            'entitled_days' => $currentYearEntitledDays,
            'next_year_entitled_days' => $nextYearEntitledDays,
            'days_increase_next_year' => $nextYearEntitledDays - $currentYearEntitledDays,
            
            // *** NUEVO: Días acumulados de TODOS los años ***
            'accumulated' => [
                'total_accumulated_days' => $totalAccumulatedDays,
                'total_used_days' => $totalUsedDays,
                'total_pending_days' => $totalPendingDays,
                'total_adjustment_days' => $totalAdjustmentDays,
                'total_available_days' => max(0, $availableDays),
                'breakdown' => $accumulatedData['breakdown'],
            ],
            
            // Balance del año actual (para compatibilidad)
            'current_balance' => [
                'year' => $currentYear,
                'entitled_days' => $currentYearEntitledDays,
                'carried_over' => $currentBalance?->carried_over ?? 0,
                'adjustment_days' => $currentBalance?->adjustment_days ?? 0,
                'adjustment_reason' => $currentBalance?->adjustment_reason,
                'adjusted_by' => $currentBalance?->adjustedByUser?->name,
                'adjusted_at' => $currentBalance?->adjusted_at?->toDateTimeString(),
                'used_days' => $currentBalance?->used_days ?? 0,
                'pending_days' => $currentBalance?->pending_days ?? 0,
                'available_days' => max(0, $availableDays), // Ahora muestra el total disponible
                'total_days' => $totalAccumulatedDays + $totalAdjustmentDays,
            ],
            
            // Info proporcional (si aplica)
            'proportional_info' => $proportionalInfo,
            
            // Tabla de referencia LFT
            'vacation_table' => self::getVacationTable(),
        ];
    }

    /**
     * Formatear antigüedad como texto
     */
    private static function formatSeniority(int $years, int $months): string
    {
        $parts = [];
        
        if ($years > 0) {
            $parts[] = $years . ' ' . ($years === 1 ? 'año' : 'años');
        }
        
        if ($months > 0 || empty($parts)) {
            $parts[] = $months . ' ' . ($months === 1 ? 'mes' : 'meses');
        }
        
        return implode(', ', $parts);
    }

    /**
     * Obtener tabla completa de vacaciones LFT
     */
    public static function getVacationTable(): array
    {
        return [
            ['years' => '1', 'days' => 12, 'description' => 'Primer año de servicio'],
            ['years' => '2', 'days' => 14, 'description' => 'Segundo año'],
            ['years' => '3', 'days' => 16, 'description' => 'Tercer año'],
            ['years' => '4', 'days' => 18, 'description' => 'Cuarto año'],
            ['years' => '5', 'days' => 20, 'description' => 'Quinto año'],
            ['years' => '6-10', 'days' => 22, 'description' => 'De 6 a 10 años'],
            ['years' => '11-15', 'days' => 24, 'description' => 'De 11 a 15 años'],
            ['years' => '16-20', 'days' => 26, 'description' => 'De 16 a 20 años'],
            ['years' => '21-25', 'days' => 28, 'description' => 'De 21 a 25 años'],
            ['years' => '26-30', 'days' => 30, 'description' => 'De 26 a 30 años'],
            ['years' => '31-35', 'days' => 32, 'description' => 'De 31 a 35 años'],
            ['years' => '36+', 'days' => '32+', 'description' => '+2 días por cada 5 años adicionales'],
        ];
    }

    /**
     * Inicializar balances de vacaciones para todos los empleados activos
     */
    public static function initializeAllBalances(int $year = null): array
    {
        $year = $year ?? now()->year;
        $results = [
            'success' => [],
            'errors' => [],
        ];

        $employees = Employee::active()->whereNotNull('hire_date')->get();

        /** @var Employee $employee */
        foreach ($employees as $employee) {
            try {
                $balance = VacationBalance::initializeForEmployee($employee, $year);
                $results['success'][] = [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->full_name,
                    'entitled_days' => $balance->entitled_days,
                ];
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->full_name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Recalcular balance para un empleado específico
     */
    public static function recalculateEmployeeBalance(Employee $employee, int $year = null): VacationBalance
    {
        $year = $year ?? now()->year;
        return VacationBalance::initializeForEmployee($employee, $year);
    }
}
