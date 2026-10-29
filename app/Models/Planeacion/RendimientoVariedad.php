<?php

namespace App\Models\Planeacion;

use App\Models\Temporada;
use App\Models\Variedad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rango de rendimiento (cajas por hectárea) de una variedad en una temporada.
 */
class RendimientoVariedad extends Model
{
    protected $table = 'plan_rendimientos';

    protected $fillable = ['temporada_id', 'variedad_id', 'minimo', 'maximo'];

    protected $casts = [
        'temporada_id' => 'integer',
        'variedad_id' => 'integer',
        'minimo' => 'decimal:2',
        'maximo' => 'decimal:2',
    ];

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class);
    }
}
