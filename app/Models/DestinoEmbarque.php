<?php

namespace App\Models;

use App\Models\Planeacion\BodegaDestino;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Destino de un embarque de Empaque (catálogo propio de Splendid Farms). La liga
 * con una bodega de Planeación es opcional: si existe, los embarques de este
 * destino cuentan en esa bodega en «Avance de embarques».
 */
class DestinoEmbarque extends Model
{
    protected $table = 'destinos_embarque';

    protected $fillable = ['enterprise_id', 'nombre', 'plan_bodega_destino_id', 'is_active', 'created_by'];

    protected $casts = [
        'enterprise_id' => 'integer',
        'plan_bodega_destino_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function bodegaPlaneacion(): BelongsTo
    {
        return $this->belongsTo(BodegaDestino::class, 'plan_bodega_destino_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
