<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisicionCotizacionDetalle extends Model
{
    protected $table = 'requisicion_cotizacion_detalles';

    protected $fillable = [
        'cotizacion_id', 'requisicion_detalle_id', 'disponible', 'cantidad',
        'precio_unitario', 'tax_rate', 'subtotal',
    ];

    protected $casts = [
        'disponible' => 'boolean',
        'cantidad' => 'decimal:4',
        'precio_unitario' => 'decimal:4',
        'tax_rate' => 'decimal:2',
        'subtotal' => 'decimal:4',
    ];

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(RequisicionCotizacion::class, 'cotizacion_id');
    }

    public function requisicionDetalle(): BelongsTo
    {
        return $this->belongsTo(RequisicionCampoDetalle::class, 'requisicion_detalle_id');
    }
}
