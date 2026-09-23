<?php

namespace App\Http\Controllers\Api\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

abstract class CrmBaseController extends Controller
{
    /**
     * Respuesta JSON de éxito con el formato estándar de Sentinel.
     */
    protected function jsonSuccess(mixed $data, string $message = 'Operación exitosa', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /**
     * Respuesta JSON de éxito con paginación.
     */
    protected function jsonPaginated(mixed $paginated, string $message = 'Operación exitosa'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $paginated->items(),
            'meta'    => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * Respuesta JSON de error con el formato estándar de Sentinel.
     */
    protected function jsonError(string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
        ], $status);
    }

    /**
     * Emite un evento en tiempo real sin que una falla del servidor de
     * websockets (Reverb apagado o mal configurado en local) convierta la
     * respuesta en 500: el registro ya quedó guardado y la pantalla que hizo
     * el cambio recarga por su cuenta.
     */
    protected function difundir(object $evento): void
    {
        try {
            $pendiente = broadcast($evento);
            // PendingBroadcast emite al destruirse; forzarlo aquí mantiene
            // cualquier excepción dentro del try.
            unset($pendiente);
        } catch (\Throwable $e) {
            Log::warning('CRM: no se pudo emitir el evento en tiempo real', [
                'evento' => $evento::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
