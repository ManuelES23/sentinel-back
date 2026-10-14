<?php

namespace App\Traits\CRM;

use App\Support\PermisoSubmodulo;
use Illuminate\Support\Facades\Auth;

/**
 * Enforcement granular de permisos por submódulo (ver/crear/editar/...),
 * contra la tabla user_submodule_permissions. Distinto de
 * FiltraPorEmpresa::getEmpresaId() (que resuelve el TENANT) — esto
 * resuelve si el usuario autenticado puede hacer ESTA acción puntual
 * sobre ESTE submódulo, dentro de la empresa ya resuelta.
 *
 * Es el primer consumidor real de UserSubmodulePermission a nivel de
 * autorización de acción: hasta ahora solo se usaba para decidir qué
 * aparece en el sidebar (visibilidad), nunca para autorizar un POST/PUT.
 */
trait VerificaPermisoSubmodulo
{
    protected function tienePermisoSubmodulo(
        int $empresaId,
        string $moduloSlug,
        string $submoduloSlug,
        string $permisoSlug,
        string $aplicacionSlug = 'crm',
    ): bool {
        return PermisoSubmodulo::tiene(Auth::id(), $empresaId, $aplicacionSlug, $moduloSlug, $submoduloSlug, $permisoSlug);
    }

    /**
     * Aborta con 403 si no hay empresa de contexto o si el usuario no tiene
     * el permiso. Atajo para las acciones que no necesitan ramificar según el
     * permiso (la mayoría de los store/update/destroy).
     */
    protected function exigirPermisoSubmodulo(
        ?int $empresaId,
        string $moduloSlug,
        string $submoduloSlug,
        string $permisoSlug,
        string $mensaje = 'No tienes permiso para realizar esta acción.',
        string $aplicacionSlug = 'crm',
    ): void {
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, $moduloSlug, $submoduloSlug, $permisoSlug, $aplicacionSlug),
            403,
            $mensaje,
        );
    }
}
