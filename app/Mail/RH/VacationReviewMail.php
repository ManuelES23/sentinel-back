<?php

namespace App\Mail\RH;

use App\Models\VacationRequest;
use App\Support\RH\TextosVacaciones;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a quien le toca revisar una solicitud de vacaciones. `via` dice dónde la decide:
 * en el portal (jefe sin usuario), en el portal o en Sentinel (jefe con usuario), en la
 * bandeja de Sentinel (otro aprobador con usuario) o en RH → Vacaciones (paso final de RH). Con `informado`
 * es solo un aviso para quien acompaña a quien decide: no pide ninguna acción.
 * Nunca lleva el PIN.
 */
class VacationReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public const VIA_PORTAL = 'portal';

    public const VIA_PORTAL_O_SENTINEL = 'portal_o_sentinel';

    public const VIA_SENTINEL = 'sentinel';

    public const VIA_RH = 'rh';

    /** Solo informativo (p. ej. la asistente del director): no decide ni tiene que hacer nada. */
    public const VIA_INFORMADO = 'informado';

    /** `aprobador`: a quién espera la solicitud; solo lo usa el aviso informativo. */
    public function __construct(
        public VacationRequest $solicitud,
        public ?string $nombre,
        public string $via = self::VIA_PORTAL,
        public ?string $aprobador = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->via === self::VIA_INFORMADO
            ? 'Solicitud de vacaciones para tu información'
            : 'Tienes una solicitud de vacaciones por revisar');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.vacaciones-por-revisar', with: [
            'nombre' => $this->nombre,
            'via' => $this->via,
            'aprobador' => $this->aprobador,
            'comoJefe' => in_array($this->via, [self::VIA_PORTAL, self::VIA_PORTAL_O_SENTINEL], true),
            'solicitante' => $this->solicitud->employee?->full_name,
            'periodo' => TextosVacaciones::periodo($this->solicitud),
            'dias' => TextosVacaciones::dias($this->solicitud),
            'motivo' => $this->solicitud->reason,
            'portalUrl' => config('vacaciones.portal_url'),
            'bandejaUrl' => config('vacaciones.sentinel_url').'/profile?tab=approvals',
            'sentinelUrl' => config('vacaciones.sentinel_url'),
        ]);
    }
}
