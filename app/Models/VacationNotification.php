<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aviso al empleado (correo o WhatsApp) sobre una solicitud de vacaciones. Sin Loggable: es una bitácora en sí. */
class VacationNotification extends Model
{
    public const CANAL_EMAIL = 'email';

    public const CANAL_WHATSAPP = 'whatsapp';

    public const EVENTO_RECIBIDA = 'recibida';

    public const EVENTO_APROBADA = 'aprobada';

    public const EVENTO_RECHAZADA = 'rechazada';

    public const EVENTO_CANCELADA_POR_RH = 'cancelada_por_rh';

    public const EVENTOS = [
        self::EVENTO_RECIBIDA,
        self::EVENTO_APROBADA,
        self::EVENTO_RECHAZADA,
        self::EVENTO_CANCELADA_POR_RH,
    ];

    protected $fillable = ['vacation_request_id', 'channel', 'event', 'sent_by', 'sent_at', 'error'];

    protected $casts = ['sent_at' => 'datetime'];

    public function vacationRequest(): BelongsTo
    {
        return $this->belongsTo(VacationRequest::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
