<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmConfiguracionImpuesto extends Model
{
    protected $table = 'crm_configuracion_impuestos';

    protected $fillable = ['empresa_id', 'nombre', 'tasa', 'activo', 'orden'];

    protected $casts = ['tasa' => 'decimal:2', 'activo' => 'boolean'];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true)->orderBy('orden');
    }
}
