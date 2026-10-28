<?php

namespace App\Models\Planeacion;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de empaque del Programa semanal (RPC, Cartón, DPC).
 */
class TipoEmpaque extends Model
{
    protected $table = 'plan_tipos_empaque';

    protected $fillable = ['nombre', 'orden', 'is_active'];

    protected $casts = [
        'orden' => 'integer',
        'is_active' => 'boolean',
    ];
}
