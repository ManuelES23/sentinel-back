<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class VacationBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'year',
        'entitled_days',
        'used_days',
        'pending_days',
        'carried_over',
        'adjustment_days',
        'adjustment_reason',
        'adjusted_by',
        'adjusted_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'entitled_days' => 'integer',
        'used_days' => 'integer',
        'pending_days' => 'integer',
        'carried_over' => 'integer',
        'adjustment_days' => 'integer',
        'adjusted_at' => 'datetime',
    ];

    // ==================== RELACIONES ====================

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function adjustedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    // ==================== ACCESSORS ====================

    /**
     * Días disponibles = Días que le corresponden + Días anteriores + Ajuste - Días usados - Días pendientes
     */
    public function getAvailableDaysAttribute(): int
    {
        return $this->entitled_days 
             + $this->carried_over 
             + ($this->adjustment_days ?? 0)
             - $this->used_days 
             - $this->pending_days;
    }

    /**
     * Total de días del período (incluye ajuste)
     */
    public function getTotalDaysAttribute(): int
    {
        return $this->entitled_days + $this->carried_over + ($this->adjustment_days ?? 0);
    }

    // ==================== MÉTODOS ESTÁTICOS ====================

    /**
     * Calcular días de vacaciones del AÑO ACTUAL según antigüedad (LFT México)
     */
    public static function calculateEntitledDays(int $yearsOfService): int
    {
        // Tabla de días según LFT México (reforma "Vacaciones Dignas" 2023)
        return match(true) {
            $yearsOfService < 1 => 0,
            $yearsOfService === 1 => 12,
            $yearsOfService === 2 => 14,
            $yearsOfService === 3 => 16,
            $yearsOfService === 4 => 18,
            $yearsOfService === 5 => 20,
            $yearsOfService >= 6 && $yearsOfService <= 10 => 22,
            $yearsOfService >= 11 && $yearsOfService <= 15 => 24,
            $yearsOfService >= 16 && $yearsOfService <= 20 => 26,
            $yearsOfService >= 21 && $yearsOfService <= 25 => 28,
            $yearsOfService >= 26 && $yearsOfService <= 30 => 30,
            $yearsOfService >= 31 && $yearsOfService <= 35 => 32,
            default => 32 + (int)(($yearsOfService - 35) / 5) * 2,
        };
    }

    /**
     * Calcular días ACUMULADOS de vacaciones (suma de todos los años trabajados)
     * Año 1: 12, Año 2: 14, Año 3: 16, etc.
     */
    public static function calculateAccumulatedDays(int $yearsOfService): int
    {
        $total = 0;
        for ($year = 1; $year <= $yearsOfService; $year++) {
            $total += self::calculateEntitledDays($year);
        }
        return $total;
    }

    /**
     * Calcular años de servicio completos desde la fecha de ingreso
     * hasta una fecha de referencia (por defecto, hoy)
     */
    public static function calculateYearsOfService(Carbon $hireDate, ?Carbon $referenceDate = null): int
    {
        $referenceDate = $referenceDate ?? now();
        return $hireDate->diffInYears($referenceDate);
    }

    /**
     * Calcular el aniversario laboral para un año específico
     */
    public static function getAnniversaryDate(Carbon $hireDate, int $year): Carbon
    {
        return $hireDate->copy()->year($year);
    }

    /**
     * Verificar si el empleado ya cumplió su aniversario en el año especificado
     */
    public static function hasCompletedAnniversary(Carbon $hireDate, int $year): bool
    {
        $anniversary = self::getAnniversaryDate($hireDate, $year);
        return $anniversary->isPast() || $anniversary->isToday();
    }

    /**
     * Inicializar o actualizar el balance para un empleado
     * Calcula correctamente los días según el aniversario del empleado
     */
    public static function initializeForEmployee(Employee $employee, int $year): self
    {
        if (!$employee->hire_date) {
            throw new \Exception('El empleado no tiene fecha de ingreso registrada');
        }

        $hireDate = Carbon::parse($employee->hire_date);
        
        // Calcular años de servicio al final del año especificado
        // o al día de hoy si es el año actual
        $referenceDate = $year < now()->year 
            ? Carbon::create($year, 12, 31) 
            : now();
        
        // Años completos de servicio
        $yearsOfService = self::calculateYearsOfService($hireDate, $referenceDate);
        
        // Si aún no cumple 1 año, no tiene días de vacaciones
        $entitledDays = self::calculateEntitledDays($yearsOfService);

        // Buscar balance del año anterior para carried_over
        $previousBalance = self::where('employee_id', $employee->id)
            ->where('year', $year - 1)
            ->first();

        $carriedOver = 0;
        if ($previousBalance) {
            // Máximo 50% de los días no usados se pueden transferir
            $unusedDays = max(0, $previousBalance->available_days);
            $carriedOver = min($unusedDays, (int)($previousBalance->entitled_days * 0.5));
        }

        // Mantener el ajuste existente si lo hay
        $existingBalance = self::where('employee_id', $employee->id)
            ->where('year', $year)
            ->first();

        $adjustmentDays = $existingBalance?->adjustment_days ?? 0;
        $adjustmentReason = $existingBalance?->adjustment_reason;
        $adjustedBy = $existingBalance?->adjusted_by;
        $adjustedAt = $existingBalance?->adjusted_at;

        return self::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'year' => $year,
            ],
            [
                'entitled_days' => $entitledDays,
                'carried_over' => $carriedOver,
                'adjustment_days' => $adjustmentDays,
                'adjustment_reason' => $adjustmentReason,
                'adjusted_by' => $adjustedBy,
                'adjusted_at' => $adjustedAt,
            ]
        );
    }

    /**
     * Aplicar un ajuste manual al balance
     */
    public function applyAdjustment(int $days, string $reason, int $userId): self
    {
        $this->update([
            'adjustment_days' => $days,
            'adjustment_reason' => $reason,
            'adjusted_by' => $userId,
            'adjusted_at' => now(),
        ]);

        return $this->fresh();
    }
}
