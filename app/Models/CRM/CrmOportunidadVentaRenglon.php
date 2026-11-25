<?php

namespace App\Models\CRM;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de la venta: copia de lo cotizado más lo que realmente se vendió
 * (o el motivo por el que no) — spec 2026-11-25 §2.
 */
class CrmOportunidadVentaRenglon extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_oportunidad_venta_renglones';

    protected $fillable = [
        'venta_id', 'cotizacion_linea_id', 'producto_id', 'descripcion', 'calibre',
        'presentacion', 'cantidad_cotizada', 'precio_cotizado', 'vendido',
        'cantidad_vendida', 'precio_vendido', 'motivo_rechazo_id', 'nota',
    ];

    protected $casts = [
        'cantidad_cotizada' => 'decimal:4',
        'precio_cotizado' => 'decimal:2',
        'vendido' => 'boolean',
        'cantidad_vendida' => 'integer',
        'precio_vendido' => 'decimal:2',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(CrmOportunidadVenta::class, 'venta_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(CrmProducto::class, 'producto_id');
    }

    public function motivoRechazo(): BelongsTo
    {
        return $this->belongsTo(CrmMotivoRechazo::class, 'motivo_rechazo_id');
    }
}
