<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de Detalle de Recepción de Mercancía
 */
class PurchaseReceiptDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_receipt_id',
        'purchase_order_detail_id',
        'product_id',
        'quantity_ordered',
        'quantity_received',
        'quantity_accepted',
        'quantity_rejected',
        'unit_id',
        'unit_cost',
        'tax_rate',
        'tax_amount',
        'line_total',
        'lot_number',
        'expiry_date',
        'serial_number',
        'quality_status',
        'quality_notes',
        'notes',
    ];

    protected $casts = [
        'quantity_ordered' => 'decimal:4',
        'quantity_received' => 'decimal:4',
        'quantity_accepted' => 'decimal:4',
        'quantity_rejected' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:4',
        'line_total' => 'decimal:4',
        'expiry_date' => 'date',
    ];

    protected $appends = ['quality_status_label'];

    // ==================== CONSTANTES ====================

    const QUALITY_STATUS_LABELS = [
        'pending' => 'Pendiente',
        'approved' => 'Aprobado',
        'rejected' => 'Rechazado',
        'partial' => 'Parcial',
    ];

    // ==================== ACCESSORS ====================

    public function getQualityStatusLabelAttribute(): string
    {
        return self::QUALITY_STATUS_LABELS[$this->quality_status] ?? $this->quality_status;
    }

    // ==================== RELATIONSHIPS ====================

    public function purchaseReceipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    public function purchaseOrderDetail(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderDetail::class);
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
            // Por defecto, lo aceptado es igual a lo recibido
            if ($detail->quantity_accepted == 0 && $detail->quantity_rejected == 0) {
                $detail->quantity_accepted = $detail->quantity_received;
            }
            
            // Calcular total (solo con lo aceptado)
            $subtotal = $detail->quantity_accepted * $detail->unit_cost;
            $detail->tax_amount = $subtotal * ($detail->tax_rate / 100);
            $detail->line_total = $subtotal + $detail->tax_amount;
        });

        // Actualizar totales de la recepción después de guardar
        static::saved(function ($detail) {
            $detail->purchaseReceipt->recalculateTotals();
        });

        static::deleted(function ($detail) {
            $detail->purchaseReceipt->recalculateTotals();
        });
    }
}
