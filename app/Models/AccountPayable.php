<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de Cuentas por Pagar
 * Ubicación: accounting/cuentas-por-pagar
 */
class AccountPayable extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'accounts_payable';

    protected $fillable = [
        'document_number',
        'document_type',
        'supplier_id',
        'supplier_invoice',
        'supplier_invoice_date',
        'purchase_receipt_id',
        'purchase_order_id',
        'document_date',
        'due_date',
        'payment_terms_days',
        'currency_code',
        'exchange_rate',
        'subtotal',
        'tax_amount',
        'total_amount',
        'amount_paid',
        'balance',
        'status',
        'accounting_account',
        'cost_center',
        'notes',
        'created_by',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'metadata',
    ];

    protected $casts = [
        'supplier_invoice_date' => 'date',
        'document_date' => 'date',
        'due_date' => 'date',
        'payment_terms_days' => 'integer',
        'exchange_rate' => 'decimal:6',
        'subtotal' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'amount_paid' => 'decimal:4',
        'balance' => 'decimal:4',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $appends = ['status_label', 'document_type_label', 'is_overdue', 'days_overdue'];

    // ==================== CONSTANTES ====================

    const STATUS_PENDING = 'pending';

    const STATUS_PARTIAL = 'partial';

    const STATUS_PAID = 'paid';

    const STATUS_CANCELLED = 'cancelled';

    const STATUS_DISPUTED = 'disputed';

    const STATUS_LABELS = [
        'pending' => 'Pendiente',
        'partial' => 'Pago Parcial',
        'paid' => 'Pagado',
        'cancelled' => 'Cancelado',
        'disputed' => 'En Disputa',
    ];

    const DOCUMENT_TYPE_LABELS = [
        'invoice' => 'Factura',
        'credit_note' => 'Nota de Crédito',
        'debit_note' => 'Nota de Débito',
        'other' => 'Otro',
    ];

    // ==================== ACCESSORS ====================

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getDocumentTypeLabelAttribute(): string
    {
        return self::DOCUMENT_TYPE_LABELS[$this->document_type] ?? $this->document_type;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->balance > 0 && $this->due_date < now()->startOfDay();
    }

    public function getDaysOverdueAttribute(): int
    {
        if (! $this->is_overdue) {
            return 0;
        }

        return now()->startOfDay()->diffInDays($this->due_date);
    }

    // ==================== RELATIONSHIPS ====================

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseReceipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountPayablePayment::class);
    }

    public function paymentApplications(): HasMany
    {
        return $this->hasMany(PaymentApplication::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // ==================== SCOPES ====================

    public function scopePending($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PARTIAL]);
    }

    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function scopeOverdue($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PARTIAL])
            ->where('due_date', '<', now()->startOfDay());
    }

    public function scopeDueSoon($query, int $days = 7)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PARTIAL])
            ->whereBetween('due_date', [now()->startOfDay(), now()->addDays($days)]);
    }

    public function scopeBySupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeByPeriod($query, $startDate, $endDate)
    {
        return $query->whereBetween('document_date', [$startDate, $endDate]);
    }

    // ==================== METHODS ====================

    /**
     * Aplicar un pago
     */
    public function applyPayment(float $amount): void
    {
        $this->amount_paid += $amount;
        $this->balance = $this->total_amount - $this->amount_paid;

        if ($this->balance <= 0) {
            $this->status = self::STATUS_PAID;
            $this->balance = 0;
        } else {
            $this->status = self::STATUS_PARTIAL;
        }

        $this->save();
    }

    /**
     * Revertir un pago
     */
    public function reversePayment(float $amount): void
    {
        $this->amount_paid = max(0, $this->amount_paid - $amount);
        $this->balance = $this->total_amount - $this->amount_paid;

        if ($this->amount_paid == 0) {
            $this->status = self::STATUS_PENDING;
        } else {
            $this->status = self::STATUS_PARTIAL;
        }

        $this->save();
    }

    /**
     * Cancelar documento
     */
    public function cancel(int $userId, string $reason): bool
    {
        if ($this->status === self::STATUS_PAID) {
            return false; // No se puede cancelar un documento ya pagado
        }

        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_by = $userId;
        $this->cancelled_at = now();
        $this->cancellation_reason = $reason;

        return $this->save();
    }

    /**
     * Generar número de documento
     */
    public static function generateDocumentNumber(): string
    {
        $year = date('Y');
        $month = date('m');

        $lastDoc = static::withTrashed()
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        $nextNumber = 1;
        if ($lastDoc && preg_match('/CXP-'.$year.$month.'-(\d+)/', $lastDoc->document_number, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        }

        return 'CXP-'.$year.$month.'-'.str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }
}
