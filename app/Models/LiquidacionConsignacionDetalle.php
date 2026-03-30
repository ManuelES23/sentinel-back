<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiquidacionConsignacionDetalle extends Model
{
    use HasFactory;

    protected $table = 'liquidacion_consignacion_detalles';

    protected $fillable = [
        'liquidacion_id',
        'salida_campo_id',
        'tipo_carga_id',
        'concepto',
        'cantidad',
        'peso_kg',
        'precio_unitario',
        'subtotal',
        'notas',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'peso_kg' => 'decimal:2',
        'precio_unitario' => 'decimal:4',
        'subtotal' => 'decimal:2',
    ];

    // ── Relaciones ──────────────────────────────────────

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(LiquidacionConsignacion::class, 'liquidacion_id');
    }

    public function salidaCampo(): BelongsTo
    {
        return $this->belongsTo(SalidaCampoCosecha::class, 'salida_campo_id');
    }

    public function tipoCarga(): BelongsTo
    {
        return $this->belongsTo(TipoCarga::class);
    }
}
