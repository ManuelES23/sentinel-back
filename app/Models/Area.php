<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Area extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'code',
        'name',
        'slug',
        'description',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($area) {
            if (empty($area->slug)) {
                $area->slug = Str::slug($area->name);
            }
        });

        static::updating(function ($area) {
            if ($area->isDirty('name') && empty($area->slug)) {
                $area->slug = Str::slug($area->name);
            }
        });
    }

    // Relaciones - Muchas entidades pueden tener esta área
    public function entities()
    {
        return $this->belongsToMany(Entity::class, 'entity_area')
            ->withPivot(['location', 'area_m2', 'responsible', 'is_active', 'allows_inventory', 'metadata'])
            ->withTimestamps();
    }

    // Relaciones - Muchos departamentos pueden operar en esta área
    public function departments()
    {
        return $this->belongsToMany(Department::class, 'department_area')
            ->withPivot(['relationship_type', 'is_primary', 'notes'])
            ->withTimestamps();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
