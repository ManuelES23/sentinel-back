<?php

namespace App\Models\Planeacion;

use App\Models\Temporada;
use App\Models\Variedad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rendimiento elegido para una semana, por temporada y variedad:
 * mínimo, medio, máximo o un valor exacto.
 */
class ProgramaRendimiento extends Model
{
    protected $table = 'programa_rendimientos';

    protected $fillable = ['programa_id', 'temporada_id', 'variedad_id', 'modo', 'valor_exacto'];

    protected $casts = [
        'programa_id' => 'integer',
        'temporada_id' => 'integer',
        'variedad_id' => 'integer',
        'valor_exacto' => 'decimal:2',
    ];

    public function programa(): BelongsTo
    {
        return $this->belongsTo(ProgramaSemanal::class, 'programa_id');
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class);
    }
}
