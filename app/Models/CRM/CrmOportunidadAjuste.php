<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Models\User;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajuste posterior a la venta (rechazo de cajas o cambio de precio) de un
 * renglón vendido. `importe` lleva signo; un ajuste anulado queda en el
 * historial pero ya no cuenta (spec 2026-11-25 §3).
 */
class CrmOportunidadAjuste extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_oportunidad_ajustes';

    public const TIPOS = ['rechazo', 'precio'];

    protected $fillable = [
        'empresa_id', 'oportunidad_id', 'venta_renglon_id', 'tipo', 'cajas',
        'precio_nuevo', 'importe', 'motivo_rechazo_id', 'nota', 'fecha', 'user_id',
        'anulado_at', 'anulado_por', 'motivo_anulacion',
    ];

    protected $casts = [
        'cajas' => 'integer',
        'precio_nuevo' => 'decimal:2',
        'importe' => 'decimal:2',
        'fecha' => 'date',
        'anulado_at' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function oportunidad(): BelongsTo
    {
        return $this->belongsTo(CrmOportunidad::class, 'oportunidad_id');
    }

    public function renglon(): BelongsTo
    {
        return $this->belongsTo(CrmOportunidadVentaRenglon::class, 'venta_renglon_id');
    }

    public function motivoRechazo(): BelongsTo
    {
        return $this->belongsTo(CrmMotivoRechazo::class, 'motivo_rechazo_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereNull('anulado_at');
    }
}
