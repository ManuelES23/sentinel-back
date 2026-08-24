<?php
// app/Console/Commands/SincronizarOutlookCommand.php

namespace App\Console\Commands;

use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmOutlookConexion;
use App\Models\CRM\CrmOutlookEventoMapeado;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Empuja hacia el calendario de Outlook de cada vendedor conectado los
 * eventos/tareas de Agenda pendientes (unidireccional: Sentinel -> Outlook,
 * jamás al revés). Un fallo en una conexión, o en un evento puntual dentro
 * de una conexión, nunca detiene el resto del lote (mismo criterio que
 * EnviarRecordatoriosAgendaCommand).
 *
 * Deliberadamente NO toca AgendaController::destroy() ni ningún endpoint ya
 * construido -- solo LEE el estado resultante de crm_agenda, manteniendo la
 * integración completamente desacoplada del código de Agenda ya probado.
 */
class SincronizarOutlookCommand extends Command
{
    protected $signature = 'agenda:sincronizar-outlook';

    protected $description = 'Empuja los eventos de Agenda pendientes hacia el calendario de Outlook de cada vendedor conectado (unidireccional)';

    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    public function handle(): int
    {
        $conexiones = CrmOutlookConexion::all();
        $procesadas = 0;

        foreach ($conexiones as $conexion) {
            try {
                if (! $this->asegurarTokenVigente($conexion)) {
                    continue; // el error ya quedó guardado en ultimo_error dentro del helper
                }

                $this->crearYActualizar($conexion);
                $this->borrarEliminados($conexion);

                $conexion->update(['ultimo_sync_at' => now(), 'ultimo_error' => null]);
                $procesadas++;
            } catch (\Throwable $e) {
                Log::error("Error al sincronizar Outlook para conexión #{$conexion->id}: {$e->getMessage()}", ['exception' => $e]);
                $conexion->update(['ultimo_error' => $e->getMessage()]);
            }
        }

        $this->info("Conexiones de Outlook sincronizadas: {$procesadas}/{$conexiones->count()}");

        return self::SUCCESS;
    }

    private function asegurarTokenVigente(CrmOutlookConexion $conexion): bool
    {
        if ($conexion->token_expires_at->subMinutes(5)->isFuture()) {
            return true;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $conexion->refresh_token,
            'scope' => 'https://graph.microsoft.com/Calendars.ReadWrite offline_access',
        ]);

        if (! $response->successful()) {
            Log::error("No se pudo refrescar el token de Outlook para conexión #{$conexion->id}: {$response->body()}");
            $conexion->update(['ultimo_error' => 'No se pudo refrescar el token de acceso. Reconecta tu cuenta de Outlook.']);

            return false;
        }

        $data = $response->json();
        $conexion->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $conexion->refresh_token,
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return true;
    }

    private function crearYActualizar(CrmOutlookConexion $conexion): void
    {
        $eventos = CrmAgenda::where('empresa_id', $conexion->empresa_id)
            ->where('vendedor_id', $conexion->crm_vendedor_id)
            ->where('completado', false)
            ->where('fecha_inicio', '>=', now())
            ->with('outlookMapeo')
            ->get();

        foreach ($eventos as $evento) {
            try {
                $mapeo = $evento->outlookMapeo;

                // Nota: EnviarRecordatoriosAgendaCommand también hace un
                // update() sobre este mismo evento al marcar el recordatorio
                // como enviado, lo que bumpea updated_at aunque nada visible
                // haya cambiado -- esto puede disparar un PATCH redundante
                // (mismo contenido) en la siguiente corrida. Aceptado: es
                // inofensivo, solo reenvía el mismo contenido.
                if ($mapeo && $mapeo->ultima_actualizacion_enviada_at->gte($evento->updated_at)) {
                    continue;
                }

                $payload = [
                    'subject' => $evento->titulo,
                    'body' => ['contentType' => 'text', 'content' => $evento->descripcion ?? ''],
                    'start' => ['dateTime' => $evento->fecha_inicio->toIso8601String(), 'timeZone' => 'America/Mazatlan'],
                    'end' => ['dateTime' => $evento->fecha_fin->toIso8601String(), 'timeZone' => 'America/Mazatlan'],
                ];

                if ($mapeo) {
                    $response = $this->graphRequest($conexion)->patch(self::GRAPH_BASE."/me/events/{$mapeo->outlook_event_id}", $payload);
                    if ($this->esRateLimit($response)) {
                        continue;
                    }
                    $response->throw();
                    $mapeo->update(['ultima_actualizacion_enviada_at' => now()]);
                } else {
                    $response = $this->graphRequest($conexion)->post(self::GRAPH_BASE.'/me/events', $payload);
                    if ($this->esRateLimit($response)) {
                        continue;
                    }
                    $response->throw();

                    CrmOutlookEventoMapeado::create([
                        'crm_agenda_id' => $evento->id,
                        'crm_outlook_conexion_id' => $conexion->id,
                        'outlook_event_id' => $response->json('id'),
                        'ultima_actualizacion_enviada_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error("Error al sincronizar el evento de agenda #{$evento->id} hacia Outlook: {$e->getMessage()}", ['exception' => $e]);
            }
        }
    }

    private function borrarEliminados(CrmOutlookConexion $conexion): void
    {
        $mapeos = CrmOutlookEventoMapeado::where('crm_outlook_conexion_id', $conexion->id)
            ->whereNull('crm_agenda_id')
            ->get();

        foreach ($mapeos as $mapeo) {
            try {
                $response = $this->graphRequest($conexion)->delete(self::GRAPH_BASE."/me/events/{$mapeo->outlook_event_id}");
                if ($this->esRateLimit($response)) {
                    continue;
                }
                // 404 = ya no existe del lado de Outlook (el usuario lo
                // borró manualmente allá) -- se trata igual que un borrado
                // exitoso, se limpia el mapeo.
                if (! $response->successful() && $response->status() !== 404) {
                    $response->throw();
                }
                $mapeo->delete();
            } catch (\Throwable $e) {
                Log::error("Error al borrar el evento de Outlook {$mapeo->outlook_event_id}: {$e->getMessage()}", ['exception' => $e]);
            }
        }
    }

    private function graphRequest(CrmOutlookConexion $conexion)
    {
        return Http::withToken($conexion->access_token)->acceptJson();
    }

    private function esRateLimit(Response $response): bool
    {
        if ($response->status() === 429) {
            Log::warning('Rate limit de Microsoft Graph alcanzado, se reintenta en la siguiente corrida.');

            return true;
        }

        return false;
    }
}
