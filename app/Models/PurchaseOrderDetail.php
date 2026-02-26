<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de Detalle de Orden de Compra
 */
class PurchaseOrderDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'quantity_ordered',
        'quantity_received',
        'unit_id',
        'unit_price',
        'discount_percent',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'line_total',
        'expected_date',
        'notes',
        'line_number',
    ];

    protected $casts = [
        'quantity_ordered' => 'decimal:4',
        'quantity_received' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:4',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:4',
        'line_total' => 'decimal:4',
        'expected_date' => 'date',
        'line_number' => 'integer',
    ];

    protected $appends = ['quantity_pending', 'is_fully_received'];

    // ==================== ACCESSORS ====================

    public function getQuantityPendingAttribute(): float
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }

    public function getIsFullyReceivedAttribute(): bool
    {
        return $this->quantity_received >= $this->quantity_ordered;
    }

    // ==================== RELATIONSHIPS ====================

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        // Calcular totales antes de guardar
        static::saving(function ($detail) {
            $subtotal = $detail->quantity_ordered * $detail->unit_price;
            
            // Aplicar descuento
            if ($detail->discount_percent > 0) {
                $detail->discount_amount = $subtotal * ($detail->discount_percent / 100);
            }
            $subtotalAfterDiscount = $subtotal - $detail->discount_amount;
            
            // Calcular impuesto
            $detail->tax_amount = $subtotalAfterDiscount * ($detail->tax_rate / 100);
            
            // Total de línea
            $detail->line_total = $subtotalAfterDiscount + $detail->tax_amount;
        });

        // Actualizar totales de la orden después de guardar
        static::saved(function ($detail) {
            $detail->purchaseOrder->recalculateTotals();
        });

        static::deleted(function ($detail) {
            $detail->purchaseOrder->recalculateTotals();
        });
    }
}
