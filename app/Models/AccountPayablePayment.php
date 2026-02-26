<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de Pagos a Proveedores
 * Ubicación: accounting/cuentas-por-pagar
 */
class AccountPayablePayment extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $fillable = [
        'account_payable_id',
        'payment_number',
        'payment_date',
        'amount',
        'currency_code',
        'exchange_rate',
        'payment_method',
        'bank_reference',
        'check_number',
        'bank_account',
        'status',
        'notes',
        'processed_by',
        'processed_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'metadata',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:4',
        'exchange_rate' => 'decimal:6',
        'processed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $appends = ['status_label', 'payment_method_label'];

    // ==================== CONSTANTES ====================

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSED = 'processed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_BOUNCED = 'bounced';

    const STATUS_LABELS = [
        'pending' => 'Pendiente',
        'processed' => 'Procesado',
        'cancelled' => 'Cancelado',
        'bounced' => 'Rechazado',
    ];

    const PAYMENT_METHOD_LABELS = [
        'cash' => 'Efectivo',
        'bank_transfer' => 'Transferencia Bancaria',
        'check' => 'Cheque',
        'credit_card' => 'Tarjeta de Crédito',
        'direct_debit' => 'Domiciliación',
        'other' => 'Otro',
    ];

    // ==================== ACCESSORS ====================

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        return self::PAYMENT_METHOD_LABELS[$this->payment_method] ?? $this->payment_method;
    }

    // ==================== RELATIONSHIPS ====================

    public function accountPayable(): BelongsTo
    {
        return $this->belongsTo(AccountPayable::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PaymentApplication::class, 'payment_id');
    }

    public function processedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // ==================== SCOPES ====================

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessed($query)
    {
        return $query->where('status', self::STATUS_PROCESSED);
    }

    public function scopeByMethod($query, string $method)
    {
        return $query->where('payment_method', $method);
    }

    public function scopeByPeriod($query, $startDate, $endDate)
    {
        return $query->whereBetween('payment_date', [$startDate, $endDate]);
    }

    // ==================== METHODS ====================

    /**
     * Procesar pago
     */
    public function process(int $userId): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        \DB::transaction(function () use ($userId) {
            // Aplicar pago a la cuenta por pagar
            $this->accountPayable->applyPayment($this->amount);
            
            $this->status = self::STATUS_PROCESSED;
            $this->processed_by = $userId;
            $this->processed_at = now();
            $this->save();
        });

        return true;
    }

    /**
     * Cancelar pago
     */
    public function cancel(int $userId, string $reason): bool
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return false;
        }

        \DB::transaction(function () use ($userId, $reason) {
            // Si ya estaba procesado, revertir en la cuenta por pagar
            if ($this->status === self::STATUS_PROCESSED) {
                $this->accountPayable->reversePayment($this->amount);
            }
            
            $this->status = self::STATUS_CANCELLED;
            $this->cancelled_by = $userId;
            $this->cancelled_at = now();
            $this->cancellation_reason = $reason;
            $this->save();
        });

        return true;
    }

    /**
     * Marcar como rebotado (cheque sin fondos, etc)
     */
    public function markAsBounced(int $userId, string $reason): bool
    {
        if ($this->status !== self::STATUS_PROCESSED) {
            return false;
        }

        \DB::transaction(function () use ($userId, $reason) {
            // Revertir en la cuenta por pagar
            $this->accountPayable->reversePayment($this->amount);
            
            $this->status = self::STATUS_BOUNCED;
            $this->cancelled_by = $userId;
            $this->cancelled_at = now();
            $this->cancellation_reason = $reason;
            $this->save();
        });

        return true;
    }

    /**
     * Generar número de pago
     */
    public static function generatePaymentNumber(): string
    {
        $year = date('Y');
        $month = date('m');
        
        $lastPayment = static::withTrashed()
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();
        
        $nextNumber = 1;
        if ($lastPayment && preg_match('/PAG-' . $year . $month . '-(\d+)/', $lastPayment->payment_number, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        }
        
        return 'PAG-' . $year . $month . '-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }
}
