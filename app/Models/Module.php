<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Module extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'slug',
        'name',
        'description',
        'icon',
        'path',
        'order',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer'
    ];

    /**
     * Obtener la aplicación a la que pertenece este módulo
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Obtener los submódulos de este módulo
     */
    public function submodules(): HasMany
    {
        return $this->hasMany(Submodule::class)->orderBy('order');
    }

    /**
     * Obtener los submódulos activos
     */
    public function activeSubmodules(): HasMany
    {
        return $this->hasMany(Submodule::class)
            ->where('is_active', true)
            ->orderBy('order');
    }

    /**
     * Usuarios que tienen permisos en este módulo
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_module_permissions')
            ->withPivot(['can_view', 'can_create', 'can_edit', 'can_delete', 'is_active', 'granted_at', 'expires_at'])
            ->withTimestamps();
    }

    /**
     * Scope para módulos activos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para ordenar por orden
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
