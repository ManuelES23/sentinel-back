<?php

namespace App\Support\CRM;

use App\Models\CRM\CrmOportunidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Qué oportunidades puede ver el usuario cuando vienen incrustadas en otra
 * respuesta (ficha de cliente o de prospecto), en el listado de actividades o
 * cuando una escritura se cuelga de una oportunidad (incluye()). Lo arma
 * VisibilidadOportunidades::alcance(); nunca aborta.
 *
 * - Sin oportunidades.ver: ninguna.
 * - Con oportunidades.equipo: todas las de la empresa.
 * - Si no: solo las de su CrmVendedor activo; sin vendedor vinculado, ninguna.
 */
final class AlcanceOportunidades
{
    public function __construct(
        public readonly int $empresaId,
        public readonly bool $puedeVer,
        public readonly bool $todas,
        public readonly ?int $vendedorId,
    ) {}

    public function ninguna(): bool
    {
        return ! $this->puedeVer || (! $this->todas && $this->vendedorId === null);
    }

    /** Si ESTA oportunidad entra en el alcance (misma regla que aplicar()). */
    public function incluye(CrmOportunidad $oportunidad): bool
    {
        if ($this->ninguna() || (int) $oportunidad->empresa_id !== $this->empresaId) {
            return false;
        }

        return $this->todas || (int) $oportunidad->vendedor_id === $this->vendedorId;
    }

    /**
     * Limita una consulta (o relación) de crm_oportunidades a lo visible.
     * Siempre filtra por la empresa, aunque la relación ya venga de un
     * registro de esa empresa.
     *
     * @template T of Builder|Relation
     *
     * @param  T  $query
     * @return T
     */
    public function aplicar(Builder|Relation $query): Builder|Relation
    {
        if ($this->ninguna()) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        $query->where('crm_oportunidades.empresa_id', $this->empresaId);

        if (! $this->todas) {
            $query->where('crm_oportunidades.vendedor_id', $this->vendedorId);
        }

        return $query;
    }
}
