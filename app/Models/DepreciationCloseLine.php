<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto de un activo en un cierre: no cambia aunque luego se edite el activo o su tipo. */
class DepreciationCloseLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'depreciation_close_id',
        'fixed_asset_id',
        'code',
        'name',
        'category_name',
        'subcategory_name',
        'purchase_date',
        'purchase_value',
        'rate',
        'salvage_percent',
        'start_month',
        'month_amount',
        'accumulated',
        'book_value',
    ];

    protected $casts = [
        'purchase_value' => 'decimal:2',
        'rate' => 'decimal:2',
        'salvage_percent' => 'decimal:2',
        'month_amount' => 'decimal:2',
        'accumulated' => 'decimal:2',
        'book_value' => 'decimal:2',
    ];

    public function close(): BelongsTo
    {
        return $this->belongsTo(DepreciationClose::class, 'depreciation_close_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id')->withTrashed();
    }
}
