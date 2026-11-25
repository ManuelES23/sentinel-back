<?php

namespace App\Models\Planeacion;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto del plan acordado en la junta de una semana: los cortes
 * (`[{temporada_id, etapa_id, variedad_id, dia, hectareas}]`) y las cargas de
 * embarque (`[{cultivo_id, bodega_id, tipo, dia, cargas}]`) tal como estaban al
 * acordarlo. Puede haber varias por programa; vale la de mayor `id`. Una foto
 * no se edita (sin `updated_at`).
 */
class ProgramaPlanAcordado extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'programa_planes_acordados';

    protected $fillable = ['programa_id', 'acordado_por', 'cortes', 'embarques'];

    protected $casts = [
        'programa_id' => 'integer',
        'acordado_por' => 'integer',
        'cortes' => 'array',
        'embarques' => 'array',
    ];

    public function programa(): BelongsTo
    {
        return $this->belongsTo(ProgramaSemanal::class, 'programa_id');
    }

    public function acordadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acordado_por');
    }
}
