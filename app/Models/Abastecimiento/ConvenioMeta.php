<?php

namespace App\Models\Abastecimiento;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta semanal de un convenio. `semana` es el lunes de la semana ISO (Y-m-d).
 * Es una fila de apoyo que se reemplaza en bloque: sin borrado lógico.
 */
class ConvenioMeta extends Model
{
    use HasFactory;

    protected $table = 'abs_convenio_metas';

    /** Columna virtual de la base (ifnull(calibre_id, 0)) para el índice único: nunca se escribe ni se expone. */
    protected $hidden = ['calibre_clave'];

    protected $fillable = [
        'convenio_id',
        'calibre_id',
        'semana',
        'cantidad',
    ];

    protected $casts = [
        'convenio_id' => 'integer',
        'calibre_id' => 'integer',
        'semana' => 'date:Y-m-d',
        'cantidad' => 'decimal:2',
    ];

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class, 'convenio_id');
    }

    public function calibre(): BelongsTo
    {
        return $this->belongsTo(VariedadCalibre::class, 'calibre_id');
    }
}
