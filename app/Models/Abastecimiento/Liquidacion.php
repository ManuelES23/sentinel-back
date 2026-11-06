<?php

namespace App\Models\Abastecimiento;

use App\Models\User;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Liquidación a un productor por las recepciones de un convenio en un periodo. */
class Liquidacion extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    public const STATUSES = ['borrador', 'en_revision', 'aprobada', 'pagada', 'cancelada'];
    public const ORIGENES = ['normal', 'carga_inicial'];

    protected $table = 'abs_liquidaciones';

    protected $fillable = [
        'enterprise_id',
        'folio',
        'convenio_id',
        'productor_id',
        'moneda',
        'periodo_inicio',
        'periodo_fin',
        'cantidad',
        'kilos',
        'bruto',
        'rezaga_real_pct',
        'rezaga_aceptada_pct',
        'descuento',
        'monto_calculado',
        'monto_ajustado',
        'motivo_ajuste',
        'monto_final',
        'status',
        'origen',
        'paso_actual',
        'ronda',
        'enviada_at',
        'enviada_by',
        'aprobada_at',
        'aprobada_by',
        'rechazo_motivo',
        'rechazada_at',
        'rechazada_by',
        'cancelada_at',
        'cancelada_by',
        'cancelada_motivo',
        'notas',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'borrador',
        'origen' => 'normal',
        'ronda' => 0,
    ];

    protected $casts = [
        'enterprise_id' => 'integer',
        'convenio_id' => 'integer',
        'productor_id' => 'integer',
        'periodo_inicio' => 'date:Y-m-d',
        'periodo_fin' => 'date:Y-m-d',
        'cantidad' => 'decimal:2',
        'kilos' => 'decimal:2',
        'bruto' => 'decimal:2',
        'rezaga_real_pct' => 'decimal:4',
        'rezaga_aceptada_pct' => 'decimal:2',
        'descuento' => 'decimal:2',
        'monto_calculado' => 'decimal:2',
        'monto_ajustado' => 'decimal:2',
        'monto_final' => 'decimal:2',
        'paso_actual' => 'integer',
        'ronda' => 'integer',
        'enviada_at' => 'datetime',
        'enviada_by' => 'integer',
        'aprobada_at' => 'datetime',
        'aprobada_by' => 'integer',
        'rechazada_at' => 'datetime',
        'rechazada_by' => 'integer',
        'cancelada_at' => 'datetime',
        'cancelada_by' => 'integer',
        'created_by' => 'integer',
    ];

    public function scopeDeEmpresa(Builder $q, int $empresaId): Builder
    {
        return $q->where($this->getTable().'.enterprise_id', $empresaId);
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class, 'convenio_id');
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class, 'productor_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(LiquidacionDetalle::class, 'liquidacion_id');
    }

    public function aprobaciones(): HasMany
    {
        return $this->hasMany(LiquidacionAprobacion::class, 'liquidacion_id');
    }

    /** Recepciones ligadas por `abs_recepciones.liquidacion_id` (desde que es borrador). */
    public function recepciones(): HasMany
    {
        return $this->hasMany(Recepcion::class, 'liquidacion_id');
    }

    public function aplicaciones(): HasMany
    {
        return $this->hasMany(AbonoAplicacion::class, 'liquidacion_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
