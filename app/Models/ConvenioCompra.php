<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConvenioCompra extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'convenios_compra';

    protected $fillable = [
        'folio_convenio',
        'temporada_id',
        'productor_id',
        'cultivo_id',
        'variedad_id',
        'modalidad',
        'status',
        'fecha_inicio',
        'fecha_fin',
        'notas',
        'porcentaje_rezaga',
        'calculo_por_kilos',
        'created_by',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'porcentaje_rezaga' => 'decimal:2',
        'calculo_por_kilos' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    const MODALIDAD_COMPRA_DIRECTA = 'compra_directa';
    const MODALIDAD_CONSIGNACION = 'consignacion';

    const STATUS_BORRADOR = 'borrador';
    const STATUS_ACTIVO = 'activo';
    const STATUS_SUSPENDIDO = 'suspendido';
    const STATUS_FINALIZADO = 'finalizado';

    // ─── Relaciones ───────────────────────────────────

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class);
    }

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class);
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function precios(): HasMany
    {
        return $this->hasMany(ConvenioCompraPrecio::class);
    }

    // ─── Scopes ───────────────────────────────────────

    public function scopeActivos($query)
    {
        return $query->where('status', self::STATUS_ACTIVO);
    }

    public function scopePorProductor($query, $productorId)
    {
        return $query->where('productor_id', $productorId);
    }

    public function scopePorCultivo($query, $cultivoId)
    {
        return $query->where('cultivo_id', $cultivoId);
    }

    public function scopePorModalidad($query, $modalidad)
    {
        return $query->where('modalidad', $modalidad);
    }

    public function scopePorTemporada($query, $temporadaId)
    {
        return $query->where('temporada_id', $temporadaId);
    }

    public function scopeVigentesEnFecha($query, $fecha = null)
    {
        $fecha = $fecha ?? now()->toDateString();
        return $query->where('fecha_inicio', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('fecha_fin')
                  ->orWhere('fecha_fin', '>=', $fecha);
            });
    }

    // ─── Helpers ──────────────────────────────────────

    public function esCompraDirecta(): bool
    {
        return $this->modalidad === self::MODALIDAD_COMPRA_DIRECTA;
    }

    public function esConsignacion(): bool
    {
        return $this->modalidad === self::MODALIDAD_CONSIGNACION;
    }

    /**
     * Obtener el precio vigente para un tipo de carga en una fecha dada
     */
    public function precioVigente($tipoCargaId = null, $fecha = null)
    {
        $fecha = $fecha ?? now()->toDateString();

        return $this->precios()
            ->where('is_active', true)
            ->where('vigencia_inicio', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('vigencia_fin')
                  ->orWhere('vigencia_fin', '>=', $fecha);
            })
            ->when($tipoCargaId, fn($q) => $q->where('tipo_carga_id', $tipoCargaId))
            ->when(!$tipoCargaId, fn($q) => $q->whereNull('tipo_carga_id'))
            ->latest('vigencia_inicio')
            ->first();
    }
}
