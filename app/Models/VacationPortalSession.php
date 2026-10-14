<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesión del portal de vacaciones (spec §3.3). Sin Loggable a propósito: cada
 * petición del portal toca last_activity_at y llenaría activity_logs de ruido.
 */
class VacationPortalSession extends Model
{
    protected $fillable = [
        'employee_id', 'token_hash', 'ip', 'user_agent',
        'last_activity_at', 'expires_at', 'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** Excluye empleados borrados (SoftDeletes): una sesión de un borrado queda sin empleado. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
