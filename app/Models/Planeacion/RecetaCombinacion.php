<?php

namespace App\Models\Planeacion;

use App\Models\Recipe;
use App\Models\Variedad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Receta de empaque de una combinación (presentación, tipo de empaque) y,
 * opcionalmente, de una variedad concreta (variedad_id nulo = cualquiera).
 */
class RecetaCombinacion extends Model
{
    protected $table = 'plan_recetas_combinacion';

    protected $fillable = ['presentacion_id', 'tipo_empaque_id', 'variedad_id', 'recipe_id'];

    protected $casts = [
        'presentacion_id' => 'integer',
        'tipo_empaque_id' => 'integer',
        'variedad_id' => 'integer',
        'recipe_id' => 'integer',
    ];

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(Presentacion::class, 'presentacion_id');
    }

    public function tipoEmpaque(): BelongsTo
    {
        return $this->belongsTo(TipoEmpaque::class, 'tipo_empaque_id');
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class);
    }

    public function receta(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }
}
