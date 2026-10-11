<?php

namespace App\Services\Compras;

use App\Models\ApprovalProcess;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\ApprovalNotificationService;
use Illuminate\Support\Collection;

/**
 * Autorización de OC por el flujo de aprobación `purchase_orders`: el puesto
 * del empleado debe ser aprobador, el creador de la OC debe caer en su alcance
 * y nadie aprueba su propia OC. Un solo paso.
 *
 * Aprobador de la empresa de la OC: respeta el alcance (departamento o empresa).
 * Aprobador corporativo (empleado de Grupo Espléndido): aprueba las OC de las
 * empresas cuya regla nombra su puesto (o de todas, si esa regla no fija
 * empresa); su departamento es del corporativo, así que el alcance es toda la
 * empresa de la OC.
 */
class AprobadorOrdenCompra
{
    public const EMPRESA_CORPORATIVA = 'grupoesplendido';

    private ?int $corporativoId = null;

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
        $corporativo = $this->esCorporativo($empleado) && (int) $empleado->enterprise_id !== $empresaId;
        if (! $corporativo && (int) $empleado->enterprise_id !== $empresaId) {
            return false;
        }

        $proceso = $this->proceso();
        if (! $proceso) {
            return false;
        }

        if ($corporativo) {
            return $this->pasosConSuPuesto($proceso, $empleado)
                ->contains(fn ($p) => $p->enterprise_id === null || (int) $p->enterprise_id === $empresaId);
        }

        if (! $proceso->canBeApprovedBy($empleado, $empresaId)) {
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

    /**
     * Empresas de las que este empleado puede autorizar OC: la suya si una
     * regla lo incluye y, si es corporativo, las que fije cada regla con su
     * puesto (todas, si alguna de esas reglas no fija empresa).
     *
     * @return array<int, int>
     */
    public function empresasQuePuedeAprobar(Employee $empleado): array
    {
        $proceso = $this->proceso();
        if (! $proceso || $empleado->status !== 'active') {
            return [];
        }

        $propia = (int) $empleado->enterprise_id;
        $empresas = $proceso->canBeApprovedBy($empleado, $propia) ? [$propia] : [];

        if ($this->esCorporativo($empleado)) {
            $pasos = $this->pasosConSuPuesto($proceso, $empleado);
            $empresas = $pasos->contains(fn ($p) => $p->enterprise_id === null)
                ? Enterprise::pluck('id')->map(fn ($id) => (int) $id)->all()
                : array_merge($empresas, $pasos->pluck('enterprise_id')->map(fn ($id) => (int) $id)->all());
        }

        return array_values(array_unique($empresas));
    }

    /**
     * Reglas que nombran el puesto del empleado. Solo estas extienden a un
     * corporativo a otras empresas: una regla por nivel jerárquico pensada
     * para los gerentes de cada empresa no lo vuelve aprobador de todas.
     *
     * @return Collection<int, \App\Models\ApprovalFlowStep>
     */
    private function pasosConSuPuesto(ApprovalProcess $proceso, Employee $empleado): Collection
    {
        if (! $proceso->is_active || ! $proceso->requires_approval || ! $empleado->position_id) {
            return collect();
        }

        return $proceso->activeSteps()
            ->where('approver_type', 'position')
            ->where('position_id', $empleado->position_id)
            ->get()
            ->filter(fn ($paso) => $paso->matchesEmployee($empleado))
            ->values();
    }

    public function esCorporativo(Employee $empleado): bool
    {
        return $this->corporativoId() !== 0 && (int) $empleado->enterprise_id === $this->corporativoId();
    }

    /** @return Collection<int, User> */
    public function aprobadoresDe(PurchaseOrder $oc): Collection
    {
        $proceso = $this->proceso();
        if (! $proceso || ! $oc->enterprise_id) {
            return collect();
        }

        $empresas = array_filter([(int) $oc->enterprise_id, $this->corporativoId()]);

        return Employee::whereIn('position_id', $proceso->getApprovers($oc->enterprise_id))
            ->whereIn('enterprise_id', $empresas)
            ->where('status', 'active')
            ->whereNotNull('user_id')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter(fn ($u) => $u && $this->puedeAprobar($u, $oc))
            ->unique('id')
            ->values();
    }

    private function corporativoId(): int
    {
        return $this->corporativoId ??= (int) Enterprise::where('slug', self::EMPRESA_CORPORATIVA)->value('id');
    }

    private function proceso(): ?ApprovalProcess
    {
        return ApprovalProcess::findByCode(ApprovalProcess::PURCHASE_ORDERS);
    }
}
