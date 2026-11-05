<?php

namespace App\Models\Abastecimiento;

use App\Models\User;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Recepción de producto contra un convenio. */
class Recepcion extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'abs_recepciones';

    protected $fillable = [
        'enterprise_id',
        'folio',
        'convenio_id',
        'fecha',
        'tipo_carga_id',
        'cantidad',
        'kilos',
        'rezaga_kg',
        'ticket_proveedor',
        'notas',
        'liquidacion_id',
        'created_by',
    ];

    protected $casts = [
        'enterprise_id' => 'integer',
        'convenio_id' => 'integer',
        'tipo_carga_id' => 'integer',
        'fecha' => 'date:Y-m-d',
        'cantidad' => 'decimal:2',
        'kilos' => 'decimal:2',
        'rezaga_kg' => 'decimal:2',
        'liquidacion_id' => 'integer',
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

    public function tipoCarga(): BelongsTo
    {
        return $this->belongsTo(TipoCarga::class, 'tipo_carga_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Una recepción ligada a una liquidación ya no se puede editar ni borrar. */
    public function estaLiquidada(): bool
    {
        return $this->liquidacion_id !== null;
    }
}
