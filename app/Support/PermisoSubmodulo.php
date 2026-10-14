<?php

namespace App\Support;

use App\Models\Submodule;
use App\Models\UserSubmodulePermission;

/**
 * Permisos por submódulo (user_submodule_permissions) para cualquier
 * aplicación y cualquier usuario. La concesión cuenta solo si su tipo de
 * permiso pertenece al mismo submódulo (una fila cruzada no abre nada).
 */
final class PermisoSubmodulo
{
    public static function submodulo(int $empresaId, string $aplicacion, string $modulo, string $submodulo): ?Submodule
    {
        return Submodule::where('slug', $submodulo)
            ->whereHas('module', fn ($q) => $q->where('slug', $modulo)
                ->whereHas('application', fn ($a) => $a->where('enterprise_id', $empresaId)->where('slug', $aplicacion)))
            ->first();
    }

    public static function tiene(?int $userId, int $empresaId, string $aplicacion, string $modulo, string $submodulo, string $permiso): bool
    {
        if (! $userId) {
            return false;
        }

        $sub = self::submodulo($empresaId, $aplicacion, $modulo, $submodulo);

        return $sub !== null && self::concesiones($sub->id, $permiso)->where('user_id', $userId)->exists();
    }

    /** @return list<int> */
    public static function usuariosCon(int $empresaId, string $aplicacion, string $modulo, string $submodulo, string $permiso): array
    {
        $sub = self::submodulo($empresaId, $aplicacion, $modulo, $submodulo);
        if (! $sub) {
            return [];
        }

        return self::concesiones($sub->id, $permiso)->pluck('user_id')
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private static function concesiones(int $submoduloId, string $permiso)
    {
        return UserSubmodulePermission::where('submodule_id', $submoduloId)
            ->where('is_granted', true)
            ->whereHas('permissionType', fn ($q) => $q->where('slug', $permiso)->where('submodule_id', $submoduloId));
    }
}
