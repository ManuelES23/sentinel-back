<?php

namespace App\Services\Compras;

use App\Models\ApprovalProcess;
use App\Models\Employee;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\ApprovalNotificationService;
use Illuminate\Support\Collection;

/**
 * Autorización de OC por el flujo de aprobación `purchase_orders` de la
 * empresa: el puesto del empleado debe ser aprobador, el creador de la OC
 * debe caer en su alcance y nadie aprueba su propia OC. Un solo paso.
 */
class AprobadorOrdenCompra
{
    public function hayAprobadores(int $empresaId): bool
    {
        $proceso = $this->proceso();

        return $proceso !== null
            && $proceso->is_active
            && $proceso->requires_approval
            && ! empty($proceso->getApprovers($empresaId));
    }

    public function puedeAprobar(User $user, PurchaseOrder $oc): bool
    {
        if ((int) $oc->created_by === (int) $user->id) {
            return false;
        }

        $empleado = $user->employee;
        if (! $empleado || $empleado->status !== 'active') {
            return false;
        }

        $empresaId = (int) ($oc->enterprise_id ?? $empleado->enterprise_id);
        if ((int) $empleado->enterprise_id !== $empresaId) {
            return false;
        }

        $proceso = $this->proceso();
        if (! $proceso || ! $proceso->canBeApprovedBy($empleado, $empresaId)) {
            return false;
        }

        $pasos = $proceso->activeSteps()
            ->where(fn ($q) => $q->whereNull('enterprise_id')->orWhere('enterprise_id', $empresaId))
            ->get();
        $alcance = ApprovalNotificationService::getApproverScope($empleado, $pasos);

        if ($alcance === 'enterprise') {
            return true;
        }

        $solicitante = $oc->createdByUser?->employee;
        if (! $solicitante || ! $empleado->department_id) {
            return false;
        }

        if ($alcance === 'child_departments') {
            return in_array(
                $solicitante->department_id,
                ApprovalNotificationService::getDepartmentAndChildIds($empleado->department_id)
            );
        }

        return $solicitante->department_id === $empleado->department_id;
    }

    /** @return Collection<int, User> */
    public function aprobadoresDe(PurchaseOrder $oc): Collection
    {
        $proceso = $this->proceso();
        if (! $proceso || ! $oc->enterprise_id) {
            return collect();
        }

        return Employee::whereIn('position_id', $proceso->getApprovers($oc->enterprise_id))
            ->where('enterprise_id', $oc->enterprise_id)
            ->where('status', 'active')
            ->whereNotNull('user_id')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter(fn ($u) => $u && $this->puedeAprobar($u, $oc))
            ->unique('id')
            ->values();
    }

    private function proceso(): ?ApprovalProcess
    {
        return ApprovalProcess::findByCode(ApprovalProcess::PURCHASE_ORDERS);
    }
}
