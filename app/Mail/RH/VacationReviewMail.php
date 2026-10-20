<?php

namespace App\Mail\RH;

use App\Models\Employee;
use App\Models\VacationRequest;
use App\Support\RH\TextosVacaciones;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

/** Aviso al jefe de departamento sin usuario de Sentinel: tiene una solicitud por revisar en el portal. Nunca lleva el PIN. */
class VacationReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public VacationRequest $solicitud, public Employee $jefe) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tienes una solicitud de vacaciones por revisar');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.vacaciones-por-revisar', with: [
            'nombre' => $this->jefe->first_name,
            'solicitante' => $this->solicitud->employee?->full_name,
            'periodo' => TextosVacaciones::periodo($this->solicitud),
            'dias' => TextosVacaciones::dias($this->solicitud),
            'portalUrl' => config('vacaciones.portal_url'),
        ]);
    }
}
