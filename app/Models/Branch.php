<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Branch extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'enterprise_id',
        'code',
        'name',
        'slug',
        'description',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'phone',
        'email',
        'manager',
        'is_active',
        'is_main',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_main' => 'boolean',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($branch) {
            if (empty($branch->slug)) {
                $branch->slug = Str::slug($branch->name);
            }
        });

        static::updating(function ($branch) {
            if ($branch->isDirty('name') && empty($branch->slug)) {
                $branch->slug = Str::slug($branch->name);
            }
        });
    }

    // Relaciones
    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function entities()
    {
        return $this->hasMany(Entity::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeMain($query)
    {
        return $query->where('is_main', true);
    }
}
