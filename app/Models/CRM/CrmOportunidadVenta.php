<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Models\User;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lo vendido de una cotización, guardado en la oportunidad. Una sola venta
 * vigente por oportunidad; las anteriores quedan como histórico
 * (spec 2026-11-25 §2).
 */
class CrmOportunidadVenta extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_oportunidad_ventas';

    protected $fillable = [
        'empresa_id', 'oportunidad_id', 'cotizacion_id', 'vigente',
        'descuento_global_pct', 'registrada_por',
    ];

    protected $casts = [
        'vigente' => 'boolean',
        'descuento_global_pct' => 'decimal:2',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function oportunidad(): BelongsTo
    {
        return $this->belongsTo(CrmOportunidad::class, 'oportunidad_id');
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(CrmCotizacion::class, 'cotizacion_id');
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por');
    }

    public function renglones(): HasMany
    {
        return $this->hasMany(CrmOportunidadVentaRenglon::class, 'venta_id');
    }
}
