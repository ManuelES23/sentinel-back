<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory;

    protected $fillable = [
        'enterprise_id',
        'slug',
        'name',
        'description',
        'icon',
        'path',
        'config',
        'is_active'
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean'
    ];

    /**
     * Get the enterprise that owns the application.
     */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /**
     * Get the modules for this application.
     */
    public function modules(): HasMany
    {
        return $this->hasMany(Module::class)->orderBy('order');
    }

    /**
     * Get the active modules for this application.
     */
    public function activeModules(): HasMany
    {
        return $this->hasMany(Module::class)
            ->where('is_active', true)
            ->orderBy('order');
    }

    /**
     * Get the users for the application.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_applications')
                    ->withPivot(['permissions', 'is_active', 'granted_at', 'expires_at'])
                    ->withTimestamps();
    }

    /**
     * Scope a query to only include active applications.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the full path for the application.
     */
    public function getFullPathAttribute()
    {
        return "/{$this->enterprise->slug}/{$this->slug}";
    }
}
