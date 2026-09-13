<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Brand extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function enterprises(): BelongsToMany
    {
        return $this->belongsToMany(Enterprise::class, 'enterprise_brand')
            ->withTimestamps();
    }

    public function scopeForEnterprise($query, int $enterpriseId)
    {
        return $query->whereHas('enterprises', function ($q) use ($enterpriseId) {
            $q->where('enterprises.id', $enterpriseId);
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
