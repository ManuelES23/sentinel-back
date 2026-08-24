<?php

namespace App\Console\Commands;

use App\Models\CRM\CrmAgenda;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recorre CrmAgenda::conRecordatorioPendiente() y notifica al usuario
 * ligado al vendedor de cada evento vía NotificationService. Idempotente:
 * cada evento procesado se marca con recordatorio_enviado_at = now(),
 * así que una corrida posterior ya no lo vuelve a tomar.
 *
 * Un vendedor sin user_id ligado no puede recibir una notificación
 * personal -- se marca como enviado igual (para no reintentarlo por
 * siempre) mas se deja constancia en el log.
 *
 * Un fallo al procesar un evento puntual (NotificationService lanza una
 * excepción) NO detiene el resto del lote: se registra en el log y ese
 * evento en particular NO se marca como enviado, así que se reintenta en
 * la siguiente corrida.
 */
class EnviarRecordatoriosAgendaCommand extends Command
{
    protected $signature = 'agenda:enviar-recordatorios';

    protected $description = 'Notifica los recordatorios de Agenda (CRM) pendientes de envío';

    public function handle(): int
    {
        $eventos = CrmAgenda::conRecordatorioPendiente()->with('vendedor.user')->get();

        $enviados = 0;
        foreach ($eventos as $evento) {
            try {
                $user = $evento->vendedor?->user;
                if ($user) {
                    NotificationService::toUser($user)
                        ->withAction('/crm/agenda', 'Ver agenda')
                        ->info($evento->titulo, $evento->descripcion ?? 'Recordatorio de agenda');
                } else {
                    Log::warning("Recordatorio de agenda #{$evento->id}: vendedor sin usuario ligado, no se notifica.");
                }

                // Nota: sin transacción — un fallo en update() después de notificar
                // con éxito causaría doble notificación en el reintento. Aceptable
                // por el riesgo bajo vs. la complejidad de una transacción por evento.
                // Nota: race condition conocida y aceptada — si un PUT del usuario
                // resetea recordatorio_enviado_at a null en la ventana entre el
                // ->get() de arriba y este update() sobre la MISMA instancia en
                // memoria, ese reset se pisa y el recordatorio recién editado queda
                // marcado "enviado" sin notificar la nueva fecha. Poco probable dada
                // la cadencia de 5 min del scheduler; no se corrige por complejidad
                // vs. riesgo bajo (mismo criterio que la nota de arriba).
                $evento->update(['recordatorio_enviado_at' => now()]);
                $enviados++;
            } catch (\Throwable $e) {
                Log::error("Error al enviar recordatorio de agenda #{$evento->id}: {$e->getMessage()}", ['exception' => $e]);
            }
        }

        $this->info("Recordatorios de agenda procesados: {$enviados}/{$eventos->count()}");

        return self::SUCCESS;
    }
}
