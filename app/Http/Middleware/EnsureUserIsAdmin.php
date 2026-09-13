<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a administradores del sistema (users.role admin|superadmin).
 *
 * Uso:
 *   ->middleware('admin')        solo administradores
 *   ->middleware('admin:self')   administradores, o el propio usuario del
 *                                parámetro de ruta {user} (lecturas de "mis
 *                                permisos" que hace WorkspaceContext en el front)
 *
 * Protege la asignación de permisos, la gestión de usuarios y la estructura
 * Aplicación → Módulo → Submódulo: sin este guard cualquier usuario
 * autenticado podía otorgarse a sí mismo cualquier permiso.
 */
class EnsureUserIsAdmin
{
    public const ROLES_ADMIN = ['admin', 'superadmin'];

    public function handle(Request $request, Closure $next, ?string $modo = null): Response
    {
        $user = $request->user();

        if ($user && self::esAdmin($user)) {
            return $next($request);
        }

        if ($user && $modo === 'self' && $this->esElMismoUsuario($request, $user)) {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'No tienes permisos de administrador para realizar esta acción.',
        ], 403);
    }

    public static function esAdmin(User $user): bool
    {
        return in_array($user->role, self::ROLES_ADMIN, true);
    }

    private function esElMismoUsuario(Request $request, User $user): bool
    {
        $parametro = $request->route('user');
        $userId = $parametro instanceof User ? $parametro->id : $parametro;

        return $userId !== null && (string) $userId === (string) $user->id;
    }
}
