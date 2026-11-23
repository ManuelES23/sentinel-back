<?php

namespace App\Models\Planeacion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente de la lista propia de Planeación (Necesidades de venta). Dos nombres
 * que solo difieren en mayúsculas, acentos o espacios tienen la misma
 * `nombre_clave` (única) y son el mismo cliente; `nombre` es el canónico.
 * Se crea y resuelve con `App\Services\Planeacion\ClientesPlaneacion`.
 */
class PlanCliente extends Model
{
    protected $table = 'plan_clientes';

    protected $fillable = ['nombre', 'nombre_clave', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function necesidades(): HasMany
    {
        return $this->hasMany(ProgramaNecesidad::class, 'plan_cliente_id');
    }
}
