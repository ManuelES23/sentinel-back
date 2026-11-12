<?php

namespace App\Models\Abastecimiento;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Calibre pactado en un convenio, con su volumen total opcional.
 * Fila de apoyo que se reemplaza en bloque: sin borrado lógico.
 */
class ConvenioCalibre extends Model
{
    use HasFactory;

    protected $table = 'abs_convenio_calibres';

    protected $fillable = [
        'convenio_id',
        'calibre_id',
        'volumen_total',
    ];

    protected $casts = [
        'convenio_id' => 'integer',
        'calibre_id' => 'integer',
        'volumen_total' => 'decimal:2',
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
