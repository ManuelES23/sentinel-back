<?php

namespace App\Mail\Planeacion;

use App\Services\Planeacion\ExportadorProgramaExcel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * El programa semanal de Planeación por correo: un solo .xlsx adjunto con una
 * hoja por semana. Quien lo manda pone en `to` el remitente del sistema y a los
 * destinatarios en copia oculta. El cuerpo nunca lleva direcciones.
 *
 * Recibe los bytes del libro (no una ruta): el archivo temporal ya se borró
 * antes de enviar.
 */
final class ProgramaSemanalMail extends Mailable
{
    /**
     * @param  string  $contenidoExcel  Bytes del .xlsx.
     * @param  list<string>  $semanas  Etiquetas «WK48 · 23–29 nov».
     * @param  string  $remitente  Nombre de quien lo manda.
     */
    public function __construct(
        public string $contenidoExcel,
        public string $nombreArchivo,
        public string $asunto,
        public array $semanas,
        public ?string $cultivo,
        public ?string $nota,
        public string $remitente,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asunto);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.programa-semanal', with: [
            'semanas' => $this->semanas,
            'cultivo' => $this->cultivo,
            'nota' => $this->nota,
            'remitente' => $this->remitente,
            'nombreArchivo' => $this->nombreArchivo,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->contenidoExcel, $this->nombreArchivo)
                ->withMime(ExportadorProgramaExcel::TIPO_MIME),
        ];
    }
}
