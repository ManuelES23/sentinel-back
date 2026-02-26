<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lote extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'lotes';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'numero_lote',
        'productor_id',
        'zona_cultivo_id',
        'nombre',
        'codigo',
        'superficie',
        'coordenadas',
        'centro_lat',
        'centro_lng',
        'superficie_calculada',
        'tipo_suelo',
        'sistema_riego',
        'descripcion',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'numero_lote' => 'integer',
        'productor_id' => 'integer',
        'superficie' => 'decimal:2',
        'coordenadas' => 'array',
        'centro_lat' => 'decimal:7',
        'centro_lng' => 'decimal:7',
        'superficie_calculada' => 'decimal:4',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Boot del modelo - generar numero_lote automáticamente
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($lote) {
            // Si no tiene numero_lote asignado, generar el siguiente
            if (!$lote->numero_lote) {
                $lote->numero_lote = self::generarSiguienteNumeroLote();
            }
        });
    }

    /**
     * Generar el siguiente número de lote
     */
    public static function generarSiguienteNumeroLote(): int
    {
        $ultimoNumero = self::withTrashed()->max('numero_lote') ?? 0;
        return $ultimoNumero + 1;
    }

    /**
     * Get the productor that owns the lote.
     */
    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class, 'productor_id');
    }

    /**
     * Get the zona de cultivo.
     */
    public function zonaCultivo(): BelongsTo
    {
        return $this->belongsTo(ZonaCultivo::class, 'zona_cultivo_id');
    }

    /**
     * Temporadas en las que este lote ha sido usado
     */
    public function temporadas()
    {
        return $this->belongsToMany(Temporada::class, 'temporada_lote')
            ->withPivot('cultivo_id', 'superficie_sembrada', 'fecha_siembra', 'fecha_cosecha_estimada', 'notas', 'is_active')
            ->withTimestamps();
    }

    /**
     * Obtener el cultivo sembrado en una temporada específica
     */
    public function cultivoEnTemporada($temporadaId)
    {
        $pivot = $this->temporadas()->where('temporada_id', $temporadaId)->first();
        if ($pivot && isset($pivot->pivot->cultivo_id)) {
            /** @var Cultivo|null */
            /** @phpstan-ignore-next-line */
            return Cultivo::find($pivot->pivot->cultivo_id);
        }
        return null;
    }

    /**
     * Scope para lotes activos.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para lotes de un productor específico.
     */
    public function scopeByProductor($query, $productorId)
    {
        return $query->where('productor_id', $productorId);
    }

    /**
     * Get código completo con número de lote.
     */
    public function getCodigoCompletoAttribute(): string
    {
        $productor = $this->productor;
        $prefijo = $productor ? strtoupper(substr($productor->nombre, 0, 3)) : 'LOT';
        return "{$prefijo}-{$this->numero_lote}";
    }

    /**
     * Get nombre completo con productor.
     */
    public function getNombreCompletoAttribute(): string
    {
        $productor = $this->productor;
        return "{$productor?->nombre} / {$this->nombre} (#{$this->numero_lote})";
    }

    /**
     * Get superficie efectiva (calculada del mapa o manual).
     * Prioriza superficie_calculada si existe.
     */
    public function getSuperficieEfectivaAttribute(): ?float
    {
        if ($this->superficie_calculada && $this->superficie_calculada > 0) {
            return floatval($this->superficie_calculada);
        }
        return $this->superficie ? floatval($this->superficie) : null;
    }

    /**
     * Indica si el lote tiene ubicación en mapa.
     */
    public function getTieneUbicacionAttribute(): bool
    {
        return !empty($this->coordenadas) && count($this->coordenadas) >= 3;
    }

    /**
     * Get la fuente de la superficie (mapa o manual).
     */
    public function getFuenteSuperficieAttribute(): ?string
    {
        if ($this->superficie_calculada && $this->superficie_calculada > 0) {
            return 'mapa';
        }
        if ($this->superficie && $this->superficie > 0) {
            return 'manual';
        }
        return null;
    }
}
