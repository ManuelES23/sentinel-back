<?php

namespace App\Services\SplendidFarms\ChecadorCampo;

use App\Models\DevicePairing;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserSubmoduleAccess;
use App\Support\PermisoSubmodulo;

/**
 * Regla única de autorización del checador de campo SF (app directa):
 * quién puede usar la app y en qué estado está un dispositivo emparejado.
 */
class AutorizacionChecadorCampo
{
    public const APLICACION = 'administration';
    public const MODULO = 'personal';
    public const SUBMODULO = 'checador-campo';

    /** Empresa del checador de campo (por slug de config), o null si no existe. */
    public function empresa(): ?Enterprise
    {
        return Enterprise::where('slug', config('biometrics.sf_field_enterprise_slug'))->first();
    }

    /**
     * Superadmin, o acceso activo a la empresa + acceso vigente al submódulo
     * administration/personal/checador-campo. No se exige create/edit.
     */
    public function usuarioAutorizado(User $user, Enterprise $empresa): bool
    {
        if ($user->isSuperadmin()) {
            return true;
        }

        $accesoEmpresa = UserEnterpriseAccess::where('user_id', $user->id)
            ->where('enterprise_id', $empresa->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
        if (! $accesoEmpresa) {
            return false;
        }

        $submodulo = PermisoSubmodulo::submodulo($empresa->id, self::APLICACION, self::MODULO, self::SUBMODULO);
        if (! $submodulo) {
            return false;
        }

        return UserSubmoduleAccess::where('user_id', $user->id)
            ->where('submodule_id', $submodulo->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    /**
     * 'ok' | 'dispositivo_revocado' | 'dispositivo_caducado' | 'sin_permiso'.
     * Orden: revocado, caducado, sin usuario o sin permiso.
     */
    public function estado(DevicePairing $pairing): string
    {
        if ($pairing->isRevoked()) {
            return 'dispositivo_revocado';
        }

        if ($pairing->isExpired()) {
            return 'dispositivo_caducado';
        }

        $user = $pairing->paired_by_user_id ? User::find($pairing->paired_by_user_id) : null;
        $empresa = $pairing->enterprise_id ? Enterprise::find($pairing->enterprise_id) : $this->empresa();

        if (! $user || ! $empresa || ! $this->usuarioAutorizado($user, $empresa)) {
            return 'sin_permiso';
        }

        return 'ok';
    }
}
