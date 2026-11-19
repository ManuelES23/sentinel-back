<?php

namespace App\Models\Planeacion;

use App\Models\Cultivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Embarque planeado por cargas: cuántas cargas (Full o Sencillo) de una presentación
 * salen un día hacia una bodega. Una sola fila por programa · cultivo · bodega ·
 * presentación · tipo · día (presentación nula = capturado antes de pedirla).
 */
class ProgramaEmbarque extends Model
{
    protected $table = 'programa_embarques';

    protected $fillable = [
        'programa_id',
        'cultivo_id',
        'bodega_id',
        'presentacion_id',
        'tipo',
        'dia',
        'cargas',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'programa_id' => 'integer',
        'cultivo_id' => 'integer',
        'bodega_id' => 'integer',
        'presentacion_id' => 'integer',
        'dia' => 'integer',
        'cargas' => 'integer',
    ];

    public function programa(): BelongsTo
    {
        return $this->belongsTo(ProgramaSemanal::class, 'programa_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(BodegaDestino::class, 'bodega_id');
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(Presentacion::class, 'presentacion_id');
    }

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class);
    }
}
