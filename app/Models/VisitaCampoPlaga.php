<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitaCampoPlaga extends Model
{
    use HasFactory;

    protected $table = 'visita_campo_plagas';

    protected $fillable = [
        'visita_campo_detalle_id',
        'plaga_id',
        'severidad',
        'area_afectada_porcentaje',
        'observaciones',
    ];

    protected $casts = [
        'area_afectada_porcentaje' => 'decimal:2',
    ];

    // ── Relaciones ──

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(VisitaCampoDetalle::class, 'visita_campo_detalle_id');
    }

    public function plaga(): BelongsTo
    {
        return $this->belongsTo(Plaga::class);
    }
}
