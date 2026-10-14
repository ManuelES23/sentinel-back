<?php

namespace App\Services\RH;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\ApprovalNotificationService;
use App\Support\PermisoSubmodulo;
use Illuminate\Support\Collection;

/**
 * Quién puede decidir cada paso de la cadena de vacaciones (spec §5.3),
 * con el patrón de AprobadorOrdenCompra:
 * - Jefe directo: el reports_to del solicitante.
 * - Aprobador de la misma empresa: su regla (puesto o nivel) y su alcance por
 *   departamento (un solicitante sin departamento solo entra con alcance
 *   'enterprise').
 * - Corporativo (Grupo Espléndido) en otra empresa: solo por una regla de
 *   PUESTO para todas las empresas o para esa; el alcance es toda la empresa.
 * - Paso final de RH: quien tiene `aprobar` en RH → Gestión → Vacaciones.
 * Siempre: tener usuario y empleado activo, no ser el solicitante y no haber
 * decidido otro paso de la misma solicitud.
 */
class AprobadorVacaciones
{
    public const EMPRESA_CORPORATIVA = 'grupoesplendido';

    private ?int $corporativoId = null;

    /** @return Collection<int, Collection<int, ApprovalFlowStep>> reglas activas agrupadas por step_order ascendente */
    public function pasosPara(int $empresaId): Collection
    {
        $proceso = ApprovalProcess::findByCode(ApprovalProcess::VACATION_REQUESTS);
        if (! $proceso || ! $proceso->is_active || ! $proceso->requires_approval) {
            return collect();
        }

        return $proceso->activeSteps()
            ->with('position:id,name')
            ->where(fn ($q) => $q->whereNull('enterprise_id')->orWhere('enterprise_id', $empresaId))
            ->get()
            ->groupBy('step_order')
            ->sortKeys();
    }

    public function puedeDecidir(User $usuario, VacationRequest $solicitud, VacationRequestApproval $paso, string $accion = 'aprobar'): bool
    {
        if ($paso->decision !== VacationRequestApproval::WAITING) {
            return false;
        }

        if ($this->esSolicitante($usuario, $solicitud)) {
            return false;
        }

        // Consulta fresca: la relación en caché del User puede estar vacía si el empleado se ligó después.
        $aprobador = $usuario->employee()->first();

        if ($paso->is_rh_final) {
            return $this->esRh($usuario);
        }

        if ($this->decidioOtroPaso($usuario, $solicitud, $paso)) {
            return false;
        }

        $solicitante = $solicitud->employee;
        if (! $aprobador || $aprobador->status !== Employee::STATUS_ACTIVE || ! $solicitante) {
            return false;
        }

        $bandera = $accion === 'rechazar' ? 'can_reject' : 'can_approve';

        return $this->reglasDelPaso($paso)
            ->filter(fn (ApprovalFlowStep $regla) => (bool) $regla->{$bandera})
            ->contains(fn (ApprovalFlowStep $regla) => $this->cumpleRegla($aprobador, $solicitante, $regla));
    }

    /** @return Collection<int, User> usuarios que hoy podrían aprobar el paso */
    public function elegiblesDelPaso(VacationRequest $solicitud, VacationRequestApproval $paso): Collection
    {
        if ($paso->is_rh_final) {
            return User::whereIn('id', $this->usuariosRhIds())->get()
                ->filter(fn (User $usuario) => $this->puedeDecidir($usuario, $solicitud, $paso))
                ->values();
        }

        $solicitante = $solicitud->employee;
        if (! $solicitante) {
            return collect();
        }

        return $this->reglasDelPaso($paso)
            ->flatMap(fn (ApprovalFlowStep $regla) => $this->candidatosDeRegla($regla, $solicitante))
            ->unique('id')
            ->filter(fn (Employee $empleado) => $empleado->user && $this->puedeDecidir($empleado->user, $solicitud, $paso))
            ->map(fn (Employee $empleado) => $empleado->user)
            ->unique('id')
            ->values();
    }

