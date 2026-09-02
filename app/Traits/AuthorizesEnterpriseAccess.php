<?php

namespace App\Traits;

use App\Models\UserEnterpriseAccess;
use Illuminate\Http\Request;

/**
 * Verifica que el usuario autenticado tiene acceso vigente a una empresa,
 * consultando UserEnterpriseAccess (tabla user_enterprise_access) — NO
 * User::activeEnterprises()/hasEnterpriseAccess() (pivot legacy
 * user_enterprises). Esas dos tablas son sistemas distintos: el modal de
 * permisos actual (HierarchicalPermissionController) y el login
 * (AuthController::getUserPermissions(), fuente real de qué empresas ve un
 * usuario) escriben/leen UserEnterpriseAccess; user_enterprises no lo llena
 * ninguna pantalla vigente del admin. Usar el pivot legacy causaba 403
 * ("No tienes acceso a esta empresa") para cualquier usuario al que se le
 * hubiera dado acceso por el camino correcto (el modal de permisos) —
 * confirmado en campo agosto 2026 (ver commit 57f8e87, que corrigió el mismo
 * bug en SfFieldCheckController antes de que este trait existiera).
 */
trait AuthorizesEnterpriseAccess
{
    protected function authorizeEnterpriseAccess(Request $request, int $enterpriseId): void
    {
        abort_unless(
            UserEnterpriseAccess::where('user_id', $request->user()->id)
                ->where('enterprise_id', $enterpriseId)
                ->where('is_active', true)
                ->exists(),
            403,
            'No tienes acceso a esta empresa'
        );
    }
}
