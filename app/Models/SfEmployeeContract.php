<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SfEmployeeContract extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'sf_employee_contracts';

    protected $fillable = [
        'sf_employee_id',
        'code',
        'version',
        'contract_type',
        'start_date',
        'end_date',
        'snapshot_full_name',
        'snapshot_curp',
        'snapshot_rfc',
        'snapshot_nss',
        'snapshot_position',
        'snapshot_department',
        'snapshot_work_location',
        'snapshot_salary',
        'snapshot_daily_rate',
        'snapshot_weekly_hours',
        'snapshot_payment_frequency',
        'generated_at',
        'generated_by_user_id',
        'status',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'snapshot_salary' => 'decimal:2',
        'snapshot_daily_rate' => 'decimal:4',
        'snapshot_weekly_hours' => 'decimal:2',
        'generated_at' => 'datetime',
        'version' => 'integer',
    ];

    protected $appends = [];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_TERMINATED = 'terminated';
    public const STATUS_ARCHIVED = 'archived';

    // ── Relaciones ──
    public function employee()
    {
        return $this->belongsTo(SfEmployee::class, 'sf_employee_id');
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    // ── Helpers ──
    public static function generateCode(): string
    {
        $last = static::withTrashed()
            ->where('code', 'like', 'CONT-%')
            ->orderByRaw('CAST(SUBSTRING(code, 6) AS UNSIGNED) DESC')
            ->value('code');

        $next = $last ? ((int) substr($last, 5)) + 1 : 1;
        return 'CONT-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}
