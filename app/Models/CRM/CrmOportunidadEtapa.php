<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fila por cada etapa por la que pasó una oportunidad. Historial
 * inmutable: sin Loggable, sin soft delete y sin endpoints de escritura.
 */
class CrmOportunidadEtapa extends Model
{
    protected $table = 'crm_oportunidad_etapas';

    protected $fillable = [
        'empresa_id',
        'oportunidad_id',
        'etapa_desde',
        'etapa_hasta',
        'cambiado_en',
        'user_id',
        'inferido',
    ];

    protected $casts = [
        'cambiado_en' => 'datetime',
        'inferido' => 'boolean',
    ];

    public function oportunidad(): BelongsTo
    {
        return $this->belongsTo(CrmOportunidad::class, 'oportunidad_id');
    }
}
