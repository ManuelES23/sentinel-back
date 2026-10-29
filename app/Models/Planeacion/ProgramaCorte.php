<?php

namespace App\Models\Planeacion;

use App\Models\Etapa;
use App\Models\Temporada;
use App\Models\Variedad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de corte: hectáreas de una variedad que se cortan un día de la
 * semana, de preferencia ligadas a una etapa del plan de siembra.
 */
class ProgramaCorte extends Model
{
    protected $table = 'programa_cortes';

    protected $fillable = [
        'programa_id',
        'temporada_id',
        'etapa_id',
        'variedad_id',
        'dia',
        'hectareas',
        'notas',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'programa_id' => 'integer',
        'temporada_id' => 'integer',
        'etapa_id' => 'integer',
        'variedad_id' => 'integer',
        'dia' => 'integer',
        'hectareas' => 'decimal:2',
    ];

    public function programa(): BelongsTo
    {
        return $this->belongsTo(ProgramaSemanal::class, 'programa_id');
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class);
    }
}
