<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeCalibrePlu extends Model
{
    use HasFactory, Loggable;

    protected $table = 'recipe_calibre_plus';

    protected $fillable = [
        'recipe_calibre_id',
        'product_id',
        'is_organic',
        'notes',
    ];

    protected $casts = [
        'is_organic' => 'boolean',
    ];

    public function recipeCalibre(): BelongsTo
    {
        return $this->belongsTo(RecipeCalibre::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
