<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Etiqueta de actividad de una empresa (spec 2026-10-30 §6), p. ej.
 * «Seguimiento». Clasifica los eventos de la agenda y las actividades.
 * Sin SoftDeletes: se desactiva en lugar de borrarse.
 */
class CrmEtiquetaActividad extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_etiquetas_actividad';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'orden',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    /** Mismos valores por defecto que la tabla, para que la respuesta de store() los incluya. */
    protected $attributes = [
        'activo' => true,
        'orden' => 0,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function scopeActivas($query)
    {
        return $query->where('activo', true);
    }
}
