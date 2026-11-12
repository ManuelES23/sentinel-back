<?php

namespace App\Services\RH;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\User;
use App\Models\VacationOutlookConnection;
use App\Models\VacationOutlookEvent;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Support\RH\PresentadorPortalVacaciones;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Agenda en el Outlook de quien autoriza (usuario específico) las vacaciones que él aprobó
 * (unidireccional: Sentinel -> Outlook). Eventos de día completo, sin motivo ni comentarios.
 * Un fallo de Microsoft nunca debe romper la operación de vacaciones: los errores de Graph se
 * registran y no se propagan; solo los de base de datos pueden subir (los consumidores los atrapan).
 * Jamás se escribe un token en un log ni en un mensaje de error.
 */
class OutlookVacaciones
{
    public const MENSAJE_TOKEN = 'No se pudo renovar el acceso a Outlook. Vuelve a vincular tu cuenta.';

    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    private const ZONA = 'America/Mazatlan';

    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /** Quien es «usuario específico» en la cadena de vacaciones, o ya tiene una cuenta vinculada. */
    public function disponible(User $usuario): bool
    {
        if (VacationOutlookConnection::where('user_id', $usuario->id)->exists()) {
            return true;
        }

        $proceso = ApprovalProcess::where('code', ApprovalProcess::VACATION_REQUESTS)->value('id');

        return $proceso !== null && ApprovalFlowStep::where('approval_process_id', $proceso)
            ->where('is_active', true)
            ->where('approver_type', ApprovalFlowStep::TYPE_USER)
            ->where('user_id', $usuario->id)
            ->exists();
    }

    public function vincular(int $userId, string $email, string $accessToken, ?string $refreshToken, int $expiraEn): VacationOutlookConnection
    {
        return VacationOutlookConnection::updateOrCreate(
            ['user_id' => $userId],
            [
                'email_outlook' => $email,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => now()->addSeconds($expiraEn),
                'ultimo_error' => null,
            ],
        );
    }

    /** @return array{creados: int, borrados: int} */
    public function sincronizar(VacationOutlookConnection $c): array
    {
        $ceros = ['creados' => 0, 'borrados' => 0];
        $candado = Cache::lock("outlook-vacaciones:{$c->id}", 120);

        if (! $candado->get()) {
            return $ceros;
        }

        try {
            if (! $this->asegurarTokenVigente($c)) {
                return $ceros;
            }

            $fallos = 0;
            $borrados = $this->borrarSobrantes($c, $fallos);
            $creados = $this->crearFaltantes($c, $fallos);

            $c->update($fallos === 0
                ? ['ultimo_sync_at' => now(), 'ultimo_error' => null]
                : ['ultimo_error' => 'Algunos eventos no se pudieron sincronizar con Outlook; se reintentará en la siguiente corrida.']);

            return ['creados' => $creados, 'borrados' => $borrados];
        } finally {
            $candado->release();
        }
    }

    /** Sincroniza a quienes aprobaron un paso de la solicitud y a quienes ya la tienen agendada. Nunca lanza. */
    public function sincronizarSolicitud(VacationRequest $s): void
    {
        try {
            $usuarios = VacationRequestApproval::where('vacation_request_id', $s->id)
                ->where('decision', VacationRequestApproval::APPROVED)
                ->whereNotNull('decided_by')
                ->pluck('decided_by');

            $conexiones = VacationOutlookConnection::whereIn('user_id', $usuarios)
                ->orWhereIn('id', VacationOutlookEvent::where('vacation_request_id', $s->id)->pluck('vacation_outlook_connection_id'))
                ->get();

            foreach ($conexiones as $conexion) {
                try {
                    $this->sincronizar($conexion);
                } catch (\Throwable $e) {
                    Log::error("Error al sincronizar Outlook (vacaciones) de la conexión #{$conexion->id}: {$e->getMessage()}");
                }
            }
        } catch (\Throwable $e) {
            Log::error("Error al sincronizar Outlook (vacaciones) de la solicitud #{$s->id}: {$e->getMessage()}");
        }
    }

