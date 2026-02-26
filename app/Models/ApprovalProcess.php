<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovalProcess extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'module',
        'entity_type',
        'requires_approval',
        'is_active',
    ];

    protected $casts = [
        'requires_approval' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $appends = ['steps_count'];

    // ===== Códigos conocidos =====
    const VACATION_REQUESTS = 'vacation_requests';
    const PURCHASE_ORDERS = 'purchase_orders';
    const INCIDENTS = 'incidents';
    const INVENTORY_MOVEMENTS = 'inventory_movements';

    // ===== Relaciones =====

    public function steps()
    {
        return $this->hasMany(ApprovalFlowStep::class)->orderBy('step_order');
    }

    public function activeSteps()
    {
        return $this->hasMany(ApprovalFlowStep::class)
            ->where('is_active', true)
            ->orderBy('step_order');
    }

    // ===== Accessors =====

    public function getStepsCountAttribute(): int
    {
        return $this->steps()->count();
    }

    // ===== Scopes =====

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRequiresApproval($query)
    {
        return $query->where('requires_approval', true);
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    public function scopeByModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    // ===== Métodos =====

    /**
     * Verificar si un empleado puede aprobar este proceso
     */
    public function canBeApprovedBy(Employee $employee, ?int $enterpriseId = null): bool
    {
        if (!$this->requires_approval || !$this->is_active) {
            return false;
        }

        $position = $employee->position;
        if (!$position) {
            return false;
        }

        $steps = $this->activeSteps()
            ->where(function ($query) use ($enterpriseId) {
                $query->whereNull('enterprise_id');
                if ($enterpriseId) {
                    $query->orWhere('enterprise_id', $enterpriseId);
                }
            })
            ->get();

        foreach ($steps as $step) {
            if ($step->matchesEmployee($employee, $position)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtener los aprobadores válidos para este proceso en una empresa
     */
    public function getApprovers(?int $enterpriseId = null): array
    {
        $steps = $this->activeSteps()
            ->where(function ($query) use ($enterpriseId) {
                $query->whereNull('enterprise_id');
                if ($enterpriseId) {
                    $query->orWhere('enterprise_id', $enterpriseId);
                }
            })
            ->get();

        $approverPositionIds = [];

        foreach ($steps as $step) {
            if ($step->approver_type === 'position' && $step->position_id) {
                $approverPositionIds[] = $step->position_id;
            } elseif ($step->approver_type === 'hierarchy_level' && $step->min_hierarchy_level) {
                $positions = Position::where('hierarchy_level', '<=', $step->min_hierarchy_level)
                    ->where('can_approve', true)
                    ->pluck('id')
                    ->toArray();
                $approverPositionIds = array_merge($approverPositionIds, $positions);
            }
        }

        return array_unique($approverPositionIds);
    }

    /**
     * Buscar proceso por código (estático)
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }
}
