<?php

namespace App\Traits\CRM;

use App\Models\Submodule;
use App\Models\UserSubmodulePermission;
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
    ): bool {
        $userId = Auth::id();
        if (! $userId) {
            return false;
        }

        $submodulo = Submodule::where('slug', $submoduloSlug)
            ->whereHas('module', function ($q) use ($empresaId, $moduloSlug) {
                $q->where('slug', $moduloSlug)
                    ->whereHas('application', function ($aq) use ($empresaId) {
                        $aq->where('enterprise_id', $empresaId)->where('slug', 'crm');
                    });
            })
            ->first();

        if (! $submodulo) {
            return false;
        }

        return UserSubmodulePermission::where('user_id', $userId)
            ->where('submodule_id', $submodulo->id)
            ->where('is_granted', true)
            ->whereHas('permissionType', fn ($q) => $q->where('slug', $permisoSlug))
            ->exists();
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
    ): void {
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, $moduloSlug, $submoduloSlug, $permisoSlug),
            403,
            $mensaje,
        );
    }
}
