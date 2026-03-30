<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CierreCosecha extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'cierres_cosecha';

    protected $fillable = [
        'temporada_id',
        'etapa_id',
        'lote_id',
        'productor_id',
        'zona_cultivo_id',
        'fecha_inicio',
        'fecha_cierre',
        'total_cajas',
        'total_bultos',
        'total_batangas',
        'total_salidas',
        'total_peso_kg',
        'rendimiento_hectarea',
        'rendimiento_kg_ha',
        'superficie_cosechada',
        'observaciones',
        'status',
        'cerrado_por',
        'created_by',
    ];

    protected $casts = [
        'total_cajas' => 'integer',
        'total_bultos' => 'integer',
        'total_batangas' => 'integer',
        'total_salidas' => 'integer',
        'total_peso_kg' => 'decimal:2',
        'rendimiento_hectarea' => 'decimal:2',
        'rendimiento_kg_ha' => 'decimal:2',
        'superficie_cosechada' => 'decimal:4',
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_cierre' => 'date:Y-m-d',
    ];

    // ── Relaciones ──────────────────────────────────────

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class);
    }

    public function zonaCultivo(): BelongsTo
    {
        return $this->belongsTo(ZonaCultivo::class);
    }

    public function cerradoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(VentaCosecha::class, 'cierre_cosecha_id');
    }

    // ── Scopes ──────────────────────────────────────────

    public function scopeByTemporada($query, $temporadaId)
    {
        return $query->where('temporada_id', $temporadaId);
    }

    public function scopeAbiertos($query)
    {
        return $query->where('status', 'abierto');
    }

    public function scopeCerrados($query)
    {
        return $query->where('status', 'cerrado');
    }
}
