<?php

namespace App\Mail\RH;

use App\Models\VacationRequest;
use App\Support\RH\TextosVacaciones;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso al empleado sobre el estado de su solicitud (spec §7.1). Nunca lleva el PIN. */
class VacationStatusMail extends Mailable
{
    public function __construct(public VacationRequest $solicitud, public string $evento) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: TextosVacaciones::asunto($this->evento));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.vacaciones-estado', with: [
            'titulo' => TextosVacaciones::titulo($this->evento),
            'nombre' => $this->solicitud->employee?->first_name,
            'lineas' => TextosVacaciones::lineas($this->solicitud, $this->evento),
            'portalUrl' => config('vacaciones.portal_url'),
        ]);
    }
}
