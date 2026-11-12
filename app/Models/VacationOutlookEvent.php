<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mapeo solicitud de vacaciones -> evento de Outlook ya creado en la agenda de
 * quien autoriza. Sin Loggable (tabla técnica de sincronización).
 */
class VacationOutlookEvent extends Model
{
    protected $table = 'vacation_outlook_events';

    protected $fillable = [
        'vacation_outlook_connection_id',
        'vacation_request_id',
        'outlook_event_id',
        'enviado_at',
    ];

    protected $casts = [
        'enviado_at' => 'datetime',
    ];

    public function conexion(): BelongsTo
    {
        return $this->belongsTo(VacationOutlookConnection::class, 'vacation_outlook_connection_id');
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(VacationRequest::class, 'vacation_request_id')->withTrashed();
    }
}
