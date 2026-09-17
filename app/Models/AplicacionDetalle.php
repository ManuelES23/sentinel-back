<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AplicacionDetalle extends Model
{
    use HasFactory;

    protected $table = 'aplicaciones_detalle';

    protected $fillable = [
        'aplicacion_id',
        'producto_id',
        'product_id',
        'dosis',
        'unidad_medida',
    ];

    protected $casts = [
        'dosis' => 'decimal:4',
    ];

    // ═══════ RELACIONES ═══════

    public function aplicacion(): BelongsTo
    {
        return $this->belongsTo(Aplicacion::class, 'aplicacion_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoAplicacion::class, 'producto_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
