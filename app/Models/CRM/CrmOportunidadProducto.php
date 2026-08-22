<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmOportunidadProducto extends Model
{
    use HasFactory;

    protected $table = 'crm_oportunidad_productos';

    public $timestamps = false;

    protected $fillable = [
        'oportunidad_id',
        'cotizacion_id',
        'producto_id',
        'descripcion',
        'cantidad',
        'precio_unitario',
    ];

    protected $casts = [
        'cantidad'        => 'decimal:4',
        'precio_unitario' => 'decimal:2',
    ];

    protected $appends = ['importe'];

    public function oportunidad(): BelongsTo
    {
        return $this->belongsTo(CrmOportunidad::class, 'oportunidad_id');
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(CrmCotizacion::class, 'cotizacion_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(CrmProducto::class, 'producto_id');
    }

    public function getImporteAttribute(): float
    {
        return round((float) $this->cantidad * (float) $this->precio_unitario, 2);
    }
}
