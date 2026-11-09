<?php

namespace App\Http\Middleware;

use App\Models\DevicePairing;
use App\Services\SplendidFarms\ChecadorCampo\AutorizacionChecadorCampo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica el token de dispositivo de la app directa del checador de campo
 * SF. Solo acepta emparejamientos sf_field (nunca self/kiosk de Grupo
 * Espléndido) y responde 401 con un `code` estable para que la app sepa si
 * debe volver a iniciar sesión.
 */
class AuthenticateSfFieldDevice
{
    private const MENSAJES = [
        'dispositivo_no_emparejado' => 'Este celular no está emparejado. Conéctate e inicia sesión.',
        'dispositivo_revocado' => 'Este celular ya no está autorizado. Conéctate y vuelve a iniciar sesión.',
        'dispositivo_caducado' => 'Este celular pasó más de 30 días sin conectarse. Conéctate y vuelve a iniciar sesión.',
        'sin_permiso' => 'Ya no tienes permiso para usar el checador de campo. Pide acceso a tu administrador.',
    ];

    public function __construct(private readonly AutorizacionChecadorCampo $autorizacion)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = $request->header('X-Device-Token');

        if (! $rawToken) {
            return $this->rechazo('dispositivo_no_emparejado');
        }

        $pairing = DevicePairing::findByToken($rawToken, [DevicePairing::MODE_SF_FIELD]);

        if (! $pairing) {
            return $this->rechazo('dispositivo_no_emparejado');
        }

        $estado = $this->autorizacion->estado($pairing);
        if ($estado !== 'ok') {
            return $this->rechazo($estado);
        }

        // updateQuietly(): igual que el middleware de GE, evita una fila de
        // activity_log por cada request del dispositivo.
        $pairing->updateQuietly(['last_used_at' => now()]);
        $pairing->load(['pairedByUser', 'enterprise']);
        $request->attributes->set('devicePairing', $pairing);

        return $next($request);
    }

    private function rechazo(string $code): Response
    {
        return response()->json([
            'status' => 'error',
            'code' => $code,
            'message' => self::MENSAJES[$code],
        ], 401);
    }
}
