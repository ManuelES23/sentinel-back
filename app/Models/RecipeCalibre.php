<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipeCalibre extends Model
{
    use HasFactory, Loggable;

    protected $fillable = [
        'recipe_id',
        'calibre_id',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function calibre(): BelongsTo
    {
        return $this->belongsTo(Calibre::class);
    }

    public function plus(): HasMany
    {
        return $this->hasMany(RecipeCalibrePlu::class);
    }
}
