<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalidaCampoCosecha extends Model
{
    use HasFactory, Loggable;

    protected $table = 'salidas_campo_cosecha';

    protected $fillable = [
        'temporada_id',
        'etapa_id',
        'variedad_id',
        'convenio_compra_id',
        'lote_id',
        'tipo_carga_id',
        'productor_id',
        'zona_cultivo_id',
        'destino_entity_id',
        'fecha',
        'hora_salida',
        'cantidad',
        'peso_neto_kg',
        'peso_bascula',
        'folio_ticket_bascula',
        'folio_salida',
        'vehiculo',
        'chofer',
        'observaciones',
        'es_batanga',
        'status',
        'eliminado',
        'created_by',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'peso_neto_kg' => 'decimal:2',
        'peso_bascula' => 'decimal:2',
        'es_batanga' => 'boolean',
        'eliminado' => 'boolean',
        'fecha' => 'date:Y-m-d',
    ];

    protected $appends = ['peso_calculado'];

    // ── Relaciones ──────────────────────────────────────

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class);
    }

    public function convenioCompra(): BelongsTo
    {
        return $this->belongsTo(ConvenioCompra::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function tipoCarga(): BelongsTo
    {
        return $this->belongsTo(TipoCarga::class);
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class);
    }

    public function zonaCultivo(): BelongsTo
    {
        return $this->belongsTo(ZonaCultivo::class);
    }

    public function destinoEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'destino_entity_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recepciones(): HasMany
    {
        return $this->hasMany(RecepcionEmpaque::class, 'salida_campo_id');
    }

    public function calidadInspecciones(): HasMany
    {
        return $this->hasMany(CalidadCosecha::class, 'salida_campo_cosecha_id');
    }

    // ── Accessors ───────────────────────────────────────

    public function getPesoCalculadoAttribute(): ?float
    {
        if ($this->cantidad && $this->tipoCarga) {
            return $this->cantidad * $this->tipoCarga->peso_estimado_kg;
        }
        return $this->peso_neto_kg;
    }

    // ── Scopes ──────────────────────────────────────────

    public function scopeActivos($query)
    {
        return $query->where('eliminado', false);
    }

    public function scopeByTemporada($query, $temporadaId)
    {
        return $query->where('temporada_id', $temporadaId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}
