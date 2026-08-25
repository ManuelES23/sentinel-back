<?php
// app/Console/Commands/SincronizarDialpadCommand.php

namespace App\Console\Commands;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmDialpadSyncEstado;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Importa llamadas concluidas de Dialpad (una sola API key global, sin
 * conexión por usuario) como CrmActividad (tipo='llamada', fuente='dialpad').
 * Unidireccional: solo LEE de Dialpad, nunca escribe nada allá.
 *
 * A diferencia de SincronizarOutlookCommand (que aísla fallos por conexión,
 * porque hay muchas), aquí solo existe UNA conexión global -- un fallo de
 * red o de autenticación detiene el comando completo para esa corrida.
 *
 * Nota de diseño -- "cursor" propio vs. cursor de Dialpad: dentro de UNA
 * corrida se usa el `cursor` opaco que devuelve la API de Dialpad para
 * pedir la siguiente página (ver solicitarPagina()). Entre corridas se
 * guarda algo distinto en crm_dialpad_sync_estado.ultimo_call_id_sincronizado:
 * el call_id de la llamada más reciente vista, solo con fines de
 * diagnóstico/visibilidad para un admin -- NO se usa para decidir dónde
 * detener la paginación. La API devuelve las llamadas en orden cronológico
 * inverso, así que simplemente se recorren hasta MAX_PAGINAS_POR_CORRIDA
 * páginas en cada corrida; reprocesar llamadas ya vistas en corridas
 * anteriores es intencional y barato (creación idempotente por
 * dialpad_call_id, re-sync no pisa clasificaciones manuales -- ver abajo).
 */
class SincronizarDialpadCommand extends Command
{
    protected $signature = 'crm:sincronizar-dialpad';

    protected $description = 'Importa llamadas concluidas de Dialpad como CrmActividad (tipo=llamada, fuente=dialpad)';

    /** Clave de Cache donde se guardan los contadores de la corrida más reciente, para que el disparo manual (Task 3) los pueda leer justo después de invocar Artisan::call(). */
    public const CACHE_ULTIMA_CORRIDA = 'dialpad_ultima_corrida';

    private const CACHE_PREFIX_OMITIDA = 'dialpad_call_omitida:';

    /** Límite de páginas por corrida -- acota cuánto puede tardar tanto la corrida programada como el disparo manual síncrono (ver DialpadIntegracionController::sincronizar()). */
    private const MAX_PAGINAS_POR_CORRIDA = 20;

    /**
     * Mensaje genérico persistido en crm_dialpad_sync_estado.ultimo_error.
     * `crm_dialpad_sync_estado` es UNA sola fila global compartida por todas
     * las empresas, así que nunca debe contener el mensaje crudo de la
     * excepción: un QueryException interpola los bindings del SQL (incluida
     * la `descripcion` autogenerada, que embebe el teléfono del contacto,
     * más empresa_id/vendedor_id/dialpad_call_id) y un RequestException de
     * Dialpad embebe el cuerpo crudo de la respuesta HTTP -- cualquiera de
     * los dos filtraría datos de una empresa a cualquier usuario con permiso
     * 'ver' en OTRA empresa. El detalle completo sigue yendo a Log::error().
     */
    private const MENSAJE_ERROR_GENERICO = 'Error al sincronizar con Dialpad. Ver logs del servidor para más detalle.';

    private const MENSAJE_RATE_LIMIT = 'Se alcanzó el límite de solicitudes de Dialpad; se reintentará en la siguiente corrida.';

