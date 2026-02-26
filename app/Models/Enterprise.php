<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Enterprise extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'logo',
        'domain',
        'color',
        'config',
        'active',
        'is_active'
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
        'active' => 'boolean'
    ];

    protected $appends = ['logo_url'];

    /**
     * Get the full URL for the enterprise logo.
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo) {
            return null;
        }
        
        // Si ya es una URL completa, devolverla tal cual
        if (str_starts_with($this->logo, 'http://') || str_starts_with($this->logo, 'https://')) {
            return $this->logo;
        }
        
        // Construir URL desde storage
        return asset('storage/' . $this->logo);
    }

    /**
     * Get the applications for the enterprise.
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * Get the users for the enterprise.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_enterprises')
                    ->withPivot(['role', 'is_active', 'granted_at', 'expires_at'])
                    ->withTimestamps();
    }

    /**
     * Horarios de trabajo asignados a esta empresa (muchos a muchos)
     */
    public function workSchedules(): BelongsToMany
    {
        return $this->belongsToMany(WorkSchedule::class, 'enterprise_work_schedule')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /**
     * Obtener el horario por defecto de la empresa
     */
    public function defaultWorkSchedule()
    {
        return $this->workSchedules()->wherePivot('is_default', true)->first();
    }

    /**
     * Get active applications for the enterprise.
     */
    public function activeApplications(): HasMany
    {
        return $this->applications()->where('is_active', true);
    }

    /**
     * Scope a query to only include active enterprises.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
