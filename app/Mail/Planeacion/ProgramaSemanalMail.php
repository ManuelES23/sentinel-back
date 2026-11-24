<?php

namespace App\Mail\Planeacion;

use App\Services\Planeacion\ExportadorProgramaExcel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * El programa semanal de Planeación por correo: una hoja resumen en PNG por
 * semana (dentro del cuerpo y adjunta) y, si se pide, el .xlsx completo con
 * una hoja por semana. Quien lo manda pone en `to` el remitente del sistema y
 * a los destinatarios en copia oculta. El cuerpo nunca lleva direcciones.
 *
 * Recibe los bytes de los archivos (no rutas): el .xlsx temporal ya se borró
 * antes de enviar.
 */
final class ProgramaSemanalMail extends Mailable
{
    /**
     * @param  string|null  $contenidoExcel  Bytes del .xlsx, o null si no se adjunta.
     * @param  string|null  $nombreArchivo  Nombre del .xlsx, o null si no se adjunta.
     * @param  list<string>  $semanas  Etiquetas «WK48 · 23–29 nov».
     * @param  string  $remitente  Nombre de quien lo manda.
     * @param  list<array{nombre: string, png: string, resumen: array}>  $imagenes  Una por semana, en el orden de $semanas.
     */
    public function __construct(
        public ?string $contenidoExcel,
        public ?string $nombreArchivo,
        public string $asunto,
        public array $semanas,
        public ?string $cultivo,
        public ?string $nota,
        public string $remitente,
        public array $imagenes = [],
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
            'imagenes' => $this->imagenes,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $adjuntos = array_map(
            fn (array $i) => Attachment::fromData(fn () => $i['png'], $i['nombre'])->withMime('image/png'),
            $this->imagenes,
        );
        if ($this->contenidoExcel !== null && $this->nombreArchivo !== null) {
            $adjuntos[] = Attachment::fromData(fn () => $this->contenidoExcel, $this->nombreArchivo)
                ->withMime(ExportadorProgramaExcel::TIPO_MIME);
        }

        return $adjuntos;
    }
}
