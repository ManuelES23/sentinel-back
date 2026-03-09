<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Etapa extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'etapas';

    protected $fillable = [
        'lote_id',
        'nombre',
        'codigo',
        'superficie',
        'variedad_id',
        'tipo_variedad_id',
        'fecha_siembra_estimada',
        'fecha_cosecha_estimada',
        'fecha_siembra_real',
        'fecha_cosecha_proyectada',
        'descripcion',
        'orden',
        'is_active',
    ];

    protected $casts = [
        'superficie' => 'decimal:4',
        'orden' => 'integer',
        'is_active' => 'boolean',
        'fecha_siembra_estimada' => 'date',
        'fecha_cosecha_estimada' => 'date',
        'fecha_siembra_real' => 'date',
        'fecha_cosecha_proyectada' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class);
    }

    public function tipoVariedad(): BelongsTo
    {
        return $this->belongsTo(TipoVariedad::class);
    }

    public function visitaCampoDetalles(): HasMany
    {
        return $this->hasMany(VisitaCampoDetalle::class);
    }

    // ── Scopes ──────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByLote($query, $loteId)
    {
        return $query->where('lote_id', $loteId);
    }

    // ── Helpers ─────────────────────────────────────────

    /**
     * Calcula la superficie restante disponible en el lote
     * (superficie del lote - suma de etapas existentes)
     */
    public static function superficieDisponible(int $loteId, ?int $excludeId = null): float
    {
        $lote = Lote::findOrFail($loteId);
        $superficieLote = (float) ($lote->superficie ?? 0);

        $query = self::where('lote_id', $loteId)->whereNull('deleted_at');
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        $superficieUsada = (float) $query->sum('superficie');

        return max(0, $superficieLote - $superficieUsada);
    }
}
