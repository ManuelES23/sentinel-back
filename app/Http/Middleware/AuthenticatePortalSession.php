<?php

namespace App\Http\Middleware;

use App\Services\RH\SesionPortalVacaciones;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica el portal de vacaciones por X-Portal-Token (spec §3.3). A
 * propósito no consulta Sanctum ni device_pairings: un token del portal no
 * abre rutas internas y un token interno no abre el portal.
 */
class AuthenticatePortalSession
{
    public const MENSAJE = 'Tu sesión terminó, vuelve a entrar.';

    public function __construct(private SesionPortalVacaciones $sesiones) {}

    public function handle(Request $request, Closure $next): Response
    {
        $sesion = $this->sesiones->validar($request->header('X-Portal-Token'));

        if (! $sesion) {
            return response()->json(['success' => false, 'message' => self::MENSAJE], 401);
        }

        $request->attributes->set('portal_session', $sesion);
        $request->attributes->set('portal_employee', $sesion->employee);

        return $next($request);
    }
}
