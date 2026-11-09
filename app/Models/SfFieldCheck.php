<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SfFieldCheck extends Model
{
    use HasFactory, Loggable;

    public const TYPE_CHECK_IN = 'check_in';
    public const TYPE_CHECK_OUT = 'check_out';

    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_LOW_CONFIDENCE = 'low_confidence';
    public const STATUS_MISMATCH = 'mismatch';
    public const STATUS_NO_TEMPLATE = 'no_template';
    public const STATUS_MANUALLY_APPROVED = 'manually_approved';
    public const STATUS_REJECTED = 'rejected';

    public const REVIEW_REASON_DISPOSITIVO_REVOCADO = 'dispositivo_revocado';

    protected $fillable = [
        'enterprise_id',
        'client_uuid',
        'sf_employee_id',
        'checked_by_user_id',
        'type',
        'checked_at',
        'synced_at',
        'evidence_photo_path',
        'client_confidence',
        'server_confidence',
        'verification_status',
        'manual_override',
        'reviewed_by_user_id',
        'reviewed_at',
        'latitude',
        'longitude',
        'device_info',
        'clock_skew_seconds',
        'device_pairing_id',
        'rescued_by_user_id',
        'review_reason',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'synced_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'manual_override' => 'boolean',
        'device_info' => 'array',
        'client_confidence' => 'decimal:4',
        'server_confidence' => 'decimal:4',
    ];

    public function employee()
    {
        return $this->belongsTo(SfEmployee::class, 'sf_employee_id');
    }

    public function checkedBy()
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function rescuedBy()
    {
        return $this->belongsTo(User::class, 'rescued_by_user_id');
    }

    public function devicePairing()
    {
        return $this->belongsTo(DevicePairing::class, 'device_pairing_id');
    }
}
