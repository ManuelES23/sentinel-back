<?php

namespace App\Models\Planeacion;

use App\Models\Etapa;
use App\Models\Temporada;
use App\Models\User;
use App\Models\Variedad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acuerdo de la junta: capa activable sobre el plan base. Tipos:
 * adelantar_etapa, mover_pedido y conseguir_producto; los campos propios de
 * cada tipo son nulos en los demás.
 */
class ProgramaAcuerdo extends Model
{
    protected $table = 'programa_acuerdos';

    protected $fillable = [
        'programa_id',
        'tipo',
        'activo',
        'descripcion',
        'etapa_id',
        'temporada_id',
        'variedad_id',
        'dia',
        'hectareas',
        'necesidad_id',
        'dia_origen',
        'destino_lunes',
        'dia_destino',
        'cajas',
        'origen_tipo',
        'origen_texto',
        'created_by',
    ];

    protected $casts = [
        'programa_id' => 'integer',
        'activo' => 'boolean',
        'etapa_id' => 'integer',
        'temporada_id' => 'integer',
        'variedad_id' => 'integer',
        'dia' => 'integer',
        'hectareas' => 'decimal:2',
        'necesidad_id' => 'integer',
        'dia_origen' => 'integer',
        'destino_lunes' => 'date:Y-m-d',
        'dia_destino' => 'integer',
        'cajas' => 'integer',
    ];

    public function programa(): BelongsTo
    {
        return $this->belongsTo(ProgramaSemanal::class, 'programa_id');
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class);
    }

    public function necesidad(): BelongsTo
    {
        return $this->belongsTo(ProgramaNecesidad::class, 'necesidad_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
