<?php

namespace App\Models\Planeacion;

use App\Models\Cultivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Viaje planeado: un Full lleva dos cajas de tráiler (cajas_caja_1 y
 * cajas_caja_2) y un Sencillo una sola (cajas_caja_1).
 */
class ProgramaViaje extends Model
{
    protected $table = 'programa_viajes';

    protected $fillable = [
        'programa_id',
        'dia',
        'bodega_id',
        'cultivo_id',
        'tipo',
        'cajas_caja_1',
        'cajas_caja_2',
        'notas',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'programa_id' => 'integer',
        'dia' => 'integer',
        'bodega_id' => 'integer',
        'cultivo_id' => 'integer',
        'cajas_caja_1' => 'integer',
        'cajas_caja_2' => 'integer',
    ];

    public function programa(): BelongsTo
    {
        return $this->belongsTo(ProgramaSemanal::class, 'programa_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(BodegaDestino::class, 'bodega_id');
    }

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class);
    }
}
