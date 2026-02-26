<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Loggable;

class Position extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    // Niveles jerárquicos organizacionales
    const LEVEL_CEO = 1;              // Director General / CEO
    const LEVEL_DIRECTOR = 2;         // Director de Área
    const LEVEL_MANAGER = 3;          // Gerente
    const LEVEL_HEAD = 4;             // Jefe de Departamento
    const LEVEL_COORDINATOR = 5;      // Coordinador
    const LEVEL_SUPERVISOR = 6;       // Supervisor
    const LEVEL_OPERATIVE = 7;        // Operativo

    const HIERARCHY_LABELS = [
        self::LEVEL_CEO => 'Director General',
        self::LEVEL_DIRECTOR => 'Director',
        self::LEVEL_MANAGER => 'Gerente',
        self::LEVEL_HEAD => 'Jefe de Departamento',
        self::LEVEL_COORDINATOR => 'Coordinador',
        self::LEVEL_SUPERVISOR => 'Supervisor',
        self::LEVEL_OPERATIVE => 'Operativo',
    ];

    const SCOPE_OWN_DEPARTMENT = 'own_department';
    const SCOPE_CHILD_DEPARTMENTS = 'child_departments';
    const SCOPE_ENTERPRISE = 'enterprise';

    protected $fillable = [
        'enterprise_id',
        'code',
        'name',
        'description',
        'department_id',
        'hierarchy_level',
        'can_approve',
        'approval_scope',
        'min_salary',
        'max_salary',
        'is_active',
    ];

    protected $casts = [
        'min_salary' => 'decimal:2',
        'max_salary' => 'decimal:2',
        'is_active' => 'boolean',
        'can_approve' => 'boolean',
        'hierarchy_level' => 'integer',
    ];

    protected $appends = ['employees_count', 'hierarchy_label'];

    // ==================== RELACIONES ====================

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    // ==================== ACCESSORS ====================

    public function getEmployeesCountAttribute(): int
    {
        return $this->employees()->where('status', 'active')->count();
    }

    public function getSalaryRangeAttribute(): ?string
    {
        if ($this->min_salary && $this->max_salary) {
            return '$' . number_format($this->min_salary, 2) . ' - $' . number_format($this->max_salary, 2);
        }
        return null;
    }

    public function getHierarchyLabelAttribute(): string
    {
        return self::HIERARCHY_LABELS[$this->hierarchy_level] ?? 'Operativo';
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

    public function scopeForDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    public function scopeApprovers($query)
    {
        return $query->where('can_approve', true);
    }

    public function scopeByHierarchy($query, int $level)
    {
        return $query->where('hierarchy_level', '<=', $level);
    }

    // ==================== MÉTODOS ====================

    public static function generateCode($enterpriseId): string
    {
        $count = self::where('enterprise_id', $enterpriseId)->withTrashed()->count() + 1;
        return 'POS-' . str_pad($count, 3, '0', STR_PAD_LEFT);
    }
}
