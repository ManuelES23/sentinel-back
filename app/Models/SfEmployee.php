<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SfEmployee extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'sf_employees';

    protected $fillable = [
        'enterprise_id',
        'code',
        'employee_type',
        'first_name',
        'last_name',
        'second_last_name',
        'birth_date',
        'gender',
        'marital_status',
        'curp',
        'rfc',
        'nss',
        'checker_key',
        'email',
        'phone',
        'mobile',
        'emergency_contact',
        'emergency_relationship',
        'emergency_phone',
        'address_street',
        'address_number',
        'address_interior',
        'address_colony',
        'address_city',
        'address_state',
        'address_zip',
        'department',
        'position',
        'work_location',
        'hire_date',
        'termination_date',
        'payment_frequency',
        'salary',
        'daily_rate',
        'weekly_hours',
        'weekly_schedule',
        'status',
        'photo',
        'notes',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'hire_date' => 'date',
        'termination_date' => 'date',
        'salary' => 'decimal:2',
        'daily_rate' => 'decimal:4',
        'weekly_hours' => 'decimal:2',
        'weekly_schedule' => 'array',
    ];

    protected $appends = ['full_name', 'photo_url'];

    // ── Constantes ──
    public const TYPE_PERMANENT = 'permanent';
    public const TYPE_TEMPORARY = 'temporary';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ON_LEAVE = 'on_leave';
    public const STATUS_TERMINATED = 'terminated';

    // ── Relaciones ──
    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function contracts()
    {
        return $this->hasMany(SfEmployeeContract::class)->orderByDesc('version');
    }

    public function attendanceRecords()
    {
        return $this->hasMany(SfAttendanceRecord::class)->orderByDesc('date');
    }

    public function activeContract()
    {
        return $this->hasOne(SfEmployeeContract::class)->where('status', 'active')->latestOfMany('version');
    }

    // ── Scopes ──
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForEnterprise($query, $enterpriseId)
    {
        return $query->where('enterprise_id', $enterpriseId);
    }

    public function scopeOfType($query, $type)
    {
        return $query->where('employee_type', $type);
    }

    public function scopeSearch($query, $term)
    {
        $like = "%{$term}%";
        return $query->where(function ($q) use ($like) {
            $q->where('first_name', 'like', $like)
              ->orWhere('last_name', 'like', $like)
              ->orWhere('second_last_name', 'like', $like)
              ->orWhere('code', 'like', $like)
              ->orWhere('curp', 'like', $like)
                            ->orWhere('rfc', 'like', $like)
                            ->orWhere('checker_key', 'like', $like);
        });
    }

    // ── Accessors ──
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
        return $this->photo ? asset('storage/' . $this->photo) : null;
    }

    // ── Helpers ──
    public static function generateCode(): string
    {
        $last = static::withTrashed()
            ->where('code', 'like', 'SFEMP-%')
            ->orderByRaw('CAST(SUBSTRING(code, 7) AS UNSIGNED) DESC')
            ->value('code');

        $next = $last ? ((int) substr($last, 6)) + 1 : 1;
        return 'SFEMP-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}
