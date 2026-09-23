<?php

namespace App\Services\Compras;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserSubmodulePermission;
use Illuminate\Support\Collection;

/**
 * Permisos granulares del flujo de compras (tabla user_submodule_permissions).
 */
class PermisosCompras
{
    public const COTIZAR = ['operacion-agricola', 'agricola', 'requisiciones', 'cotizar'];
    public const CONFIRMAR = ['inventario', 'compras', 'recepciones', 'confirmar'];
    public const GESTIONAR = ['inventario', 'compras', 'ordenes-compra', 'gestionar'];

    public function puedeCotizar(User $user, Enterprise $empresa): bool
    {
        return $this->tiene($user, $empresa, self::COTIZAR);
    }

    public function puedeConfirmar(User $user, Enterprise $empresa): bool
    {
        return $this->tiene($user, $empresa, self::CONFIRMAR);
    }

    public function puedeGestionar(User $user, Enterprise $empresa): bool
    {
        return $this->tiene($user, $empresa, self::GESTIONAR);
    }

    /** @return Collection<int, User> */
    public function usuariosQueCotizan(Enterprise $empresa): Collection
    {
        return $this->usuarios($empresa, self::COTIZAR);
    }

    /** @return Collection<int, User> */
    public function usuariosQueConfirman(Enterprise $empresa): Collection
    {
        return $this->usuarios($empresa, self::CONFIRMAR);
    }

    private function tiene(User $user, Enterprise $empresa, array $permiso): bool
    {
        if (EnsureUserIsAdmin::esAdmin($user)) {
            return true;
        }

        return $this->consulta($empresa, $permiso)->where('user_id', $user->id)->exists();
    }

    private function usuarios(Enterprise $empresa, array $permiso): Collection
    {
        $ids = $this->consulta($empresa, $permiso)->pluck('user_id')->unique();

        return User::whereIn('id', $ids)->orderBy('id')->get();
    }

    private function consulta(Enterprise $empresa, array $permiso)
    {
        [$app, $modulo, $sub, $slug] = $permiso;

        return UserSubmodulePermission::query()
            ->where('is_granted', true)
            ->whereHas('permissionType', fn ($q) => $q->where('slug', $slug))
            ->whereHas('submodule', fn ($q) => $q->where('slug', $sub)
                ->whereHas('module', fn ($m) => $m->where('slug', $modulo)
                    ->whereHas('application', fn ($a) => $a->where('slug', $app)->where('enterprise_id', $empresa->id))));
    }
}
