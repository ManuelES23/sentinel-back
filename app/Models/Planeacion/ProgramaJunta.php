<?php

namespace App\Models\Planeacion;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de la junta de una semana, con la foto del pizarrón (arreglo JSON
 * de archivos). Solo tiene created_at.
 */
class ProgramaJunta extends Model
{
    public $timestamps = false;

    protected $table = 'programa_juntas';

    protected $fillable = ['programa_id', 'registrada_por', 'foto', 'created_at'];

    protected $casts = [
        'programa_id' => 'integer',
        'registrada_por' => 'integer',
        'foto' => 'array',
        'created_at' => 'datetime',
    ];

    public function programa(): BelongsTo
    {
        return $this->belongsTo(ProgramaSemanal::class, 'programa_id');
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por');
    }
}
