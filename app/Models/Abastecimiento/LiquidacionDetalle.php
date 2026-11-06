<?php

namespace App\Models\Abastecimiento;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Renglón de una liquidación: una recepción con su precio y su importe. */
class LiquidacionDetalle extends Model
{
    use HasFactory;

    protected $table = 'abs_liquidacion_detalles';

    protected $fillable = [
        'liquidacion_id',
        'recepcion_id',
        'cantidad',
        'kilos',
        'rezaga_kg',
        'precio',
        'porcentaje_productor',
        'subtotal',
        'descuento',
        'a_pagar',
    ];

    protected $casts = [
        'liquidacion_id' => 'integer',
        'recepcion_id' => 'integer',
        'cantidad' => 'decimal:2',
        'kilos' => 'decimal:2',
        'rezaga_kg' => 'decimal:2',
        'precio' => 'decimal:4',
        'porcentaje_productor' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'a_pagar' => 'decimal:2',
    ];

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class, 'liquidacion_id');
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(Recepcion::class, 'recepcion_id');
    }
}
