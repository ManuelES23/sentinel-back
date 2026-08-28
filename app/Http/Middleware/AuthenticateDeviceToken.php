<?php
// sentinel-back/app/Http/Middleware/AuthenticateDeviceToken.php
namespace App\Http\Middleware;

use App\Models\DevicePairing;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDeviceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = $request->header('X-Device-Token');

        if (! $rawToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'Falta el token de dispositivo.',
            ], 401);
        }

        $pairing = DevicePairing::findActiveByToken($rawToken);

        if (! $pairing) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dispositivo no emparejado o token revocado. Vuelve a emparejarlo.',
            ], 401);
        }

        // updateQuietly(): last_used_at se toca en CADA sync()/refresh de
        // paquete exitoso — un update() normal dispararía el evento
        // `updated` de Loggable en cada uno de esos requests, generando una
        // fila de activity_log por checada/refresh (ruido de auditoría sin
        // valor real, ver hallazgo Importante de la revisión final).
        $pairing->updateQuietly(['last_used_at' => now()]);
        $request->attributes->set('devicePairing', $pairing);

        return $next($request);
    }
}
