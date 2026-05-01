<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoCarga extends Model
{
    use HasFactory, Loggable;

    protected $table = 'tipos_carga';

    protected $fillable = [
        'cultivo_id',
        'nombre',
        'categoria_caja',
        'peso_estimado_kg',
        'descripcion',
        'is_active',
    ];

    protected $casts = [
        'peso_estimado_kg' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // ── Relaciones ──────────────────────────────────────

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class);
    }

    public function salidasCampo(): HasMany
    {
        return $this->hasMany(SalidaCampoCosecha::class, 'tipo_carga_id');
    }

    // ── Scopes ──────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCultivo($query, $cultivoId)
    {
        return $query->where('cultivo_id', $cultivoId);
    }
}
