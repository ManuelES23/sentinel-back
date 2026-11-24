<?php

namespace App\Models\Planeacion;

use Illuminate\Database\Eloquent\Model;

/**
 * Bodega de destino del Programa semanal (Texas, Nogales, Mexicali, Nacional).
 * Es un catálogo propio de Splendid Farms, distinto de crm_bodegas.
 */
class BodegaDestino extends Model
{
    protected $table = 'plan_bodegas_destino';

    protected $fillable = ['nombre', 'orden', 'is_active', 'dias_antelacion', 'cajas_full', 'cajas_sencillo'];

    protected $casts = [
        'orden' => 'integer',
        'dias_antelacion' => 'integer',
        'cajas_full' => 'integer',
        'cajas_sencillo' => 'integer',
        'is_active' => 'boolean',
    ];
}
