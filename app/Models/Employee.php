<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Traits\Loggable;

class Employee extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'enterprise_id',
        'employee_number',
        'first_name',
        'last_name',
        'second_last_name',
        'birth_date',
        'gender',
        'curp',
        'rfc',
        'nss',
        'email',
        'phone',
        'mobile',
        'emergency_contact',
        'emergency_phone',
        'address_street',
        'address_number',
        'address_interior',
        'address_colony',
        'address_city',
        'address_state',
        'address_zip',
        'address',
        'department_id',
        'position_id',
        'reports_to',
        'hire_date',
        'termination_date',
        'contract_type',
        'work_shift',
        'work_schedule_id',
        'salary',
        'payment_frequency',
        'qr_code',
        'pin',
        'status',
        'photo',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'hire_date' => 'date',
        'termination_date' => 'date',
        'salary' => 'decimal:2',
    ];

    protected $hidden = [
        'salary',
        'pin',
    ];

    protected $appends = ['full_name', 'photo_url'];

    // Constantes
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_ON_LEAVE = 'on_leave';
    const STATUS_TERMINATED = 'terminated';

    const CONTRACT_PERMANENT = 'permanent';
    const CONTRACT_TEMPORARY = 'temporary';
    const CONTRACT_CONTRACTOR = 'contractor';
    const CONTRACT_INTERN = 'intern';

    // ==================== RELACIONES ====================

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function vacationBalances()
    {
        return $this->hasMany(VacationBalance::class);
    }

    public function vacationRequests()
    {
        return $this->hasMany(VacationRequest::class);
    }

    public function currentVacationBalance()
    {
        return $this->hasOne(VacationBalance::class)->where('year', date('Y'));
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    public function supervisor()
    {
        return $this->belongsTo(Employee::class, 'reports_to');
    }

    public function subordinates()
    {
        return $this->hasMany(Employee::class, 'reports_to');
    }

    public function workSchedule()
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function managedDepartments()
    {
        return $this->hasMany(Department::class, 'manager_id');
    }

    // ==================== ACCESSORS ====================

    public function getFullNameAttribute(): string
    {
        $name = trim($this->first_name . ' ' . $this->last_name);
        if ($this->second_last_name) {
            $name .= ' ' . $this->second_last_name;
        }
        return $name;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        if ($this->photo) {
            return asset('storage/' . $this->photo);
        }
        return null;
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'active' => 'Activo',
            'inactive' => 'Inactivo',
            'on_leave' => 'En licencia',
            'terminated' => 'Baja',
            default => $this->status,
        };
    }

    public function getContractTypeLabelAttribute(): string
    {
        return match($this->contract_type) {
            'permanent' => 'Planta',
            'temporary' => 'Temporal',
            'contractor' => 'Por proyecto',
            'intern' => 'Practicante',
            default => $this->contract_type,
        };
    }

    public function getAgeAttribute(): ?int
    {
        if ($this->birth_date) {
            return $this->birth_date->age;
        }
        return null;
    }

    public function getSeniorityAttribute(): string
    {
        if (!$this->hire_date) {
            return '0 mes(es)';
        }
        
        $years = $this->hire_date->diffInYears(now());
        $months = $this->hire_date->diffInMonths(now()) % 12;
        
        if ($years > 0) {
            return $years . ' año(s), ' . $months . ' mes(es)';
        }
        return $months . ' mes(es)';
    }

    /**
     * Años completos de servicio (antigüedad)
     */
    public function getYearsOfServiceAttribute(): int
    {
        if (!$this->hire_date) {
            return 0;
        }
        return $this->hire_date->diffInYears(now());
    }

    /**
     * Fecha del próximo aniversario laboral
     */
    public function getNextAnniversaryAttribute(): ?\Carbon\Carbon
    {
        if (!$this->hire_date) {
            return null;
        }
        
        $anniversary = $this->hire_date->copy()->year(now()->year);
        if ($anniversary->isPast()) {
            $anniversary->addYear();
        }
        return $anniversary;
    }

    /**
     * Días de vacaciones que le corresponden según la LFT México
     */
    public function getEntitledVacationDaysAttribute(): int
    {
        return VacationBalance::calculateEntitledDays($this->years_of_service);
    }

    /**
     * Información completa de vacaciones
     */
    public function getVacationInfoAttribute(): array
    {
        $balance = $this->currentVacationBalance;
        $yearsOfService = $this->years_of_service;
        $entitledDays = VacationBalance::calculateEntitledDays($yearsOfService);
        
        return [
            'years_of_service' => $yearsOfService,
            'seniority' => $this->seniority,
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'next_anniversary' => $this->next_anniversary?->format('Y-m-d'),
            'entitled_days' => $entitledDays,
            'available_days' => $balance?->available_days ?? $entitledDays,
            'used_days' => $balance?->used_days ?? 0,
            'pending_days' => $balance?->pending_days ?? 0,
            'carried_over' => $balance?->carried_over ?? 0,
        ];
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForEnterprise($query, $enterpriseId)
    {
        return $query->where('enterprise_id', $enterpriseId);
    }

    public function scopeForDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    public function scopeSearch($query, $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('employee_number', 'like', "%{$term}%")
              ->orWhere('first_name', 'like', "%{$term}%")
              ->orWhere('last_name', 'like', "%{$term}%")
              ->orWhere('email', 'like', "%{$term}%")
              ->orWhere('curp', 'like', "%{$term}%");
        });
    }

    // ==================== MÉTODOS ====================

    /**
     * Genera un código de empleado único basado en UUID corto
     */
    public static function generateEmployeeNumber($enterpriseId): string
    {
        do {
            // Generar código único: 2 letras + 6 números aleatorios
            $letters = Str::upper(Str::random(2));
            $numbers = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $code = $letters . $numbers;
        } while (self::where('employee_number', $code)->withTrashed()->exists());
        
        return $code;
    }

    public static function generateQRCode(): string
    {
        do {
            $code = Str::upper(Str::random(12));
        } while (self::where('qr_code', $code)->exists());
        
        return $code;
    }

    public static function generatePIN(): string
    {
        do {
            $pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (self::where('pin', $pin)->exists());
        
        return $pin;
    }

    /**
     * Verificar si el empleado puede checar (entrada/salida)
     */
    public function canCheckIn(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Obtener el registro de asistencia de hoy
     */
    public function todayAttendance()
    {
        return $this->attendanceRecords()
            ->where('date', today())
            ->first();
    }

    /**
     * Verificar código QR
     */
    public static function findByQRCode(string $qrCode): ?self
    {
        return self::where('qr_code', $qrCode)
            ->where('status', self::STATUS_ACTIVE)
            ->first();
    }

    /**
     * Verificar PIN
     */
    public static function findByPIN(string $pin): ?self
    {
        return self::where('pin', $pin)
            ->where('status', self::STATUS_ACTIVE)
            ->first();
    }
}
