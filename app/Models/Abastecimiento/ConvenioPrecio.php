<?php

namespace App\Models\Abastecimiento;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Precio de un tipo de carga dentro de un convenio, con su vigencia. */
class ConvenioPrecio extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'abs_convenio_precios';

    /** Columna virtual de la base (ifnull(calibre_id, 0)) para el índice único: nunca se escribe ni se expone. */
    protected $hidden = ['calibre_clave'];

    protected $fillable = [
        'convenio_id',
        'calibre_id',
        'tipo_carga_id',
        'precio',
        'porcentaje_productor',
        'vigencia_inicio',
        'es_principal',
        'notas',
    ];

    protected $casts = [
        'convenio_id' => 'integer',
        'calibre_id' => 'integer',
        'tipo_carga_id' => 'integer',
        'precio' => 'decimal:4',
        'porcentaje_productor' => 'decimal:2',
        'vigencia_inicio' => 'date:Y-m-d',
        'es_principal' => 'boolean',
    ];

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class, 'convenio_id');
    }

    public function calibre(): BelongsTo
    {
        return $this->belongsTo(VariedadCalibre::class, 'calibre_id');
    }

    public function tipoCarga(): BelongsTo
    {
        return $this->belongsTo(TipoCarga::class, 'tipo_carga_id');
    }
}
