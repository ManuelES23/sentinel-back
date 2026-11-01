<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Presentación de empaque por empresa y commodity (catálogo de la cotización por caja). */
class CrmPresentacion extends Model
{
    protected $table = 'crm_presentaciones';

    protected $fillable = ['empresa_id', 'commodity', 'nombre', 'orden', 'activo'];

    protected $casts = [
        'orden'  => 'integer',
        'activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function scopeActivo($query)
    {
        return $query->where('activo', true);
    }
}
