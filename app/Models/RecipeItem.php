<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeItem extends Model
{
    use HasFactory, Loggable;

    protected $fillable = [
        'recipe_id',
        'product_id',
        'quantity',
        'unit_id',
        'waste_percentage',
        'cost_per_unit',
        'is_optional',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'waste_percentage' => 'decimal:2',
        'cost_per_unit' => 'decimal:4',
        'is_optional' => 'boolean',
        'sort_order' => 'integer',
    ];

    // ── Relaciones ──────────────────────────────────────────────

    /**
     * Receta a la que pertenece
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Producto/ingrediente
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Unidad de medida
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    // ── Accessors ───────────────────────────────────────────────

    /**
     * Costo total del item (qty * (1 + waste%) * cost)
     */
    public function getTotalCostAttribute(): float
    {
        $qty = (float) $this->quantity;
        $waste = (float) $this->waste_percentage;
        $cost = (float) $this->cost_per_unit;
        return $qty * (1 + $waste / 100) * $cost;
    }
}
