<?php

namespace App\Models\Planeacion;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial de cambios del programa posteriores a la junta. Solo tiene
 * created_at (la BD lo llena si no se indica).
 */
class ProgramaCambio extends Model
{
    public $timestamps = false;

    protected $table = 'programa_cambios';

    protected $fillable = [
        'programa_id',
        'entidad',
        'entidad_id',
        'accion',
        'antes',
        'despues',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'programa_id' => 'integer',
        'entidad_id' => 'integer',
        'antes' => 'array',
        'despues' => 'array',
        'user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function programa(): BelongsTo
    {
        return $this->belongsTo(ProgramaSemanal::class, 'programa_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
