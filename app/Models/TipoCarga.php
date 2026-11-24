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

    /** Clases de bin de rezaga (Elote en bins); null = tipo de carga normal. */
    public const CLASES_REZAGA = ['hoja', 'fresco', 'cuarto_frio'];

    protected $fillable = [
        'cultivo_id',
        'nombre',
        'categoria_caja',
        'peso_estimado_kg',
        'clase_rezaga',
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

    // ── Rezaga en bins ──────────────────────────────────

    /**
     * Tipo y subtipo de rezaga que le corresponden a la clase de este tipo de
     * carga, o null si no tiene clase (o es desconocida).
     */
    public function mapeoRezaga(): ?array
    {
        return match ($this->clase_rezaga) {
            'hoja' => ['tipo_rezaga' => 'produccion', 'subtipo_rezaga' => 'hoja'],
            'fresco' => ['tipo_rezaga' => 'produccion', 'subtipo_rezaga' => 'producto'],
            'cuarto_frio' => ['tipo_rezaga' => 'cuarto_frio', 'subtipo_rezaga' => 'producto'],
            default => null,
        };
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
