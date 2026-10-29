<?php

namespace App\Models\Planeacion;

use App\Models\Cultivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Presentación de un cultivo en el Programa semanal (Bulk, Tray, Minicobs…).
 */
class Presentacion extends Model
{
    protected $table = 'plan_presentaciones';

    protected $fillable = ['cultivo_id', 'nombre', 'orden', 'is_active'];

    protected $casts = [
        'cultivo_id' => 'integer',
        'orden' => 'integer',
        'is_active' => 'boolean',
    ];

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class);
    }
}
