<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStock extends Model
{
    use HasFactory;

    protected $table = 'inventory_stock';

    protected $fillable = [
        'product_id',
        'entity_id',
        'entity_type',
        'area_id',
        'quantity',
        'reserved_quantity',
        'lot_number',
        'serial_number',
        'expiry_date',
        'unit_cost',
        'total_cost',
        'last_movement_at',
        'last_movement_id',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'last_movement_at' => 'datetime',
        'quantity' => 'decimal:4',
        'reserved_quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
    ];

    /**
     * available_quantity es calculado automáticamente en DB
     */
    protected $appends = [];

    /**
     * Producto
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Entidad (bodega)
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    /**
     * Último movimiento
     */
    public function lastMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'last_movement_id');
    }

    /**
     * Scope por producto
     */
    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope por entidad
     */
    public function scopeForEntity($query, int $entityId)
    {
        return $query->where('entity_id', $entityId);
    }

    /**
     * Scope por área
     */
    public function scopeForArea($query, ?int $areaId)
    {
        if ($areaId) {
            return $query->where('area_id', $areaId);
        }
        return $query->whereNull('area_id');
    }

    /**
     * Scope con stock disponible
     */
    public function scopeWithAvailable($query)
    {
        return $query->whereRaw('quantity > reserved_quantity');
    }

    /**
     * Scope para lotes próximos a vencer
     */
    public function scopeExpiringBefore($query, $date)
    {
        return $query->whereNotNull('expiry_date')
                    ->where('expiry_date', '<=', $date);
    }

    /**
     * Actualizar stock después de un movimiento
     */
    public static function updateStock(
        int $productId,
        int $entityId,
        ?int $areaId,
        float $quantityChange,
        float $unitCost = 0,
        ?string $lotNumber = null,
        ?string $expiryDate = null,
        ?int $movementId = null
    ): self {
        $stock = self::firstOrNew([
            'product_id' => $productId,
            'entity_id' => $entityId,
            'area_id' => $areaId,
            'lot_number' => $lotNumber,
        ]);

        $newQuantity = ($stock->quantity ?? 0) + $quantityChange;
        
        // Si es entrada, recalcular costo promedio
        if ($quantityChange > 0 && $unitCost > 0) {
            $currentTotal = ($stock->quantity ?? 0) * ($stock->unit_cost ?? 0);
            $newTotal = $currentTotal + ($quantityChange * $unitCost);
            $stock->unit_cost = $newQuantity > 0 ? $newTotal / $newQuantity : $unitCost;
        }

        $stock->quantity = max(0, $newQuantity);
        $stock->total_cost = $stock->quantity * ($stock->unit_cost ?? 0);
        
        if ($expiryDate) {
            $stock->expiry_date = $expiryDate;
        }
        
        if ($movementId) {
            $stock->last_movement_id = $movementId;
            $stock->last_movement_at = now();
        }

        $stock->save();

        return $stock;
    }
}
