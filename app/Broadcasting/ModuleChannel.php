<?php

namespace App\Broadcasting;

use App\Models\Module;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserEnterpriseAccess;
use App\Models\UserModuleAccess;

/**
 * Autorización del canal privado `module.{empresa}.{aplicación}.{módulo}`.
 *
 * Por ese canal viaja el payload completo de cada alta, cambio y baja del
 * módulo, así que se autoriza con los mismos accesos jerárquicos que deciden
 * qué ve el usuario en el menú: empresa → aplicación → módulo. Si no podía
 * abrir la pantalla, tampoco puede escuchar sus eventos.
 */
class ModuleChannel
{
    public function join(User $user, string $enterpriseSlug, string $applicationSlug, string $moduleSlug): bool
    {
        $modulo = Module::query()
            ->where('slug', $moduleSlug)
            ->whereHas('application', function ($q) use ($applicationSlug, $enterpriseSlug) {
                $q->where('slug', $applicationSlug)
                    ->whereHas('enterprise', fn ($eq) => $eq->where('slug', $enterpriseSlug));
            })
            ->first();

        if (! $modulo) {
            return false;
        }

        $tieneEmpresa = UserEnterpriseAccess::where('user_id', $user->id)
            ->where('enterprise_id', $modulo->application->enterprise_id)
            ->where('is_active', true)
            ->exists();

        $tieneAplicacion = UserApplicationAccess::where('user_id', $user->id)
            ->where('application_id', $modulo->application_id)
            ->where('is_active', true)
            ->exists();

        $tieneModulo = UserModuleAccess::where('user_id', $user->id)
            ->where('module_id', $modulo->id)
            ->where('is_active', true)
            ->exists();

        return $tieneEmpresa && $tieneAplicacion && $tieneModulo;
    }
}
