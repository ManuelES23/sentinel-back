<?php

namespace App\Models\Planeacion;

use App\Models\Cultivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Embarque planeado por cargas: cuántas cargas (Full o Sencillo) salen un día
 * hacia una bodega. Una sola fila por programa · cultivo · bodega · tipo · día.
 */
class ProgramaEmbarque extends Model
{
    protected $table = 'programa_embarques';

    protected $fillable = [
        'programa_id',
        'cultivo_id',
        'bodega_id',
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

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class);
    }
}
