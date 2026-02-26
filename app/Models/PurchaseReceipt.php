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
        'pending' => 'Pendiente Validación',
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
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING]);
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
        $subtotal = $this->details()->sum('line_total');
        $taxAmount = $this->details()->sum('tax_amount');
        
        $this->subtotal = $subtotal;
        $this->tax_amount = $taxAmount;
        $this->total_amount = $subtotal + $taxAmount;
        $this->save();
    }

    /**
     * Completar recepción (genera inventario y cuenta por pagar)
     */
    public function complete(int $userId): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        \DB::transaction(function () use ($userId) {
            // 1. Generar movimiento de inventario (entrada)
            $movement = $this->createInventoryMovement($userId);
            $this->inventory_movement_id = $movement->id;
            
            // 2. Generar cuenta por pagar
            $this->createAccountPayable($userId);
            
            // 3. Actualizar cantidades recibidas en la orden de compra
            $this->updatePurchaseOrderQuantities();
            
            // 4. Marcar como completada
            $this->status = self::STATUS_COMPLETED;
            $this->validated_by = $userId;
            $this->validated_at = now();
            $this->save();
        });

        return true;
    }

    /**
     * Crear movimiento de inventario
     */
    protected function createInventoryMovement(int $userId): InventoryMovement
    {
        // Buscar el tipo de movimiento "Entrada por Compra"
        $movementType = MovementType::where('code', 'ENTRADA_COMPRA')
            ->orWhere('name', 'like', '%compra%')
            ->first();

        $movement = InventoryMovement::create([
            'reference_number' => 'MOV-' . $this->receipt_number,
            'movement_type_id' => $movementType?->id,
            'movement_date' => $this->receipt_date,
            'description' => "Recepción de mercancía #{$this->receipt_number}",
            'source_type' => 'purchase_receipt',
            'source_id' => $this->id,
            'status' => 'completed',
            'created_by' => $userId,
        ]);

        // Crear detalles del movimiento
        foreach ($this->details as $detail) {
            $movement->details()->create([
                'product_id' => $detail->product_id,
                'quantity' => $detail->quantity_accepted,
                'unit_cost' => $detail->unit_cost,
                'total_cost' => $detail->quantity_accepted * $detail->unit_cost,
                'lot_number' => $detail->lot_number,
                'expiry_date' => $detail->expiry_date,
            ]);
        }

        return $movement;
    }

    /**
     * Crear cuenta por pagar
     */
    protected function createAccountPayable(int $userId): AccountPayable
    {
        $dueDate = now()->addDays($this->supplier->payment_terms_days ?? 30);

        return AccountPayable::create([
            'document_number' => AccountPayable::generateDocumentNumber(),
            'document_type' => 'invoice',
            'supplier_id' => $this->supplier_id,
            'supplier_invoice' => $this->supplier_document,
            'supplier_invoice_date' => $this->supplier_document_date,
            'purchase_receipt_id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'document_date' => $this->receipt_date,
            'due_date' => $dueDate,
            'payment_terms_days' => $this->supplier->payment_terms_days ?? 30,
            'currency_code' => $this->purchaseOrder?->currency_code ?? 'MXN',
            'exchange_rate' => $this->purchaseOrder?->exchange_rate ?? 1,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'balance' => $this->total_amount,
            'status' => 'pending',
            'created_by' => $userId,
        ]);
    }

    /**
     * Actualizar cantidades recibidas en la orden de compra
     */
    protected function updatePurchaseOrderQuantities(): void
    {
        if (!$this->purchase_order_id) {
            return;
        }

        foreach ($this->details as $detail) {
            if ($detail->purchase_order_detail_id) {
                $orderDetail = PurchaseOrderDetail::find($detail->purchase_order_detail_id);
                if ($orderDetail) {
                    $orderDetail->quantity_received += $detail->quantity_accepted;
                    $orderDetail->save();
                }
            }
        }

        // Actualizar estado de la orden
        $this->purchaseOrder->updateStatusFromReceipts();
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
