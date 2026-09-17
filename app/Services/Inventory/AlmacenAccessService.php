<?php

namespace App\Services\Inventory;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserEntityAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve la empresa del header X-Enterprise-Slug (validando que el usuario
 * pertenezca a ella) y los almacenes (entidades) que el usuario puede ver.
 *
 * Regla: admin/superadmin o quien tenga el permiso `ver_todos_almacenes` en
 * cualquier submódulo de la empresa ve todas las entidades de la empresa
 * (propias + vinculadas). El resto solo ve las asignadas en user_entity_access.
 */
class AlmacenAccessService
{
    public const PERMISO_VER_TODOS = 'ver_todos_almacenes';

    public function resolverEmpresa(Request $request): Enterprise
    {
        $slug = $request->header('X-Enterprise-Slug');
        $empresa = $slug ? Enterprise::where('slug', $slug)->first() : null;

        if (! $empresa) {
            $this->abortar(422, 'No se pudo determinar la empresa actual desde el header X-Enterprise-Slug');
        }

        $user = $request->user();
        if (! $user || ! $this->perteneceAEmpresa($user, $empresa)) {
            $this->abortar(403, 'No tienes acceso a esta empresa');
        }

        return $empresa;
    }

    public function esAdmin(User $user): bool
    {
        return EnsureUserIsAdmin::esAdmin($user);
    }

    public function perteneceAEmpresa(User $user, Enterprise $empresa): bool
    {
        if ($this->esAdmin($user)) {
            return true;
        }

        return UserEnterpriseAccess::where('user_id', $user->id)
            ->where('enterprise_id', $empresa->id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * @return array<int>
     */
    public function idsDeEmpresa(Enterprise $empresa): array
    {
        $propias = Entity::whereHas('branch', fn ($q) => $q->where('enterprise_id', $empresa->id))
            ->pluck('id');

        $vinculadas = DB::table('enterprise_entity as ee')
            ->join('entities as e', 'e.id', '=', 'ee.entity_id')
            ->where('ee.enterprise_id', $empresa->id)
            ->whereNull('e.deleted_at')
            ->pluck('ee.entity_id');

        return $propias->merge($vinculadas)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function puedeVerTodos(User $user, Enterprise $empresa): bool
    {
        if ($this->esAdmin($user)) {
            return true;
        }

        return UserSubmodulePermission::where('user_id', $user->id)
            ->where('is_granted', true)
            ->whereHas('permissionType', fn ($q) => $q->where('slug', self::PERMISO_VER_TODOS))
            ->whereHas('submodule.module.application', fn ($q) => $q->where('enterprise_id', $empresa->id))
            ->exists();
    }

    /**
     * @return array<int>
     */
    public function idsVisibles(User $user, Enterprise $empresa): array
    {
        $deEmpresa = $this->idsDeEmpresa($empresa);

        if ($this->puedeVerTodos($user, $empresa)) {
            return $deEmpresa;
        }

        $asignadas = UserEntityAccess::where('user_id', $user->id)
            ->where('enterprise_id', $empresa->id)
            ->pluck('entity_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_intersect($deEmpresa, $asignadas));
    }

    public function puedeVer(User $user, Enterprise $empresa, ?int $entityId): bool
    {
        return $entityId !== null
            && in_array((int) $entityId, $this->idsVisibles($user, $empresa), true);
    }

    private function abortar(int $status, string $mensaje): never
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => $mensaje,
        ], $status));
    }
}
