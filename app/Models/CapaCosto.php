<?php

namespace App\Models;

use App\Support\Inventario\Cubeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Capa de costo PEPS: una entrada a una cubeta con su costo y lo que queda de ella. */
class CapaCosto extends Model
{
    public const ORIGEN_COMPRA = 'compra';
    public const ORIGEN_ENTRADA = 'entrada';
    public const ORIGEN_AJUSTE = 'ajuste';
    public const ORIGEN_TRANSFERENCIA = 'transferencia';
    public const ORIGEN_DEVOLUCION = 'devolucion';
    public const ORIGEN_INICIAL = 'inicial';
    public const ORIGEN_FALTANTE = 'faltante';

    protected $table = 'inventory_cost_layers';

    protected $fillable = [
        'product_id', 'entity_id', 'area_id', 'lot_number', 'fecha_entrada', 'unit_cost',
        'cantidad_inicial', 'cantidad_restante', 'origen', 'movement_id', 'movement_detail_id', 'capa_origen_id',
    ];

    protected $casts = [
        'fecha_entrada' => 'datetime',
        'unit_cost' => 'decimal:6',
        'cantidad_inicial' => 'decimal:4',
        'cantidad_restante' => 'decimal:4',
    ];

    public function scopeDeCubeta(Builder $q, Cubeta $c): Builder
    {
        return $c->filtrar($q);
    }

    public function scopeOrdenPeps(Builder $q): Builder
    {
        return $q->orderBy('fecha_entrada')->orderBy('id');
    }

    public function consumos(): HasMany
    {
        return $this->hasMany(ConsumoCapa::class, 'layer_id');
    }
}
