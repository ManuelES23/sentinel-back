<?php

namespace App\Services\Compras;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserSubmodulePermission;
use Illuminate\Support\Collection;

/**
 * Permisos granulares del flujo de compras (tabla user_submodule_permissions).
 * La ubicación del submódulo depende de la empresa: la resuelve RutasCompras.
 */
class PermisosCompras
{
    public function __construct(private RutasCompras $rutas)
    {
    }

    public function puedeCotizar(User $user, Enterprise $empresa): bool
    {
        return $this->tiene($user, $empresa, 'requisiciones', 'cotizar');
    }

    public function puedeConfirmar(User $user, Enterprise $empresa): bool
    {
        return $this->tiene($user, $empresa, 'recepciones', 'confirmar');
    }

    public function puedeGestionar(User $user, Enterprise $empresa): bool
    {
        return $this->tiene($user, $empresa, 'ordenes-compra', 'gestionar');
    }

    /** @return Collection<int, User> */
    public function usuariosQueCotizan(Enterprise $empresa): Collection
    {
        return $this->usuarios($empresa, 'requisiciones', 'cotizar');
    }

    /** @return Collection<int, User> */
    public function usuariosQueConfirman(Enterprise $empresa): Collection
    {
        return $this->usuarios($empresa, 'recepciones', 'confirmar');
    }

    private function tiene(User $user, Enterprise $empresa, string $submodulo, string $permiso): bool
    {
        if (EnsureUserIsAdmin::esAdmin($user)) {
            return true;
        }

        return $this->consulta($empresa, $submodulo, $permiso)->where('user_id', $user->id)->exists();
    }

    /** @return Collection<int, User> */
    private function usuarios(Enterprise $empresa, string $submodulo, string $permiso): Collection
    {
        $ids = $this->consulta($empresa, $submodulo, $permiso)->pluck('user_id')->unique();

        return User::whereIn('id', $ids)->orderBy('id')->get();
    }

    private function consulta(Enterprise $empresa, string $submodulo, string $permiso)
    {
        [$app, $modulo, $sub] = $this->rutas->ruta($empresa->slug, $submodulo);

        return UserSubmodulePermission::query()
            ->where('is_granted', true)
            ->whereHas('permissionType', fn ($q) => $q->where('slug', $permiso))
            ->whereHas('submodule', fn ($q) => $q->where('slug', $sub)
                ->whereHas('module', fn ($m) => $m->where('slug', $modulo)
                    ->whereHas('application', fn ($a) => $a->where('slug', $app)->where('enterprise_id', $empresa->id))));
    }
}
