<?php

namespace App\Models\Abastecimiento;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Convenio de abastecimiento entre la empresa y un productor o empaque. */
class Convenio extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    public const MODALIDADES = ['compra_directa', 'consignacion'];
    public const UNIDADES = ['caja', 'kg'];
    public const MONEDAS = ['MXN', 'USD'];
    public const MODOS_VOLUMEN = ['total', 'semanal'];
    public const STATUSES = ['borrador', 'activo', 'suspendido', 'finalizado', 'cancelado'];

    protected $table = 'abs_convenios';

    protected $fillable = [
        'enterprise_id',
        'folio',
        'temporada_id',
        'productor_id',
        'cultivo_id',
        'variedad_id',
        'modalidad',
        'unidad',
        'moneda',
        'porcentaje_rezaga',
        'fecha_inicio',
        'fecha_fin',
        'modo_volumen',
        'usa_calibres',
        'volumen_total',
        'status',
        'notas',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'borrador',
        'porcentaje_rezaga' => 0,
        'usa_calibres' => false,
    ];

    protected $casts = [
        'enterprise_id' => 'integer',
        'temporada_id' => 'integer',
        'productor_id' => 'integer',
        'cultivo_id' => 'integer',
        'variedad_id' => 'integer',
        'porcentaje_rezaga' => 'decimal:2',
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_fin' => 'date:Y-m-d',
        'volumen_total' => 'decimal:2',
        'usa_calibres' => 'boolean',
        'created_by' => 'integer',
    ];

    public function scopeDeEmpresa(Builder $q, int $empresaId): Builder
    {
        return $q->where($this->getTable().'.enterprise_id', $empresaId);
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class, 'temporada_id');
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class, 'productor_id');
    }

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class, 'cultivo_id');
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class, 'variedad_id');
    }

    public function precios(): HasMany
    {
        return $this->hasMany(ConvenioPrecio::class, 'convenio_id');
    }

    public function metas(): HasMany
    {
        return $this->hasMany(ConvenioMeta::class, 'convenio_id');
    }

    public function calibres(): HasMany
    {
        return $this->hasMany(ConvenioCalibre::class, 'convenio_id');
    }

    public function recepciones(): HasMany
    {
        return $this->hasMany(Recepcion::class, 'convenio_id');
    }

    public function liquidaciones(): HasMany
    {
        return $this->hasMany(Liquidacion::class, 'convenio_id');
    }
}
