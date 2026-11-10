<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\CRM\NombreCatalogo;
use Illuminate\Support\Collection;

class CrmProducto extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_productos';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'commodity',
        'variedad_id',
        'descripcion',
        'precio',
        'unidad_medida',
        'activo',
        'clave',
        'marca_id',
        'linea_id',
        'sublinea_id',
    ];

    protected $casts = [
        'precio'  => 'decimal:2',
        'activo'  => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $producto) {
            $limpia = NombreCatalogo::limpio($producto->clave);
            $producto->clave = $limpia === '' ? null : $limpia;
            $producto->clave_normalizada = $limpia === '' ? null : NombreCatalogo::clave($limpia);
        });
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(CrmMarca::class, 'marca_id');
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(CrmLinea::class, 'linea_id');
    }

    public function sublinea(): BelongsTo
    {
        return $this->belongsTo(CrmSublinea::class, 'sublinea_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function oportunidadProductos(): HasMany
    {
        return $this->hasMany(CrmOportunidadProducto::class, 'producto_id');
    }

    public function calibresPropios(): HasMany
    {
        return $this->hasMany(CrmProductoCalibre::class, 'producto_id');
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
