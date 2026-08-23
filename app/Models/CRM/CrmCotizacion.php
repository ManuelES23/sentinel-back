<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmCotizacion extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'crm_cotizaciones';

    protected $fillable = [
        'empresa_id', 'oportunidad_id', 'folio', 'estado',
        'fecha_emision', 'vigencia_dias', 'descuento_global_pct',
        'subtotal', 'total', 'notas',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'descuento_global_pct' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function oportunidad(): BelongsTo
    {
        return $this->belongsTo(CrmOportunidad::class, 'oportunidad_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(CrmOportunidadProducto::class, 'cotizacion_id');
    }

    public function impuestos(): HasMany
    {
        return $this->hasMany(CrmCotizacionImpuesto::class, 'cotizacion_id');
    }
}
