<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cierre mensual de depreciación de una empresa. `period` es el primer día del
 * mes y se lee y escribe siempre como cadena `Y-m-d` (sin cast).
 */
class DepreciationClose extends Model
{
    use HasFactory, Loggable;

    protected $fillable = [
        'enterprise_id',
        'period',
        'assets_count',
        'excluded_count',
        'month_total',
        'accumulated_total',
        'book_total',
        'closed_by',
        'closed_at',
    ];

    protected $casts = [
        'enterprise_id' => 'integer',
        'assets_count' => 'integer',
        'excluded_count' => 'integer',
        'month_total' => 'decimal:2',
        'accumulated_total' => 'decimal:2',
        'book_total' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DepreciationCloseLine::class);
    }
}
