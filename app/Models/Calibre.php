<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Calibre extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'cultivo_id',
        'variedad_id',
        'nombre',
        'valor',
        'descripcion',
        'is_active',
        'order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    // ── Relaciones ──────────────────────────────────────────────

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class);
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class);
    }

    // ── Scopes ──────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCultivo($query, $cultivoId)
    {
        return $query->where('cultivo_id', $cultivoId);
    }

    public function scopeByVariedad($query, $variedadId)
    {
        return $query->where('variedad_id', $variedadId);
    }
}
