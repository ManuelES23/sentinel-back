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
}
