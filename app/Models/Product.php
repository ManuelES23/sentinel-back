<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $fillable = [
        'code',
        'sku',
        'barcode',
        'name',
        'slug',
        'description',
        'category_id',
        'unit_id',
        'product_type',
        'track_inventory',
        'track_lots',
        'track_serials',
        'track_expiry',
        'min_stock',
        'max_stock',
        'reorder_point',
        'reorder_quantity',
        'cost_price',
        'sale_price',
        'cost_method',
        'image',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'track_inventory' => 'boolean',
        'track_lots' => 'boolean',
        'track_serials' => 'boolean',
        'track_expiry' => 'boolean',
        'min_stock' => 'decimal:4',
        'max_stock' => 'decimal:4',
        'reorder_point' => 'decimal:4',
        'reorder_quantity' => 'decimal:4',
        'cost_price' => 'decimal:4',
        'sale_price' => 'decimal:4',
        'metadata' => 'array',
    ];

    /**
     * Categoría del producto
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * Unidad de medida
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    /**
     * Stock actual
     */
    public function stock(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'product_id');
    }

    /**
     * Movimientos de este producto
     */
    public function movementDetails(): HasMany
    {
        return $this->hasMany(InventoryMovementDetail::class, 'product_id');
    }

    /**
     * Kardex del producto
     */
    public function kardex(): HasMany
    {
        return $this->hasMany(InventoryKardex::class, 'product_id');
    }

    /**
     * Scope para productos activos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para productos que controlan inventario
     */
    public function scopeTracksInventory($query)
    {
        return $query->where('track_inventory', true);
    }

    /**
     * Scope por tipo de producto
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('product_type', $type);
    }

    /**
     * Scope por categoría
     */
    public function scopeInCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Obtener stock total del producto
     */
    public function getTotalStockAttribute(): float
    {
        return $this->stock()->sum('quantity');
    }

    /**
     * Obtener stock disponible del producto
     */
    public function getAvailableStockAttribute(): float
    {
        return $this->stock()->sum('available_quantity');
    }

    /**
     * Verificar si tiene stock bajo
     */
    public function getIsLowStockAttribute(): bool
    {
        return $this->total_stock <= $this->min_stock;
    }

    /**
     * Verificar si necesita reorden
     */
    public function getNeedsReorderAttribute(): bool
    {
        if (! $this->reorder_point) {
            return false;
        }

        return $this->total_stock <= $this->reorder_point;
    }

    /**
     * Obtener URL de imagen
     */
    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return asset('storage/'.$this->image);
    }
}
