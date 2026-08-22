<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmOportunidad extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'crm_oportunidades';

    protected $attributes = [
        'etapa' => 'prospecto',
    ];

    /**
     * Orden numérico de etapas para validar avance unidireccional.
     * cerrado_perdido (-1) es la única regresión permitida desde cualquier etapa.
     */
    public const ORDEN_ETAPAS = [
        'prospecto'       => 0,
        'calificado'      => 1,
        'propuesta'       => 2,
        'negociacion'     => 3,
        'cerrado_ganado'  => 4,
        'cerrado_perdido' => -1,
    ];

    protected $fillable = [
        'empresa_id',
        'prospecto_id',
        'cliente_id',
        'vendedor_id',
        'nombre',
        'monto_esperado',
        'probabilidad',
        'etapa',
        'fecha_cierre_esperada',
        'notas',
        'motivo_perdida',
        'fecha_cierre_real',
    ];

    protected $casts = [
        'monto_esperado'        => 'decimal:2',
        'probabilidad'          => 'integer',
        'fecha_cierre_esperada' => 'date',
        'fecha_cierre_real'     => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(CrmVendedor::class, 'vendedor_id');
    }

    public function prospecto(): BelongsTo
    {
        return $this->belongsTo(CrmProspecto::class, 'prospecto_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(CrmCliente::class, 'cliente_id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(CrmOportunidadProducto::class, 'oportunidad_id');
    }

    public function actividades(): MorphMany
    {
        return $this->morphMany(CrmActividad::class, 'entidad');
    }

    public function scopeActivas($query)
    {
        return $query->whereNotIn('etapa', ['cerrado_ganado', 'cerrado_perdido']);
    }

    public function scopePorVendedor($query, int $vendedorId)
    {
        return $query->where('vendedor_id', $vendedorId);
    }

    public function scopeCerradasGanadas($query)
    {
        return $query->where('etapa', 'cerrado_ganado');
    }

    /**
     * Indica si el cambio de etapa propuesto es válido.
     */
    public function puedeAvanzarA(string $nuevaEtapa): bool
    {
        if ($nuevaEtapa === 'cerrado_perdido') {
            return true;
        }

        $ordenActual = self::ORDEN_ETAPAS[$this->etapa] ?? -1;
        $ordenNuevo  = self::ORDEN_ETAPAS[$nuevaEtapa] ?? -1;

        return $ordenNuevo > $ordenActual;
    }
}
