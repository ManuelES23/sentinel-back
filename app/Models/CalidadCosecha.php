<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalidadCosecha extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'calidad_cosecha';

    protected $fillable = [
        'temporada_id',
        'salida_campo_cosecha_id',
        'etapa_id',
        'lote_id',
        'fecha_inspeccion',
        'tipo_inspeccion',
        'parametros',
        'resultado',
        'porcentaje_calidad',
        'observaciones',
        'inspector',
        'created_by',
    ];

    protected $casts = [
        'parametros' => 'array',
        'porcentaje_calidad' => 'decimal:2',
        'fecha_inspeccion' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function salidaCampo(): BelongsTo
    {
        return $this->belongsTo(SalidaCampoCosecha::class, 'salida_campo_cosecha_id');
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──────────────────────────────────────────

    public function scopeByTemporada($query, $temporadaId)
    {
        return $query->where('temporada_id', $temporadaId);
    }

    public function scopeByResultado($query, $resultado)
    {
        return $query->where('resultado', $resultado);
    }
}
