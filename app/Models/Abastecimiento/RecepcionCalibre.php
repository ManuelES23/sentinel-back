<?php

namespace App\Models\Abastecimiento;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de una recepción por calibre: cantidad (y kilos / rezaga opcionales) de un calibre.
 * Los totales de la recepción son la suma de sus renglones. Sin borrado lógico ni bitácora
 * propia (la lleva la cabecera); se borran en cascada con la recepción.
 */
class RecepcionCalibre extends Model
{
    use HasFactory;

    protected $table = 'abs_recepcion_calibres';

    protected $fillable = [
        'recepcion_id',
        'calibre_id',
        'cantidad',
        'kilos',
        'rezaga_kg',
    ];

    protected $casts = [
        'recepcion_id' => 'integer',
        'calibre_id' => 'integer',
        'cantidad' => 'decimal:2',
        'kilos' => 'decimal:2',
        'rezaga_kg' => 'decimal:2',
    ];

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(Recepcion::class, 'recepcion_id');
    }

    /** Con borrados: un calibre dado de baja sigue mostrando su nombre en recepciones viejas. */
    public function calibre(): BelongsTo
    {
        return $this->belongsTo(VariedadCalibre::class, 'calibre_id')->withTrashed();
    }
}
