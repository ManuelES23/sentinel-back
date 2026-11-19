<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de consumo de un insumo por producción de Empaque: lo descontado del almacén de la
 * planta y lo ya devuelto. Su referencia enlaza las porciones PEPS de la salida para devolverlas.
 */
class ProduccionEmpaqueConsumo extends Model
{
    protected $table = 'produccion_empaque_consumos';

    protected $fillable = [
        'produccion_id', 'detalle_id', 'entity_id', 'product_id', 'recipe_id', 'recipe_item_id',
        'group_key', 'tipo', 'cajas', 'cantidad', 'costo_total', 'cantidad_devuelta',
    ];

    protected $casts = [
        'cajas' => 'integer',
        'cantidad' => 'decimal:4',
        'costo_total' => 'decimal:4',
        'cantidad_devuelta' => 'decimal:4',
    ];

    public function produccion(): BelongsTo
    {
        return $this->belongsTo(ProduccionEmpaque::class, 'produccion_id');
    }

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(ProduccionEmpaqueDetalle::class, 'detalle_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Lo que sigue descontado: cantidad menos lo devuelto. */
    public function vigente(): float
    {
        return round((float) $this->cantidad - (float) $this->cantidad_devuelta, 4);
    }

    public function referencia(): string
    {
        return "empaque_consumo:{$this->id}";
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereColumn('cantidad_devuelta', '<', 'cantidad');
    }
}
