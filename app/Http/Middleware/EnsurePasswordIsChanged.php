<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si un admin restableció la contraseña con "obligar a cambiarla", el usuario
 * no puede usar la API hasta definir una propia. Solo se permite lo necesario
 * para iniciar/cerrar sesión, leer su usuario y cambiar la contraseña.
 *
 * Va en el grupo `api` para cubrir todas las rutas (incluidos archivos como
 * routes/crm.php). Las rutas públicas pasan porque no resuelven usuario.
 */
class EnsurePasswordIsChanged
{
    private const RUTAS_PERMITIDAS = [
        'api/auth/login',
        'api/auth/user',
        'api/auth/logout',
        'api/profile/password',
        'api/broadcasting/auth',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if (! $user || ! $user->must_change_password || in_array($request->path(), self::RUTAS_PERMITIDAS, true)) {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'code' => 'password_change_required',
            'message' => 'Debes cambiar tu contraseña antes de continuar.',
        ], 403);
    }
}