    /**
     * ¿La solicitud es del propio usuario? employees.user_id no es único (datos viejos pueden
     * ligar varios empleados a un usuario), así que se mira tanto el usuario del empleado
     * solicitante como el empleado propio del usuario (consulta fresca, no la relación en caché).
     */
    public function esSolicitante(User $usuario, VacationRequest $solicitud): bool
    {
        $duenoDeLaSolicitud = $solicitud->employee?->user_id;
        if ($duenoDeLaSolicitud !== null && (int) $duenoDeLaSolicitud === (int) $usuario->id) {
            return true;
        }

        $propio = $usuario->employee()->first();

        return $propio !== null && (int) $propio->id === (int) $solicitud->employee_id;
    }
    public function esRh(User $usuario): bool
    {
        $corporativo = $this->corporativoId();

        return $corporativo !== 0 && PermisoSubmodulo::tiene($usuario->id, $corporativo, 'rh', 'gestion', 'vacaciones', 'aprobar');
    }

    public function esCorporativo(Employee $empleado): bool
    {
        return $this->corporativoId() !== 0 && (int) $empleado->enterprise_id === $this->corporativoId();
    }

    /** @return Collection<int, ApprovalFlowStep> */
    private function reglasDelPaso(VacationRequestApproval $paso): Collection
    {
        return ApprovalFlowStep::with('position')
            ->whereIn('id', $paso->rules ?? [])
            ->where('is_active', true)
            ->get();
    }

    private function cumpleRegla(Employee $aprobador, Employee $solicitante, ApprovalFlowStep $regla): bool
    {
        if ($regla->approver_type === ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR) {
            return $solicitante->reports_to !== null && (int) $solicitante->reports_to === (int) $aprobador->id;
        }

        if ((int) $aprobador->enterprise_id !== (int) $solicitante->enterprise_id) {
            return $this->esCorporativo($aprobador)
                && $regla->approver_type === 'position'
                && $regla->matchesEmployee($aprobador)
                && ($regla->enterprise_id === null || (int) $regla->enterprise_id === (int) $solicitante->enterprise_id);
        }

        if (! $regla->matchesEmployee($aprobador)) {
            return false;
        }

        $alcance = ApprovalNotificationService::getApproverScope($aprobador, collect([$regla]));
        if ($alcance === 'enterprise') {
            return true;
        }

        if ($aprobador->department_id === null || $solicitante->department_id === null) {
            return false;
        }

        if ($alcance === 'child_departments') {
            $departamentos = array_map('intval', ApprovalNotificationService::getDepartmentAndChildIds((int) $aprobador->department_id));

            return in_array((int) $solicitante->department_id, $departamentos, true);
        }

        return (int) $aprobador->department_id === (int) $solicitante->department_id;
    }

    /** @return Collection<int, Employee> */
    private function candidatosDeRegla(ApprovalFlowStep $regla, Employee $solicitante): Collection
    {
        $base = Employee::with(['user', 'position'])
            ->where('status', Employee::STATUS_ACTIVE)
            ->whereNotNull('user_id');

        return match ($regla->approver_type) {
            ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR => $solicitante->reports_to
                ? $base->whereKey($solicitante->reports_to)->get()
                : collect(),
            'position' => $base->where('position_id', $regla->position_id)
                ->whereIn('enterprise_id', array_values(array_filter([(int) $solicitante->enterprise_id, $this->corporativoId()])))
                ->get(),
            'hierarchy_level' => $base->where('enterprise_id', $solicitante->enterprise_id)
                ->whereHas('position', fn ($q) => $q->where('can_approve', true)->where('hierarchy_level', '<=', $regla->min_hierarchy_level))
                ->get(),
            default => collect(),
        };
    }

    private function decidioOtroPaso(User $usuario, VacationRequest $solicitud, VacationRequestApproval $paso): bool
    {
        return VacationRequestApproval::where('vacation_request_id', $solicitud->id)
            ->where('id', '!=', $paso->id)
            ->where('decided_by', $usuario->id)
            ->whereIn('decision', [VacationRequestApproval::APPROVED, VacationRequestApproval::REJECTED])
            ->exists();
    }

    /** @return list<int> */
    private function usuariosRhIds(): array
    {
        $corporativo = $this->corporativoId();

        return $corporativo ? PermisoSubmodulo::usuariosCon($corporativo, 'rh', 'gestion', 'vacaciones', 'aprobar') : [];
    }

    private function corporativoId(): int
    {
        return $this->corporativoId ??= (int) Enterprise::where('slug', self::EMPRESA_CORPORATIVA)->value('id');
    }
}
