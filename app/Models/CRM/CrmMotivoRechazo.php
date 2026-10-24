<?php

namespace App\Models\CRM;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Motivo de rechazo dentro de una categoría (spec 2026-10-23 §4.5). La
 * oportunidad perdida guarda el motivo; la categoría se deriva de él.
 */
class CrmMotivoRechazo extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_motivos_rechazo';

    protected $fillable = [
        'categoria_id',
        'nombre',
        'orden',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    protected $attributes = [
        'activo' => true,
        'orden' => 0,
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CrmCategoriaRechazo::class, 'categoria_id');
    }

    public function oportunidades(): HasMany
    {
        return $this->hasMany(CrmOportunidad::class, 'motivo_rechazo_id');
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }
}
