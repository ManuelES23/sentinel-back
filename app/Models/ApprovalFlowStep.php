<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovalFlowStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'approval_process_id',
        'enterprise_id',
        'step_order',
        'approver_type',
        'min_hierarchy_level',
        'position_id',
        'approval_scope',
        'can_approve',
        'can_reject',
        'is_active',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'min_hierarchy_level' => 'integer',
        'can_approve' => 'boolean',
        'can_reject' => 'boolean',
        'is_active' => 'boolean',
    ];

    // ===== Relaciones =====

    public function process()
    {
        return $this->belongsTo(ApprovalProcess::class, 'approval_process_id');
    }

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    // ===== Scopes =====

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ===== Métodos =====

    /**
     * Verificar si un empleado cumple con este step de aprobación
     */
    public function matchesEmployee(Employee $employee, ?Position $position = null): bool
    {
        $position = $position ?? $employee->position;

        if (!$position) {
            return false;
        }

        // Verificar que el puesto tenga can_approve habilitado
        if (!$position->can_approve) {
            return false;
        }

        // Verificar tipo de aprobador
        if ($this->approver_type === 'position') {
            return $position->id === $this->position_id;
        }

        if ($this->approver_type === 'hierarchy_level') {
            // El nivel jerárquico del puesto debe ser <= al mínimo requerido
            // (nivel 1 = CEO, nivel 7 = operativo, menor número = más autoridad)
            return $position->hierarchy_level <= $this->min_hierarchy_level;
        }

        return false;
    }

    /**
     * Etiqueta legible del tipo de aprobador
     */
    public function getApproverDescriptionAttribute(): string
    {
        if ($this->approver_type === 'position') {
            return $this->position ? $this->position->name : 'Puesto específico';
        }

        if ($this->approver_type === 'hierarchy_level') {
            $label = Position::HIERARCHY_LABELS[$this->min_hierarchy_level] ?? 'Nivel ' . $this->min_hierarchy_level;
            return "Nivel {$this->min_hierarchy_level} ({$label}) o superior";
        }

        return 'Desconocido';
    }

    /**
     * Etiqueta del alcance
     */
    public function getScopeDescriptionAttribute(): string
    {
        return match ($this->approval_scope) {
            'own_department' => 'Solo su departamento',
            'child_departments' => 'Departamento e hijos',
            'enterprise' => 'Toda la empresa',
            default => $this->approval_scope,
        };
    }
}
