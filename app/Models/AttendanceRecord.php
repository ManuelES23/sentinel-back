<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use App\Traits\Loggable;

class AttendanceRecord extends Model
{
    use HasFactory, Loggable;

    protected $fillable = [
        'employee_id',
        'date',
        'check_in',
        'check_out',
        'break_start',
        'break_end',
        'hours_worked',
        'overtime_hours',
        'status',
        'late_minutes',
        'early_leave_minutes',
        'check_in_method',
        'check_out_method',
        'check_in_device',
        'check_out_device',
        'notes',
        'justification',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'date' => 'date',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'break_start' => 'datetime',
        'break_end' => 'datetime',
        'hours_worked' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'late_minutes' => 'integer',
        'early_leave_minutes' => 'integer',
        'approved_at' => 'datetime',
    ];

    // Estados posibles
    const STATUS_PRESENT = 'present';
    const STATUS_ABSENT = 'absent';
    const STATUS_LATE = 'late';
    const STATUS_EARLY_LEAVE = 'early_leave';
    const STATUS_HALF_DAY = 'half_day';
    const STATUS_HOLIDAY = 'holiday';
    const STATUS_VACATION = 'vacation';
    const STATUS_SICK_LEAVE = 'sick_leave';
    const STATUS_PERSONAL_LEAVE = 'personal_leave';
    const STATUS_WORK_FROM_HOME = 'work_from_home';

    // ==================== RELACIONES ====================

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ==================== ACCESSORS ====================

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'present' => 'Presente',
            'absent' => 'Falta',
            'late' => 'Retardo',
            'early_leave' => 'Salida temprana',
            'half_day' => 'Medio día',
            'holiday' => 'Día festivo',
            'vacation' => 'Vacaciones',
            'sick_leave' => 'Incapacidad',
            'personal_leave' => 'Permiso',
            'work_from_home' => 'Home office',
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'present' => 'success',
            'absent' => 'error',
            'late' => 'warning',
            'early_leave' => 'warning',
            'half_day' => 'info',
            'holiday' => 'primary',
            'vacation' => 'primary',
            'sick_leave' => 'secondary',
            'personal_leave' => 'secondary',
            'work_from_home' => 'info',
            default => 'secondary',
        };
    }

    public function getCheckInTimeAttribute(): ?string
    {
        return $this->check_in ? $this->check_in->format('H:i') : null;
    }

    public function getCheckOutTimeAttribute(): ?string
    {
        return $this->check_out ? $this->check_out->format('H:i') : null;
    }

    // ==================== SCOPES ====================

    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeToday($query)
    {
        return $query->where('date', today());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('date', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('date', now()->month)
            ->whereYear('date', now()->year);
    }

    // ==================== MÉTODOS ====================

    /**
     * Registrar entrada
     */
    public static function checkIn(Employee $employee, string $method = 'qr', ?string $device = null): self
    {
        $today = today();
        
        // Buscar o crear registro del día
        $record = self::firstOrCreate(
            ['employee_id' => $employee->id, 'date' => $today],
            ['status' => self::STATUS_PRESENT]
        );

        if ($record->check_in) {
            throw new \Exception('Ya registraste tu entrada hoy');
        }

        $now = now();
        $record->check_in = $now;
        $record->check_in_method = $method;
        $record->check_in_device = $device;

        // Calcular retardo si tiene horario asignado
        if ($employee->workSchedule) {
            $lateMinutes = $employee->workSchedule->calculateLateMinutes($now);
            if ($lateMinutes > 0) {
                $record->late_minutes = $lateMinutes;
                $record->status = self::STATUS_LATE;
            }
        }

        $record->save();

        return $record;
    }

    /**
     * Registrar salida
     */
    public static function checkOut(Employee $employee, string $method = 'qr', ?string $device = null): self
    {
        $today = today();
        
        $record = self::where('employee_id', $employee->id)
            ->where('date', $today)
            ->first();

        if (!$record || !$record->check_in) {
            throw new \Exception('Primero debes registrar tu entrada');
        }

        if ($record->check_out) {
            throw new \Exception('Ya registraste tu salida hoy');
        }

        $now = now();
        $record->check_out = $now;
        $record->check_out_method = $method;
        $record->check_out_device = $device;

        // Calcular horas trabajadas
        $record->hours_worked = $record->check_in->diffInMinutes($now) / 60;

        // Detectar salida temprana si tiene horario
        if ($employee->workSchedule) {
            $schedule = $employee->workSchedule->getTodaySchedule();
            if ($schedule) {
                $expectedEnd = Carbon::parse($schedule['end']);
                $actualEnd = Carbon::parse($now->format('H:i:s'));
                
                $earlyMinutes = $expectedEnd->diffInMinutes($actualEnd, false);
                if ($earlyMinutes > 0) {
                    $record->early_leave_minutes = $earlyMinutes;
                    if ($record->status === self::STATUS_PRESENT) {
                        $record->status = self::STATUS_EARLY_LEAVE;
                    }
                }
            }
        }

        $record->save();

        return $record;
    }

    /**
     * Obtener resumen de asistencia para un empleado
     */
    public static function getSummaryForEmployee(int $employeeId, $startDate, $endDate): array
    {
        $records = self::where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        return [
            'total_days' => $records->count(),
            'present' => $records->where('status', self::STATUS_PRESENT)->count(),
            'late' => $records->where('status', self::STATUS_LATE)->count(),
            'absent' => $records->where('status', self::STATUS_ABSENT)->count(),
            'total_hours' => $records->sum('hours_worked'),
            'overtime_hours' => $records->sum('overtime_hours'),
            'total_late_minutes' => $records->sum('late_minutes'),
        ];
    }
}
