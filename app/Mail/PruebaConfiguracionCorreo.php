<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PruebaConfiguracionCorreo extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Correo de prueba — '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.prueba-configuracion');
    }
}
