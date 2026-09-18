<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de Recepciones de Mercancía
 * Ubicación: inventory/compras/recepciones
 * Genera movimientos de inventario y cuentas por pagar
 */
class PurchaseReceipt extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $fillable = [
        'receipt_number',
        'purchase_order_id',
        'supplier_id',
        'enterprise_id',
        'almacen_id',
        'receipt_date',
        'supplier_document',
        'supplier_document_date',
        'status',
        'inventory_movement_id',
        'warehouse_id',
        'warehouse_type',
        'subtotal',
        'tax_amount',
        'total_amount',
        'notes',
        'quality_notes',
        'received_by',
        'capturada_por',
        'enviada_at',
        'confirmada_por',
        'confirmada_at',
        'motivo_rechazo',
        'validated_by',
        'validated_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'metadata',
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'supplier_document_date' => 'date',
        'subtotal' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'validated_at' => 'datetime',
        'enviada_at' => 'datetime',
        'confirmada_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $appends = ['status_label', 'is_editable'];

    // ==================== CONSTANTES ====================

    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    const STATUS_LABELS = [
        'draft' => 'Borrador',
        'pending' => 'Por confirmar',
        'completed' => 'Completada',
        'cancelled' => 'Cancelada',
    ];

    // ==================== ACCESSORS ====================

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getIsEditableAttribute(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    // ==================== RELATIONSHIPS ====================

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'almacen_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'enterprise_id');
    }

    public function capturadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capturada_por');
    }

    public function confirmadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmada_por');
    }

    public function details(): HasMany
    {
        return $this->hasMany(PurchaseReceiptDetail::class);
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }

    public function accountPayable(): HasOne
    {
        return $this->hasOne(AccountPayable::class);
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function validatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // ==================== SCOPES ====================

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeBySupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeByPurchaseOrder($query, int $orderId)
    {
        return $query->where('purchase_order_id', $orderId);
    }

    // ==================== METHODS ====================

    /**
     * Recalcular totales
     */
    public function recalculateTotals(): void
    {
        $taxAmount = $this->details()->sum('tax_amount');
        $lineTotal = $this->details()->sum('line_total');

        $this->subtotal = $lineTotal - $taxAmount;
        $this->tax_amount = $taxAmount;
        $this->total_amount = $lineTotal;
        $this->save();
    }

    /**
     * Cancelar recepción
     */
    public function cancel(int $userId, string $reason): bool
    {
        if (!$this->is_editable) {
            return false;
        }

        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_by = $userId;
        $this->cancelled_at = now();
        $this->cancellation_reason = $reason;

        return $this->save();
    }

    /**
     * Generar número de recepción
     */
    public static function generateReceiptNumber(): string
    {
        $year = date('Y');
        $month = date('m');
        
        $lastReceipt = static::withTrashed()
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();
        
        $nextNumber = 1;
        if ($lastReceipt && preg_match('/REC-' . $year . $month . '-(\d+)/', $lastReceipt->receipt_number, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        }
        
        return 'REC-' . $year . $month . '-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }
}
