<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmProducto extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_productos';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'commodity',
        'descripcion',
        'precio',
        'unidad_medida',
        'activo',
    ];

    protected $casts = [
        'precio'  => 'decimal:2',
        'activo'  => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function oportunidadProductos(): HasMany
    {
        return $this->hasMany(CrmOportunidadProducto::class, 'producto_id');
    }

    public function scopeActivo($query)
    {
        return $query->where('activo', true);
    }
}
