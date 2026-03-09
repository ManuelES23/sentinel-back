<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisicionCampoDetalle extends Model
{
    use HasFactory;

    protected $table = 'requisicion_campo_detalles';

    protected $fillable = [
        'requisicion_campo_id',
        'product_id',
        'nombre_producto',
        'cantidad',
        'unit_id',
        'precio_estimado',
        'subtotal_estimado',
        'etapa_id',
        'lote_id',
        'visita_campo_recomendacion_id',
        'observaciones',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_estimado' => 'decimal:2',
        'subtotal_estimado' => 'decimal:2',
    ];

    // ═══════ BOOT ═══════

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($detalle) {
            if ($detalle->cantidad && $detalle->precio_estimado) {
                $detalle->subtotal_estimado = $detalle->cantidad * $detalle->precio_estimado;
            }
        });
    }

    // ═══════ RELACIONES ═══════

    public function requisicion(): BelongsTo
    {
        return $this->belongsTo(RequisicionCampo::class, 'requisicion_campo_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }
}
