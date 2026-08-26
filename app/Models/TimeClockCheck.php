<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeClockCheck extends Model
{
    use HasFactory, Loggable;

    public const TYPE_CHECK_IN = 'check_in';
    public const TYPE_CHECK_OUT = 'check_out';

    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_LOW_CONFIDENCE = 'low_confidence';
    public const STATUS_NO_TEMPLATE = 'no_template';
    public const STATUS_MANUALLY_APPROVED = 'manually_approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'client_uuid',
        'employee_id',
        'type',
        'checked_at',
        'synced_at',
        'evidence_photo_path',
        'server_confidence',
        'verification_status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'latitude',
        'longitude',
        'device_info',
        'clock_skew_seconds',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'synced_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'device_info' => 'array',
        'server_confidence' => 'decimal:4',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
