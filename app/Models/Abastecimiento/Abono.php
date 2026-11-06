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

/** Pago o anticipo a un productor; se aplica (total o parcialmente) a liquidaciones aprobadas. */
class Abono extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    public const STATUSES = ['aplicado', 'cancelado'];
    public const METODOS_PAGO = ['transferencia', 'cheque', 'efectivo', 'otro'];

    protected $table = 'abs_abonos';

    protected $fillable = [
        'enterprise_id',
        'folio',
        'productor_id',
        'convenio_id',
        'fecha',
        'monto',
        'moneda',
        'metodo_pago',
        'referencia',
        'notas',
        'status',
        'cancelado_motivo',
        'cancelado_by',
        'cancelado_at',
        'aplicaciones_canceladas',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'aplicado',
    ];

    protected $casts = [
        'enterprise_id' => 'integer',
        'productor_id' => 'integer',
        'convenio_id' => 'integer',
        'fecha' => 'date:Y-m-d',
        'monto' => 'decimal:2',
        'cancelado_by' => 'integer',
        'cancelado_at' => 'datetime',
        'aplicaciones_canceladas' => 'array',
        'created_by' => 'integer',
    ];

    public function scopeDeEmpresa(Builder $q, int $empresaId): Builder
    {
        return $q->where($this->getTable().'.enterprise_id', $empresaId);
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class, 'productor_id');
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class, 'convenio_id');
    }

    public function aplicaciones(): HasMany
    {
        return $this->hasMany(AbonoAplicacion::class, 'abono_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
