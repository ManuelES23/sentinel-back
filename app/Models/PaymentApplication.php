<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de Aplicación de Pagos
 * Tabla N:N que permite que un pago cubra múltiples facturas
 */
class PaymentApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'account_payable_id',
        'amount_applied',
    ];

    protected $casts = [
        'amount_applied' => 'decimal:4',
    ];

    // ==================== RELATIONSHIPS ====================

    public function payment(): BelongsTo
    {
        return $this->belongsTo(AccountPayablePayment::class, 'payment_id');
    }

    public function accountPayable(): BelongsTo
    {
        return $this->belongsTo(AccountPayable::class);
    }
}
