<?php

namespace App\Policies;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Jerarquía de gestión de usuarios (única fuente de estas reglas).
 *
 * - superadmin gestiona a cualquiera.
 * - admin gestiona a user y admin, nunca a superadmin.
 * - Solo superadmin asigna el rol superadmin.
 * - Nadie cambia su propio rol, se borra o restablece su propia contraseña
 *   desde el panel (para eso está "Mi perfil").
 */
class UserPolicy
{
    public function create(User $actor, ?string $role = null): Response
    {
        if (! EnsureUserIsAdmin::esAdmin($actor)) {
            return Response::deny('No tienes permisos de administrador para realizar esta acción.');
        }

        return $this->puedeAsignarRol($actor, $role);
    }

    public function update(User $actor, User $target): Response
    {
        return $this->gestionar($actor, $target);
    }

    public function changeRole(User $actor, User $target, string $role): Response
    {
        $respuesta = $this->gestionar($actor, $target);
        if ($respuesta->denied()) {
            return $respuesta;
        }

        if ($actor->is($target)) {
            return Response::deny('No puedes cambiar tu propio rol.');
        }

        return $this->puedeAsignarRol($actor, $role);
    }

    public function delete(User $actor, User $target): Response
    {
        $respuesta = $this->gestionar($actor, $target);
        if ($respuesta->denied()) {
            return $respuesta;
        }

        return $actor->is($target)
            ? Response::deny('No puedes eliminar tu propia cuenta.')
            : Response::allow();
    }

    public function resetPassword(User $actor, User $target): Response
    {
        $respuesta = $this->gestionar($actor, $target);
        if ($respuesta->denied()) {
            return $respuesta;
        }

        return $actor->is($target)
            ? Response::deny('Para cambiar tu propia contraseña usa "Mi perfil".')
            : Response::allow();
    }

    /**
     * Asignar o revocar accesos y permisos: un admin no puede dejar a un
     * superadmin fuera del workspace quitándole sus empresas.
     */
    public function managePermissions(User $actor, User $target): Response
    {
        return $this->gestionar($actor, $target);
    }

    private function gestionar(User $actor, User $target): Response
    {
        if (! EnsureUserIsAdmin::esAdmin($actor)) {
            return Response::deny('No tienes permisos de administrador para realizar esta acción.');
        }

        if ($target->isSuperadmin() && ! $actor->isSuperadmin()) {
            return Response::deny('Solo un superadministrador puede gestionar a otro superadministrador.');
        }

        return Response::allow();
    }

    private function puedeAsignarRol(User $actor, ?string $role): Response
    {
        if ($role === 'superadmin' && ! $actor->isSuperadmin()) {
            return Response::deny('Solo un superadministrador puede asignar el rol de superadministrador.');
        }

        return Response::allow();
    }
}
