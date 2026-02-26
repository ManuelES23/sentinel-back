<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Loggable;

class Department extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'enterprise_id',
        'code',
        'name',
        'description',
        'parent_id',
        'manager_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['full_name', 'employees_count'];

    // ==================== RELACIONES ====================

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function parent()
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Department::class, 'parent_id');
    }

    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function positions()
    {
        return $this->hasMany(Position::class);
    }

    public function areas()
    {
        return $this->belongsToMany(Area::class, 'department_area')
            ->withPivot(['relationship_type', 'is_primary', 'notes'])
            ->withTimestamps();
    }

    // ==================== ACCESSORS ====================

    public function getFullNameAttribute(): string
    {
        if ($this->parent) {
            return $this->parent->name . ' > ' . $this->name;
        }
        return $this->name;
    }

    public function getEmployeesCountAttribute(): int
    {
        return $this->employees()->where('status', 'active')->count();
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEnterprise($query, $enterpriseId)
    {
        return $query->where('enterprise_id', $enterpriseId);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    // ==================== MÉTODOS ====================

    public static function generateCode($enterpriseId): string
    {
        $count = self::where('enterprise_id', $enterpriseId)->withTrashed()->count() + 1;
        return 'DEP-' . str_pad($count, 3, '0', STR_PAD_LEFT);
    }
}
