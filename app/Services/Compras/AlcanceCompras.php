<?php

namespace App\Services\Compras;

use App\Models\Enterprise;
use App\Models\User;
use App\Services\Inventory\AlmacenAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Visibilidad de requisiciones, OC y recepciones: la empresa del registro
 * debe ser la resuelta y su almacén visible para el usuario. Quien ve todos
 * los almacenes ve además los registros antiguos sin empresa/almacén.
 */
class AlcanceCompras
{
    public function __construct(private AlmacenAccessService $almacenes)
    {
    }

    public function aplicar(Builder $query, string $columnaAlmacen, User $user, Enterprise $empresa): Builder
    {
        $tabla = $query->getModel()->getTable();

        if ($this->almacenes->puedeVerTodos($user, $empresa)) {
            return $query->where(fn ($q) => $q->where("$tabla.enterprise_id", $empresa->id)
                ->orWhereNull("$tabla.enterprise_id"));
        }

        return $query->where("$tabla.enterprise_id", $empresa->id)
            ->whereIn("$tabla.$columnaAlmacen", $this->almacenes->idsVisibles($user, $empresa));
    }

    public function puedeVer(User $user, Enterprise $empresa, Model $registro, string $columnaAlmacen): bool
    {
        $empresaId = $registro->enterprise_id;
        if ($empresaId !== null && (int) $empresaId !== (int) $empresa->id) {
            return false;
        }

        if ($this->almacenes->puedeVerTodos($user, $empresa)) {
            return true;
        }

        return $empresaId !== null
            && $this->almacenes->puedeVer($user, $empresa, $registro->{$columnaAlmacen});
    }
}