    /** Borra en Outlook cada evento agendado (best-effort; 404 = ya no existe) y elimina la conexión. */
    public function desvincular(VacationOutlookConnection $c): void
    {
        try {
            if ($this->asegurarTokenVigente($c)) {
                foreach ($c->eventos()->get() as $mapeo) {
                    try {
                        $this->graph($c)->delete(self::GRAPH_BASE."/me/events/{$mapeo->outlook_event_id}");
                    } catch (\Throwable $e) {
                        Log::warning("No se pudo borrar el evento de Outlook {$mapeo->outlook_event_id} al desvincular: {$e->getMessage()}");
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("No se pudo limpiar Outlook al desvincular la conexión #{$c->id}: {$e->getMessage()}");
        }

        $c->eventos()->delete();
        $c->delete();
    }

    /** Payload del evento de día completo (fin exclusivo). Nunca incluye el motivo ni comentarios. */
    public static function evento(VacationRequest $s, int $conexionId): array
    {
        $s->loadMissing(['employee.position', 'employee.department', 'employee.enterprise']);
        $empleado = $s->employee;

        $linea = collect([$empleado?->enterprise?->name, $empleado?->position?->name, $empleado?->department?->name])
            ->filter(fn ($v) => filled($v))
            ->implode(' · ');

        $dias = (int) $s->days_requested;
        $cuerpo = array_values(array_filter([
            'Vacaciones',
            $linea !== '' ? $linea : null,
            'Del '.self::fecha($s->start_date).' al '.self::fecha($s->end_date).' · '.$dias.' '.($dias === 1 ? 'día' : 'días'),
            'Folio '.PresentadorPortalVacaciones::folio($s),
        ]));

        return [
            'subject' => 'Vacaciones · '.($empleado?->full_name ?? ''),
            'body' => ['contentType' => 'text', 'content' => implode("\n", $cuerpo)],
            'isAllDay' => true,
            'showAs' => 'free',
            'isReminderOn' => false,
            'start' => ['dateTime' => $s->start_date->format('Y-m-d').'T00:00:00', 'timeZone' => self::ZONA],
            'end' => ['dateTime' => $s->end_date->copy()->addDay()->format('Y-m-d').'T00:00:00', 'timeZone' => self::ZONA],
            'transactionId' => "sentinel-vac-{$conexionId}-{$s->id}",
        ];
    }

    private static function fecha(Carbon $fecha): string
    {
        return $fecha->day.' '.self::MESES[$fecha->month - 1].' '.$fecha->year;
    }

    private function crearFaltantes(VacationOutlookConnection $c, int &$fallos): int
    {
        $agendadas = VacationOutlookEvent::where('vacation_outlook_connection_id', $c->id)->pluck('vacation_request_id');

        $pendientes = VacationRequest::query()
            ->where('status', 'approved')
            ->whereDate('end_date', '>=', today())
            ->whereHas('approvals', fn ($q) => $q->where('decision', VacationRequestApproval::APPROVED)->where('decided_by', $c->user_id))
            ->whereNotIn('id', $agendadas)
            ->with(['employee.position', 'employee.department', 'employee.enterprise'])
            ->orderBy('id')
            ->get();

        $creados = 0;
        foreach ($pendientes as $solicitud) {
            try {
                $respuesta = $this->graph($c)->post(self::GRAPH_BASE.'/me/events', self::evento($solicitud, $c->id));
                if ($this->esRateLimit($respuesta)) {
                    $fallos++;

                    continue;
                }
                $respuesta->throw();

                $eventoId = $respuesta->json('id');
                if (! is_string($eventoId) || $eventoId === '') {
                    // Sin id no hay mapeo posible (ni forma de borrarlo después): se reintenta en la siguiente corrida.
                    $fallos++;
                    Log::error("Graph respondió sin id al agendar la solicitud de vacaciones #{$solicitud->id} (conexión #{$c->id}).");

                    continue;
                }

                try {
                    VacationOutlookEvent::create([
                        'vacation_outlook_connection_id' => $c->id,
                        'vacation_request_id' => $solicitud->id,
                        'outlook_event_id' => $eventoId,
                        'enviado_at' => now(),
                    ]);
                    $creados++;
                } catch (QueryException $e) {
                    if ($this->esConflictoUnico($e)) {
                        // Otro proceso ya mapeó (conexión, solicitud) entre el POST y este guardado: se quita el sobrante.
                        $mapeado = VacationOutlookEvent::where('vacation_outlook_connection_id', $c->id)
                            ->where('vacation_request_id', $solicitud->id)
                            ->value('outlook_event_id');
                        Log::warning("Evento de vacaciones #{$solicitud->id} ya estaba mapeado en la conexión #{$c->id}.");
                        if ($mapeado !== $eventoId) {
                            $this->borrarEnGraphSinLanzar($c, $eventoId);
                        }

                        continue;
                    }

                    // Otro fallo de BD: el evento ya existe en Outlook sin mapeo; se quita para no dejarlo huérfano.
                    $fallos++;
                    Log::error("No se pudo guardar el mapeo de la solicitud de vacaciones #{$solicitud->id} (conexión #{$c->id}): ".($e->getPrevious()?->getMessage() ?? 'error de base de datos'));
                    $this->borrarEnGraphSinLanzar($c, $eventoId);
                }
            } catch (\Throwable $e) {
                $fallos++;
                Log::error("Error al agendar en Outlook la solicitud de vacaciones #{$solicitud->id}: {$e->getMessage()}");
            }
        }

        return $creados;
    }

    /** Borra en Graph y en la BD los mapeos cuya solicitud ya no debe estar (cancelada, rechazada o borrada). */
    private function borrarSobrantes(VacationOutlookConnection $c, int &$fallos): int
    {
        $mapeos = VacationOutlookEvent::where('vacation_outlook_connection_id', $c->id)
            ->with('solicitud')
            ->get()
            ->filter(fn (VacationOutlookEvent $m) => $m->solicitud === null
                || $m->solicitud->trashed()
                || $m->solicitud->status !== 'approved');

        $borrados = 0;
        foreach ($mapeos as $mapeo) {
            try {
                $respuesta = $this->graph($c)->delete(self::GRAPH_BASE."/me/events/{$mapeo->outlook_event_id}");
                if ($this->esRateLimit($respuesta)) {
                    $fallos++;

                    continue;
                }
                // 404 = ya no existe del lado de Outlook (se borró a mano): cuenta como borrado.
                if (! $respuesta->successful() && $respuesta->status() !== 404) {
                    $respuesta->throw();
                }
                $mapeo->delete();
                $borrados++;
            } catch (\Throwable $e) {
                $fallos++;
                Log::error("Error al borrar en Outlook el evento {$mapeo->outlook_event_id}: {$e->getMessage()}");
            }
        }

        return $borrados;
    }

    /** Violación del índice único (MySQL 1062, SQLite 19 con UNIQUE, Postgres 23505); no otras restricciones ni fallos de conexión. */
    private function esConflictoUnico(QueryException $e): bool
    {
        $estado = (string) ($e->errorInfo[0] ?? '');
        $codigo = (int) ($e->errorInfo[1] ?? 0);
        $mensaje = $e->getPrevious()?->getMessage() ?? '';

        return $estado === '23505'
            || ($estado === '23000' && ($codigo === 1062 || str_contains($mensaje, 'UNIQUE constraint failed') || str_contains($mensaje, 'Duplicate entry')));
    }

    private function borrarEnGraphSinLanzar(VacationOutlookConnection $c, string $eventoId): void
    {
        try {
            $this->graph($c)->delete(self::GRAPH_BASE."/me/events/{$eventoId}");
        } catch (\Throwable $e) {
            Log::warning("No se pudo borrar el evento sobrante {$eventoId} de Outlook (conexión #{$c->id}).");
        }
    }

    private function asegurarTokenVigente(VacationOutlookConnection $c): bool
    {
        if ($c->token_expires_at && $c->token_expires_at->copy()->subMinutes(5)->isFuture()) {
            return true;
        }

        try {
            $respuesta = Http::asForm()->post(self::TOKEN_URL, [
                'client_id' => config('services.microsoft.client_id'),
                'client_secret' => config('services.microsoft.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $c->refresh_token,
                'scope' => 'https://graph.microsoft.com/Calendars.ReadWrite offline_access',
            ]);
        } catch (\Throwable $e) {
            Log::error("No se pudo renovar el token de Outlook (vacaciones) de la conexión #{$c->id}.");
            $c->update(['ultimo_error' => self::MENSAJE_TOKEN]);

            return false;
        }

        if (! $respuesta->successful() || ! $respuesta->json('access_token')) {
            Log::error("No se pudo renovar el token de Outlook (vacaciones) de la conexión #{$c->id}: HTTP {$respuesta->status()}.");
            $c->update(['ultimo_error' => self::MENSAJE_TOKEN]);

            return false;
        }

        $c->update([
            'access_token' => $respuesta->json('access_token'),
            'refresh_token' => $respuesta->json('refresh_token') ?? $c->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($respuesta->json('expires_in') ?? 3600)),
        ]);

        return true;
    }

    private function graph(VacationOutlookConnection $c): PendingRequest
    {
        return Http::withToken($c->access_token)->acceptJson();
    }

    private function esRateLimit(Response $respuesta): bool
    {
        if ($respuesta->status() === 429) {
            Log::warning('Rate limit de Microsoft Graph alcanzado (vacaciones), se reintenta en la siguiente corrida.');

            return true;
        }

        return false;
    }
}
