<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un paso de la cadena congelada de una solicitud de vacaciones. */
class VacationRequestApproval extends Model
{
    public const WAITING = 'waiting';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SKIPPED = 'skipped';

    protected $fillable = [
        'vacation_request_id', 'step_order', 'description', 'rules', 'is_rh_final',
        'decision', 'decided_by', 'comment', 'decided_at',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'rules' => 'array',
        'is_rh_final' => 'boolean',
        'decided_at' => 'datetime',
    ];

    public function vacationRequest(): BelongsTo
    {
        return $this->belongsTo(VacationRequest::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
