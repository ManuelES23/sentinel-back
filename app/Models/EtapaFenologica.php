<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EtapaFenologica extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'etapas_fenologicas';

    protected $fillable = [
        'cultivo_id',
        'nombre',
        'orden',
        'descripcion',
        'color',
        'is_active',
    ];

    protected $casts = [
        'orden' => 'integer',
        'is_active' => 'boolean',
    ];

    // ── Relaciones ──

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class);
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCultivo($query, $cultivoId)
    {
        return $query->where('cultivo_id', $cultivoId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('orden')->orderBy('nombre');
    }
}
