<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeVersion extends Model
{
    protected $fillable = [
        'recipe_id',
        'version_number',
        'snapshot',
        'created_by',
        'change_note',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'version_number' => 'integer',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
