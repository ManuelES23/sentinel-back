<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovementDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'movement_id',
        'product_id',
        'quantity',
        'unit_id',
        'conversion_factor',
        'base_quantity',
        'lot_number',
        'serial_number',
        'expiry_date',
        'unit_cost',
        'total_cost',
        'source_area_id',
        'destination_area_id',
        'notes',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'quantity' => 'decimal:4',
        'conversion_factor' => 'decimal:6',
        'base_quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
    ];

    /**
     * Movimiento padre
     */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'movement_id');
    }

    /**
     * Producto
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Unidad de medida
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    /**
     * Calcular cantidad base
     */
    public function calculateBaseQuantity(): float
    {
        return $this->quantity * $this->conversion_factor;
    }

    /**
     * Calcular costo total
     */
    public function calculateTotalCost(): float
    {
        return $this->base_quantity * $this->unit_cost;
    }

    /**
     * Boot del modelo
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($detail) {
            // Calcular cantidad base si no está definida
            if (!$detail->base_quantity && $detail->quantity) {
                $detail->base_quantity = $detail->quantity * ($detail->conversion_factor ?? 1);
            }
            
            // Calcular costo total
            if (!$detail->total_cost && $detail->base_quantity && $detail->unit_cost) {
                $detail->total_cost = $detail->base_quantity * $detail->unit_cost;
            }
        });

        static::saved(function ($detail) {
            // Recalcular totales del movimiento padre
            $detail->movement->recalculateTotals();
        });

        static::deleted(function ($detail) {
            // Recalcular totales del movimiento padre
            $detail->movement->recalculateTotals();
        });
    }
}
