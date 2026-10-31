<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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

    /**
     * Commodities distintos (no vacíos) de los productos de la empresa, en
     * orden alfabético. Alimenta el autocompletado del catálogo y las
     * opciones de `tipo_producto` de la oportunidad.
     *
     * @return Collection<int, string>
     */
    public static function commoditiesDe(int $empresaId): Collection
    {
        return static::query()
            ->where('empresa_id', $empresaId)
            ->whereNotNull('commodity')
            ->where('commodity', '!=', '')
            ->distinct()
            ->orderBy('commodity')
            ->pluck('commodity')
            ->values();
    }
}
