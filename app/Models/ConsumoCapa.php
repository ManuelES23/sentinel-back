<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Porción que una salida tomó de una capa (permite devolver exactamente). */
class ConsumoCapa extends Model
{
    protected $table = 'inventory_cost_layer_consumptions';

    protected $fillable = ['layer_id', 'movement_id', 'movement_detail_id', 'referencia', 'cantidad', 'unit_cost', 'cantidad_devuelta'];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'unit_cost' => 'decimal:6',
        'cantidad_devuelta' => 'decimal:4',
    ];

    public function capa(): BelongsTo
    {
        return $this->belongsTo(CapaCosto::class, 'layer_id');
    }
}
