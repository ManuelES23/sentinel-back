<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cuenta de Outlook vinculada por quien autoriza vacaciones (una por usuario).
 * Sin Loggable a propósito: guarda tokens. access_token y refresh_token van
 * cifrados (cast encrypted) y ocultos en $hidden; nunca deben llegar al
 * frontend, a la bitácora ni a los logs.
 */
class VacationOutlookConnection extends Model
{
    protected $table = 'vacation_outlook_connections';

    protected $fillable = [
        'user_id',
        'email_outlook',
        'microsoft_user_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'ultimo_sync_at',
        'ultimo_error',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'ultimo_sync_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(VacationOutlookEvent::class, 'vacation_outlook_connection_id');
    }
}
