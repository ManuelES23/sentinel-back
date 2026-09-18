<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de Órdenes de Compra
 * Ubicación: inventory/compras/ordenes-compra
 */
class PurchaseOrder extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $fillable = [
        'order_number',
        'enterprise_id',
        'almacen_destino_id',
        'requisicion_campo_id',
        'cotizacion_id',
        'supplier_id',
        'order_date',
        'expected_date',
        'expiry_date',
        'status',
        'currency_code',
        'exchange_rate',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'payment_terms',
        'payment_conditions',
        'shipping_address',
        'shipping_method',
        'delivery_instructions',
        'notes',
        'internal_notes',
        'requested_by',
        'department_head',
        'authorized_by_name',
        'created_by',
        'approved_by',
        'approved_at',
        'sent_by',
        'sent_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'metadata',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'expiry_date' => 'date',
        'exchange_rate' => 'decimal:6',
        'subtotal' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'payment_terms' => 'integer',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $appends = ['status_label', 'is_editable'];

    // ==================== CONSTANTES ====================

    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_SENT = 'sent';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_PARTIAL = 'partial';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REJECTED = 'rejected';

    const STATUS_LABELS = [
        'draft' => 'Borrador',
        'pending' => 'Pendiente Aprobación',
        'approved' => 'Aprobada',
        'sent' => 'Enviada al Proveedor',
        'confirmed' => 'Confirmada',
        'partial' => 'Recepción Parcial',
        'completed' => 'Completada',
        'cancelled' => 'Cancelada',
        'rejected' => 'Rechazada',
    ];

    // ==================== ACCESSORS ====================

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    const STATUSES_RECIBIBLES = [
        self::STATUS_APPROVED,
        self::STATUS_SENT,
        self::STATUS_CONFIRMED,
        self::STATUS_PARTIAL,
    ];

    public function getIsEditableAttribute(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_REJECTED]);
    }

    public function getIsCancellableAttribute(): bool
    {
        return !in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    // ==================== RELATIONSHIPS ====================

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function almacenDestino(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'almacen_destino_id');
    }

    public function requisicion(): BelongsTo
    {
        return $this->belongsTo(RequisicionCampo::class, 'requisicion_campo_id');
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(RequisicionCotizacion::class, 'cotizacion_id');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(PurchaseOrderDetail::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function accountsPayable(): HasMany
    {
        return $this->hasMany(AccountPayable::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
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

    public function scopePendingApproval($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->whereIn('status', [
            self::STATUS_APPROVED,
            self::STATUS_SENT,
            self::STATUS_CONFIRMED,
            self::STATUS_PARTIAL,
        ]);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    public function scopePendingReceipt($query)
    {
        return $query->whereIn('status', self::STATUSES_RECIBIBLES);
    }

    public function scopeBySupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
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
        $this->total_amount = $subtotal + $taxAmount - (float) $this->discount_amount;
        $this->save();
    }

    /**
     * Actualizar estado basado en recepciones
     */
    public function updateStatusFromReceipts(): void
    {
        $totalOrdered = (float) $this->details()->sum('quantity_ordered');
        $totalReceived = (float) $this->details()->sum('quantity_received');

        if ($totalOrdered > 0 && $totalReceived >= $totalOrdered) {
            $this->status = self::STATUS_COMPLETED;
        } elseif ($totalReceived > 0) {
            $this->status = self::STATUS_PARTIAL;
        }

        $this->save();
    }

    /**
     * Aprobar orden
     */
    public function approve(int $userId): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        $this->status = self::STATUS_APPROVED;
        $this->approved_by = $userId;
        $this->approved_at = now();

        return $this->save();
    }

    /**
     * Rechazar orden
     */
    public function reject(int $userId, string $reason): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        $this->status = self::STATUS_REJECTED;
        $this->rejected_by = $userId;
        $this->rejected_at = now();
        $this->rejection_reason = $reason;

        return $this->save();
    }

    /**
     * Enviar al proveedor
     */
    public function markAsSent(int $userId): bool
    {
        if ($this->status !== self::STATUS_APPROVED) {
            return false;
        }
        
        $this->status = self::STATUS_SENT;
        $this->sent_by = $userId;
        $this->sent_at = now();
        
        return $this->save();
    }

    /**
     * Cancelar orden
     */
    public function cancel(int $userId, string $reason): bool
    {
        if (!$this->is_cancellable) {
            return false;
        }
        
        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_by = $userId;
        $this->cancelled_at = now();
        $this->cancellation_reason = $reason;
        
        return $this->save();
    }

    /**
     * Generar número de orden
     */
    public static function generateOrderNumber(): string
    {
        $year = date('Y');
        $lastOrder = static::withTrashed()
            ->whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();
        
        $nextNumber = 1;
        if ($lastOrder && preg_match('/OC-' . $year . '-(\d+)/', $lastOrder->order_number, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        }
        
        return 'OC-' . $year . '-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }
}
