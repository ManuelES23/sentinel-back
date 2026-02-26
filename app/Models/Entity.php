<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Entity extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'branch_id',
        'entity_type_id',
        'code',
        'name',
        'slug',
        'description',
        'location',
        'responsible',
        'area_m2',
        'is_active',
        'is_external',
        'owner_company',
        'contact_person',
        'contact_phone',
        'contact_email',
        'contract_number',
        'contract_start_date',
        'contract_end_date',
        'contract_notes',
        'metadata',
    ];

    protected $casts = [
        'area_m2' => 'decimal:2',
        'is_active' => 'boolean',
        'is_external' => 'boolean',
        'contract_start_date' => 'date',
        'contract_end_date' => 'date',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($entity) {
            if (empty($entity->slug)) {
                $entity->slug = Str::slug($entity->name);
            }
        });

        static::updating(function ($entity) {
            if ($entity->isDirty('name') && empty($entity->slug)) {
                $entity->slug = Str::slug($entity->name);
            }
        });
    }

    // Relaciones
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function entityType()
    {
        return $this->belongsTo(EntityType::class);
    }

    public function areas()
    {
        return $this->belongsToMany(Area::class, 'entity_area')
            ->withPivot(['location', 'area_m2', 'responsible', 'is_active', 'allows_inventory', 'metadata'])
            ->withTimestamps();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeExternal($query)
    {
        return $query->where('is_external', true);
    }

    public function scopeInternal($query)
    {
        return $query->where('is_external', false);
    }

    public function scopeByBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeByType($query, $typeId)
    {
        return $query->where('entity_type_id', $typeId);
    }
}
