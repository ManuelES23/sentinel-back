<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una unidad aceptada de un renglón de recepción cuyo producto es activo fijo.
 * pending → registered (con su activo) o discarded (con motivo).
 * "Vigente" = pending, o registered cuyo activo existe y no está en baja.
 */
class FixedAssetReceiptUnit extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_REGISTERED = 'registered';
    public const STATUS_DISCARDED = 'discarded';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_REGISTERED, self::STATUS_DISCARDED];

    protected $fillable = [
        'enterprise_id',
        'purchase_receipt_id',
        'purchase_receipt_detail_id',
        'product_id',
        'unit_number',
        'lot_number',
        'unit_cost',
        'serial_number',
        'status',
        'fixed_asset_id',
        'registered_by',
        'registered_at',
        'discarded_reason',
        'discarded_by',
        'discarded_at',
    ];

    protected $casts = [
        'enterprise_id' => 'integer',
        'unit_number' => 'integer',
        'unit_cost' => 'decimal:2',
        'registered_at' => 'datetime',
        'discarded_at' => 'datetime',
    ];

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where(function (Builder $w) {
            $w->where('status', self::STATUS_PENDING)
                ->orWhere(function (Builder $r) {
                    $r->where('status', self::STATUS_REGISTERED)
                        ->whereHas('fixedAsset', fn ($a) => $a->where('status', '!=', 'baja'));
                });
        });
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptDetail::class, 'purchase_receipt_detail_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function discardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discarded_by');
    }
}
