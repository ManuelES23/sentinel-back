<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Loggable;

class WorkSchedule extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'name',
        'description',
        'monday_start',
        'monday_end',
        'tuesday_start',
        'tuesday_end',
        'wednesday_start',
        'wednesday_end',
        'thursday_start',
        'thursday_end',
        'friday_start',
        'friday_end',
        'saturday_start',
        'saturday_end',
        'sunday_start',
        'sunday_end',
        'late_tolerance_minutes',
        'early_departure_tolerance',
        'is_active',
    ];

    protected $casts = [
        'late_tolerance_minutes' => 'integer',
        'early_departure_tolerance' => 'integer',
        'is_active' => 'boolean',
    ];

    // Mapeo de días
    const DAYS = [
        0 => 'sunday',
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
    ];

    // ==================== RELACIONES ====================

    /**
     * Empresas que tienen asignado este horario (muchos a muchos)
     */
    public function enterprises()
    {
        return $this->belongsToMany(Enterprise::class, 'enterprise_work_schedule')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /**
     * Empleados con este horario asignado
     */
    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Horarios asignados a una empresa específica
     */
    public function scopeForEnterprise($query, $enterpriseId)
    {
        return $query->whereHas('enterprises', function ($q) use ($enterpriseId) {
            $q->where('enterprises.id', $enterpriseId);
        });
    }

    /**
     * Horarios NO asignados a una empresa específica
     */
    public function scopeNotForEnterprise($query, $enterpriseId)
    {
        return $query->whereDoesntHave('enterprises', function ($q) use ($enterpriseId) {
            $q->where('enterprises.id', $enterpriseId);
        });
    }

    // ==================== MÉTODOS ====================

    /**
     * Obtener el horario para un día específico
     */
    public function getScheduleForDay(int $dayOfWeek): ?array
    {
        $dayName = self::DAYS[$dayOfWeek] ?? null;
        
        if (!$dayName) {
            return null;
        }

        $startField = $dayName . '_start';
        $endField = $dayName . '_end';

        if ($this->$startField && $this->$endField) {
            return [
                'start' => $this->$startField,
                'end' => $this->$endField,
            ];
        }

        return null; // Día libre
    }

    /**
     * Obtener el horario de hoy
     */
    public function getTodaySchedule(): ?array
    {
        return $this->getScheduleForDay(now()->dayOfWeek);
    }

    /**
     * Verificar si es día laboral
     */
    public function isWorkDay(int $dayOfWeek): bool
    {
        return $this->getScheduleForDay($dayOfWeek) !== null;
    }

    /**
     * Calcular minutos de retardo
     * @param \DateTime|\Carbon\Carbon $checkInTime Hora de entrada (en UTC)
     * @param string $timezone Zona horaria para comparar (default: America/Mexico_City)
     */
    public function calculateLateMinutes($checkInTime, string $timezone = 'America/Mexico_City'): int
    {
        // Convertir a Carbon si es DateTime
        $checkIn = $checkInTime instanceof \Carbon\Carbon 
            ? $checkInTime->copy() 
            : \Carbon\Carbon::parse($checkInTime);
        
        // Convertir la hora UTC a la zona horaria local del empleado
        $localCheckIn = $checkIn->setTimezone($timezone);
        
        $schedule = $this->getScheduleForDay($localCheckIn->dayOfWeek);
        
        if (!$schedule) {
            return 0;
        }

        // Crear Carbon para la hora esperada en la misma zona horaria
        $expectedStart = \Carbon\Carbon::parse($schedule['start'], $timezone);
        $actualTime = \Carbon\Carbon::parse($localCheckIn->format('H:i:s'), $timezone);
        
        // Si llegó antes o a tiempo, no hay retardo
        if ($actualTime->lte($expectedStart)) {
            return 0;
        }
        
        $lateMinutes = (int) $expectedStart->diffInMinutes($actualTime);
        
        // Solo contar como retardo si excede la tolerancia
        if ($lateMinutes > $this->late_tolerance_minutes) {
            return $lateMinutes;
        }
        
        return 0;
    }

    /**
     * Obtener resumen del horario
     */
    public function getScheduleSummaryAttribute(): array
    {
        $summary = [];
        $dayNames = [
            'monday' => 'Lun',
            'tuesday' => 'Mar',
            'wednesday' => 'Mié',
            'thursday' => 'Jue',
            'friday' => 'Vie',
            'saturday' => 'Sáb',
            'sunday' => 'Dom',
        ];

        foreach ($dayNames as $day => $label) {
            $start = $this->{$day . '_start'};
            $end = $this->{$day . '_end'};
            
            if ($start && $end) {
                $summary[$label] = substr($start, 0, 5) . '-' . substr($end, 0, 5);
            } else {
                $summary[$label] = 'Libre';
            }
        }

        return $summary;
    }
}
