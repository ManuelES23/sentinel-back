<?php

namespace App\Models\Planeacion;

use App\Models\Cultivo;
use App\Models\Variedad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Grupo de balance: variedades que se planean juntas (una variedad va en un
 * solo grupo). Una variedad sin grupo forma un grupo implícito ella sola.
 */
class GrupoBalance extends Model
{
    protected $table = 'plan_grupos_balance';

    protected $fillable = ['cultivo_id', 'nombre', 'orden'];

    protected $casts = [
        'cultivo_id' => 'integer',
        'orden' => 'integer',
    ];

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class);
    }

    public function variedades(): BelongsToMany
    {
        return $this->belongsToMany(Variedad::class, 'plan_grupo_variedad', 'grupo_id', 'variedad_id');
    }
}
