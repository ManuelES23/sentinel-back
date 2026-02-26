<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Submodule extends Model
{
    use HasFactory;

    protected $fillable = [
        'module_id',
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
     * Obtener el módulo al que pertenece este submódulo
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * Usuarios que tienen permisos en este submódulo
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_submodule_permissions')
            ->withPivot(['can_view', 'can_create', 'can_edit', 'can_delete', 'is_active', 'granted_at', 'expires_at'])
            ->withTimestamps();
    }

    /**
     * Scope para submódulos activos
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

    /**
     * Obtener los tipos de permisos definidos para este submódulo
     */
    public function permissionTypes()
    {
        return $this->hasMany(SubmodulePermissionType::class);
    }

    /**
     * Obtener la ruta completa del submódulo
     */
    public function getFullPathAttribute(): string
    {
        $modulePath = $this->module->path ?? '';
        $submodulePath = $this->path ?? '';
        return $modulePath . $submodulePath;
    }
}
