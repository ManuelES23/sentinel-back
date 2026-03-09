<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitaCampoRecomendacion extends Model
{
    use HasFactory;

    protected $table = 'visita_campo_recomendaciones';

    protected $fillable = [
        'visita_campo_detalle_id',
        'product_id',
        'nombre_producto',
        'dosis',
        'unit_id',
        'metodo_aplicacion',
        'prioridad',
        'observaciones',
    ];

    protected $casts = [
        'dosis' => 'decimal:4',
    ];

    // ── Relaciones ──

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(VisitaCampoDetalle::class, 'visita_campo_detalle_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }
}
