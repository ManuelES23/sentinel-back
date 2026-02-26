<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de Proveedores
 * Catálogo maestro de proveedores de la organización
 * Ubicación: administration/organizacion/proveedores
 */
class Supplier extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $fillable = [
        'code',
        'business_name',
        'trade_name',
        'tax_id',
        'supplier_type',
        'category',
        'has_credit',
        'payment_terms',
        'credit_limit',
        'discount_percent',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'phone',
        'mobile',
        'email',
        'website',
        'bank_name',
        'bank_account',
        'bank_clabe',
        'bank_swift',
        'legal_representative',
        'contract_start_date',
        'contract_end_date',
        'notes',
        'is_active',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'discount_percent' => 0,
        'credit_limit' => 0,
        'payment_terms' => 0,
        'has_credit' => false,
        'is_active' => true,
    ];

    protected $casts = [
        'has_credit' => 'boolean',
        'payment_terms' => 'integer',
        'credit_limit' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'contract_start_date' => 'date',
        'contract_end_date' => 'date',
        'metadata' => 'array',
    ];

    protected $appends = ['full_name', 'status_label'];

    // ==================== ACCESSORS ====================

    public function getFullNameAttribute(): string
    {
        return $this->trade_name ?: $this->business_name;
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->is_active ? 'Activo' : 'Inactivo';
    }

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country,
        ]);
        return implode(', ', $parts);
    }

    // ==================== RELATIONSHIPS ====================

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    public function primaryContact()
    {
        return $this->hasOne(SupplierContact::class)->where('is_primary', true);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function purchaseReceipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function accountsPayable(): HasMany
    {
        return $this->hasMany(AccountPayable::class);
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeNational($query)
    {
        return $query->where('supplier_type', 'national');
    }

    public function scopeInternational($query)
    {
        return $query->where('supplier_type', 'international');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeWithCredit($query)
    {
        return $query->where('credit_limit', '>', 0);
    }

    // ==================== METHODS ====================

    /**
     * Obtener el balance actual de cuentas por pagar
     */
    public function getCurrentBalance(): float
    {
        return $this->accountsPayable()
            ->whereIn('status', ['pending', 'partial'])
            ->sum('balance');
    }

    /**
     * Verificar si tiene crédito disponible
     */
    public function hasAvailableCredit(float $amount): bool
    {
        if ($this->credit_limit <= 0) {
            return true; // Sin límite de crédito
        }
        
        $currentBalance = $this->getCurrentBalance();
        return ($currentBalance + $amount) <= $this->credit_limit;
    }

    /**
     * Obtener crédito disponible
     */
    public function getAvailableCredit(): float
    {
        if ($this->credit_limit <= 0) {
            return PHP_FLOAT_MAX;
        }
        
        return max(0, $this->credit_limit - $this->getCurrentBalance());
    }

    /**
     * Generar código automático
     */
    public static function generateCode(): string
    {
        $lastSupplier = static::withTrashed()
            ->orderBy('id', 'desc')
            ->first();
        
        $nextNumber = $lastSupplier ? ($lastSupplier->id + 1) : 1;
        
        return 'PROV-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }
}