    public function handle(): int
    {
        $estado = CrmDialpadSyncEstado::obtenerSingleton();

        $apiKey = config('services.dialpad.api_key');
        if (! $apiKey) {
            $mensaje = 'CRM_DIALPAD_API_KEY no está configurada.';
            Log::error($mensaje);
            // $mensaje no contiene datos sensibles en este caso puntual, pero
            // se persiste el mismo mensaje genérico que el catch-all para no
            // tener dos formatos distintos de error en un mismo campo
            // compartido entre empresas -- ver nota de seguridad arriba.
            $estado->update(['ultimo_error' => self::MENSAJE_ERROR_GENERICO]);
            $this->guardarContadores(0, 0);

            return self::FAILURE;
        }

        $sincronizadas = 0;
        $omitidas = 0;
        $masRecienteCallId = null;
        $cursor = null;
        $pagina = 0;
        $rateLimited = false;

        try {
            do {
                $response = $this->solicitarPagina($apiKey, $cursor);

                if ($response->status() === 429) {
                    Log::warning('Rate limit de Dialpad alcanzado, se corta la corrida sin avanzar el cursor.');
                    $rateLimited = true;
                    break;
                }

                $response->throw();

                $data = $response->json() ?? [];
                $items = $data['items'] ?? [];

                foreach ($items as $llamada) {
                    if ($masRecienteCallId === null && isset($llamada['call_id'])) {
                        $masRecienteCallId = (string) $llamada['call_id'];
                    }

                    if ($this->procesarLlamada($llamada)) {
                        $sincronizadas++;
                    } else {
                        $omitidas++;
                    }
                }

                $cursor = $data['cursor'] ?? null;
                $pagina++;
            } while ($cursor && $pagina < self::MAX_PAGINAS_POR_CORRIDA);

            if ($rateLimited) {
                // No se toca ultimo_call_id_sincronizado ni ultimo_sync_at
                // (esa regla se mantiene intacta -- no avanzar el cursor de
                // diagnóstico en un rate limit), pero sí se refleja en
                // ultimo_error para que un admin viendo estado() no vea una
                // corrida "exitosa" obsoleta sin ninguna señal de problema.
                // Se autolimpia en la siguiente corrida exitosa (ver arriba).
                $estado->update(['ultimo_error' => self::MENSAJE_RATE_LIMIT]);
            } else {
                $estado->update([
                    'ultimo_call_id_sincronizado' => $masRecienteCallId ?? $estado->ultimo_call_id_sincronizado,
                    'ultimo_sync_at' => now(),
                    'ultimo_error' => null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("Error al sincronizar llamadas de Dialpad: {$e->getMessage()}", ['exception' => $e]);
            $estado->update(['ultimo_error' => self::MENSAJE_ERROR_GENERICO]);
            $this->guardarContadores($sincronizadas, $omitidas);

            return self::FAILURE;
        }

        $this->guardarContadores($sincronizadas, $omitidas);

        $this->info("Llamadas de Dialpad sincronizadas: {$sincronizadas}, omitidas: {$omitidas}");

        return self::SUCCESS;
    }

    /**
     * Guarda en Cache los contadores de la corrida más reciente, para que el
     * disparo manual (Task 3) los pueda leer justo después de invocar
     * Artisan::call(). Único punto de escritura para las 3 salidas de
     * handle() (falla de API key, excepción, y corrida normal/rate-limited).
     */
    private function guardarContadores(int $sincronizadas, int $omitidas): void
    {
        Cache::put(self::CACHE_ULTIMA_CORRIDA, ['sincronizadas' => $sincronizadas, 'omitidas' => $omitidas], now()->addMinutes(5));
    }

    private function solicitarPagina(string $apiKey, ?string $cursor)
    {
        $baseUrl = rtrim((string) config('services.dialpad.base_url'), '/');
        $query = $cursor ? ['cursor' => $cursor] : [];

        return Http::withToken($apiKey)->acceptJson()->timeout(15)->get("{$baseUrl}/call", $query);
    }

    /**
     * Procesa una llamada del payload de Dialpad. Devuelve true si se creó o
     * actualizó una CrmActividad, false si se omitió (sin vendedor match).
     */
    private function procesarLlamada(array $llamada): bool
    {
        $callId = isset($llamada['call_id']) ? (string) $llamada['call_id'] : null;
        if (! $callId) {
            return false;
        }

        $emailAgente = $llamada['target']['email'] ?? null;
        $vendedor = $this->resolverVendedor($emailAgente);

        if (! $vendedor) {
            $this->registrarOmisionSinVendedor($callId, $emailAgente);

            return false;
        }

        $telefonoContacto = $llamada['contact']['phone'] ?? null;
        [$entidadType, $entidadId] = $this->resolverEntidad((int) $vendedor->empresa_id, $telefonoContacto);

        $direccion = ($llamada['direction'] ?? null) === 'outbound' ? 'saliente' : 'entrante';
        $duracionMinutos = isset($llamada['duration']) ? (int) round(((float) $llamada['duration']) / 60000) : null;
        $fechaActividad = isset($llamada['date_started'])
            ? Carbon::createFromTimestampMs((int) $llamada['date_started'])
            : now();

        $telefonoLabel = $telefonoContacto ?: 'número desconocido';
        $duracionLabel = $duracionMinutos !== null ? "{$duracionMinutos} min" : 'duración desconocida';
        $descripcion = "Llamada {$direccion} de Dialpad ({$telefonoLabel}) — {$duracionLabel}";

        $existente = CrmActividad::where('dialpad_call_id', $callId)->first();

        if ($existente) {
            // Re-sync: solo se actualiza si nadie la ha clasificado manualmente (Global Constraints).
            if ($existente->entidad_id === null && $existente->resultado === null) {
                $existente->update([
                    'descripcion' => $descripcion,
                    'duracion_minutos' => $duracionMinutos,
                ]);
            }

            return true;
        }

        CrmActividad::create([
            'empresa_id' => $vendedor->empresa_id,
            'tipo' => 'llamada',
            'entidad_type' => $entidadType,
            'entidad_id' => $entidadId,
            'vendedor_id' => $vendedor->id,
            'descripcion' => $descripcion,
            'fecha_actividad' => $fechaActividad,
            'duracion_minutos' => $duracionMinutos,
            'fuente' => 'dialpad',
            'dialpad_call_id' => $callId,
        ]);

        return true;
    }

    /**
     * Resuelve el vendedor por email, SIN filtrar por empresa (en este punto
     * no se sabe a cuál pertenece -- la empresa de la Actividad se resuelve
     * de la empresa_id de este vendedor, es la única fuente de verdad).
     */
    private function resolverVendedor(?string $email): ?CrmVendedor
    {
        if (! $email) {
            return null;
        }

        $vendedores = CrmVendedor::where('email', $email)->orderBy('id')->get();

        if ($vendedores->count() > 1) {
            $ids = $vendedores->pluck('id')->implode(',');
            Log::warning("Email de vendedor Dialpad duplicado entre empresas: {$email} (vendedor_ids: {$ids}). Se usa el primero.");
        }

        return $vendedores->first();
    }

    /**
     * Resuelve la entidad relacionada por teléfono, dentro de la empresa ya
     * resuelta por el vendedor. Prioridad: Cliente > Prospecto > Contacto.
     * Devuelve [entidad_type FQCN, entidad_id] o [null, null] si no hay match
     * o no hay teléfono que buscar.
     */
    private function resolverEntidad(int $empresaId, ?string $telefono): array
    {
        if (! $telefono) {
            return [null, null];
        }

        $cliente = CrmCliente::where('empresa_id', $empresaId)->where('telefono', $telefono)->first();
        if ($cliente) {
            return [CrmCliente::class, $cliente->id];
        }

        $prospecto = CrmProspecto::where('empresa_id', $empresaId)->where('telefono', $telefono)->first();
        if ($prospecto) {
            return [CrmProspecto::class, $prospecto->id];
        }

        $contacto = CrmContacto::where('empresa_id', $empresaId)->where('telefono', $telefono)->first();
        if ($contacto) {
            return [CrmContacto::class, $contacto->id];
        }

        return [null, null];
    }

    /**
     * Loguea la omisión por falta de vendedor UNA sola vez por call_id (con
     * Cache, TTL de 30 días) -- evita repetir el mismo warning en cada
     * corrida futura (cada 15 min) hasta que un admin registre al vendedor.
     */
    private function registrarOmisionSinVendedor(string $callId, ?string $email): void
    {
        $cacheKey = self::CACHE_PREFIX_OMITIDA.$callId;
        if (Cache::has($cacheKey)) {
            return;
        }

        Log::warning("Llamada de Dialpad omitida: ningún vendedor coincide con el email '{$email}' (call_id: {$callId}).");
        Cache::put($cacheKey, true, now()->addDays(30));
    }
}
