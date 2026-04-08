<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'code',
        'name',
        'recipe_type',
        'slug',
        'description',
        'category_id',
        'cultivo_id',
        'variedad_id',
        'output_product_id',
        'output_quantity',
        'output_unit_id',
        'peso_pieza',
        'estimated_cost',
        'status',
        'version',
        'notes',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'output_quantity' => 'decimal:4',
        'peso_pieza' => 'decimal:4',
        'estimated_cost' => 'decimal:4',
        'metadata' => 'array',
    ];

    // ── Relaciones ──────────────────────────────────────────────

    /**
     * Categoría de la receta
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * Cultivo asociado a la receta
     */
    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class, 'cultivo_id');
    }

    /**
     * Variedad asociada a la receta
     */
    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class, 'variedad_id');
    }

    /**
     * Calibres asociados a la receta
     */
    public function recipeCalibres(): HasMany
    {
        return $this->hasMany(RecipeCalibre::class);
    }

    /**
     * Producto terminado que genera esta receta
     */
    public function outputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'output_product_id');
    }

    /**
     * Unidad de medida del producto de salida
     */
    public function outputUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'output_unit_id');
    }

    /**
     * Items/ingredientes de la receta
     */
    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->orderBy('sort_order');
    }

    // ── Scopes ──────────────────────────────────────────────────

    /**
     * Recetas activas
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Recetas por status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    // ── Accessors / Helpers ─────────────────────────────────────

    /**
     * Calcular costo estimado sumando los items
     */
    public function calculateEstimatedCost(): float
    {
        return $this->items->sum(function ($item) {
            $qty = (float) $item->quantity;
            $waste = (float) $item->waste_percentage;
            $cost = (float) $item->cost_per_unit;
            return $qty * (1 + $waste / 100) * $cost;
        });
    }

    /**
     * Recalcular y guardar el costo estimado
     * Solo suma items que son default (para grupos intercambiables)
     */
    public function recalculateCost(): self
    {
        $this->estimated_cost = $this->calculateEstimatedCost();
        $this->save();
        return $this;
    }

    /**
     * Agrupar items por group_key.
     * Items sin group_key son ingredientes fijos.
     * Items con group_key son alternativas intercambiables.
     */
    public function getGroups(): array
    {
        $fixed = [];
        $groups = [];

        foreach ($this->items as $item) {
            if (empty($item->group_key)) {
                $fixed[] = $item;
            } else {
                $groups[$item->group_key][] = $item;
            }
        }

        return [
            'fixed' => $fixed,
            'groups' => $groups,
        ];
    }
}
