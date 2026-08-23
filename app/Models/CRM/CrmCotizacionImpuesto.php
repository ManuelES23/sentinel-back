<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmCotizacionImpuesto extends Model
{
    protected $table = 'crm_cotizacion_impuestos';

    public $timestamps = false;

    protected $fillable = ['cotizacion_id', 'nombre', 'tasa', 'monto'];

    protected $casts = ['tasa' => 'decimal:2', 'monto' => 'decimal:2'];

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(CrmCotizacion::class, 'cotizacion_id');
    }
}
