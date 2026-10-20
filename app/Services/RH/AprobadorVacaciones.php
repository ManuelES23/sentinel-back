<?php

namespace App\Services\RH;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\Department;
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
 * - Jefe del departamento: el manager_id del departamento del solicitante. También decide
 *   desde el portal (número + PIN) aunque no tenga usuario: puedeDecidirComoEmpleado.
 * - Usuario específico: el user_id de la regla; no necesita ser empleado (si lo es, debe estar activo).
 * - Aprobador de la misma empresa: su regla (puesto o nivel) y su alcance por
 *   departamento (un solicitante sin departamento solo entra con alcance
 *   'enterprise').
 * - Corporativo (Grupo Espléndido) en otra empresa: solo por una regla de
 *   PUESTO para todas las empresas o para esa; el alcance es toda la empresa.
 * - Paso final de RH: quien tiene `aprobar` en RH → Gestión → Vacaciones.
 * Siempre: tener usuario y empleado activo (salvo el usuario específico sin
 * empleado), no ser el solicitante y no haber decidido otro paso de la misma solicitud.
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
            ->with(['position:id,name', 'user:id,name'])
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
        if (! $solicitante) {
            return false;
        }

        $bandera = $accion === 'rechazar' ? 'can_reject' : 'can_approve';

        return $this->reglasDelPaso($paso)
            ->filter(fn (ApprovalFlowStep $regla) => (bool) $regla->{$bandera})
            ->contains(fn (ApprovalFlowStep $regla) => $this->cumpleRegla($usuario, $aprobador, $solicitante, $regla));
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
            ->filter(fn (User $usuario) => $this->puedeDecidir($usuario, $solicitud, $paso))
            ->values();
    }

    /**
     * Jefe del departamento del solicitante que hoy podría decidir el paso desde el portal
     * (número + PIN), con o sin usuario de Sentinel. Solo la regla «Jefe del departamento»
     * abre esa puerta; los demás tipos siguen exigiendo usuario.
     *
     * @return Collection<int, Employee>
     */
    public function jefesDelPortal(VacationRequest $solicitud, VacationRequestApproval $paso): Collection
    {
        $solicitante = $solicitud->employee;
        $jefeId = $solicitante ? $this->jefeDelDepartamento($solicitante) : null;
        if ($jefeId === null || $paso->is_rh_final || $paso->decision !== VacationRequestApproval::WAITING) {
            return collect();
        }

        $jefe = Employee::whereKey($jefeId)->first();

        return $jefe && $this->puedeDecidirComoEmpleado($jefe, $solicitud, $paso) ? collect([$jefe]) : collect();
    }

    /** ¿Alguien (usuario de Sentinel o jefe por el portal) puede decidir el paso? Si no, la cadena lo salta. */
    public function hayQuienDecida(VacationRequest $solicitud, VacationRequestApproval $paso): bool
    {
        return $this->elegiblesDelPaso($solicitud, $paso)->isNotEmpty() || $this->jefesDelPortal($solicitud, $paso)->isNotEmpty();
    }

    /** Decisión de un jefe de departamento desde el portal: el empleado de la sesión, no un usuario. */
    public function puedeDecidirComoEmpleado(Employee $jefe, VacationRequest $solicitud, VacationRequestApproval $paso, string $accion = 'aprobar'): bool
    {
        if ($paso->decision !== VacationRequestApproval::WAITING || $paso->is_rh_final || $jefe->status !== Employee::STATUS_ACTIVE) {
            return false;
        }

        $solicitante = $solicitud->employee;
        if (! $solicitante || (int) $jefe->id === (int) $solicitante->id) {
            return false;
        }

        // employees.user_id no es único: dos fichas ligadas al mismo usuario son la misma persona.
        if ($jefe->user_id !== null && (int) $jefe->user_id === (int) $solicitante->user_id) {
            return false;
        }

        if ($this->empleadoDecidioOtroPaso($jefe, $solicitud, $paso)) {
            return false;
        }

        $jefeDelDepartamento = $this->jefeDelDepartamento($solicitante);
        if ($jefeDelDepartamento === null || $jefeDelDepartamento !== (int) $jefe->id) {
            return false;
        }

        $bandera = $accion === 'rechazar' ? 'can_reject' : 'can_approve';

        return $this->reglasDelPaso($paso)->contains(
            fn (ApprovalFlowStep $regla) => $regla->approver_type === ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER && (bool) $regla->{$bandera}
        );
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

    private function cumpleRegla(User $usuario, ?Employee $aprobador, Employee $solicitante, ApprovalFlowStep $regla): bool
    {
        // El usuario específico puede no ser empleado; si lo es, no puede estar dado de baja.
        if ($regla->approver_type === ApprovalFlowStep::TYPE_USER) {
            return $regla->user_id !== null
                && (int) $regla->user_id === (int) $usuario->id
                && ($aprobador === null || $aprobador->status === Employee::STATUS_ACTIVE);
        }

        if (! $aprobador || $aprobador->status !== Employee::STATUS_ACTIVE) {
            return false;
        }

        if ($regla->approver_type === ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR) {
            return $solicitante->reports_to !== null && (int) $solicitante->reports_to === (int) $aprobador->id;
        }

        if ($regla->approver_type === ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER) {
            $jefe = $this->jefeDelDepartamento($solicitante);

            return $jefe !== null && $jefe === (int) $aprobador->id;
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

    /**
     * manager_id del departamento del solicitante. Las listas cargan el departamento con pocas
     * columnas (`department:id,name`), así que si manager_id no vino se consulta en vez de tomarlo por nulo.
     */
    private function jefeDelDepartamento(Employee $solicitante): ?int
    {
        if ($solicitante->department_id === null) {
            return null;
        }

        $departamento = $solicitante->relationLoaded('department') ? $solicitante->department : null;
        $jefe = $departamento && array_key_exists('manager_id', $departamento->getAttributes())
            ? $departamento->manager_id
            : Department::whereKey($solicitante->department_id)->value('manager_id');

        return $jefe === null ? null : (int) $jefe;
    }

    /** @return Collection<int, User> usuarios que la regla podría admitir (la decisión final es puedeDecidir) */
    private function candidatosDeRegla(ApprovalFlowStep $regla, Employee $solicitante): Collection
    {
        if ($regla->approver_type === ApprovalFlowStep::TYPE_USER) {
            return $regla->user_id ? User::whereKey($regla->user_id)->get() : collect();
        }

        $base = Employee::with(['user', 'position'])
            ->where('status', Employee::STATUS_ACTIVE)
            ->whereNotNull('user_id');

        $empleados = match ($regla->approver_type) {
            ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR => $solicitante->reports_to
                ? $base->whereKey($solicitante->reports_to)->get()
                : collect(),
            ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER => $this->jefeDelDepartamento($solicitante)
                ? $base->whereKey($this->jefeDelDepartamento($solicitante))->get()
                : collect(),
            'position' => $base->where('position_id', $regla->position_id)
                ->whereIn('enterprise_id', array_values(array_filter([(int) $solicitante->enterprise_id, $this->corporativoId()])))
                ->get(),
            'hierarchy_level' => $base->where('enterprise_id', $solicitante->enterprise_id)
                ->whereHas('position', fn ($q) => $q->where('can_approve', true)->where('hierarchy_level', '<=', $regla->min_hierarchy_level))
                ->get(),
            default => collect(),
        };

        return $empleados->map(fn (Employee $empleado) => $empleado->user)->filter()->values();
    }

    private function decidioOtroPaso(User $usuario, VacationRequest $solicitud, VacationRequestApproval $paso): bool
    {
        return VacationRequestApproval::where('vacation_request_id', $solicitud->id)
            ->where('id', '!=', $paso->id)
            ->where('decided_by', $usuario->id)
            ->whereIn('decision', [VacationRequestApproval::APPROVED, VacationRequestApproval::REJECTED])
            ->exists();
    }

    private function empleadoDecidioOtroPaso(Employee $jefe, VacationRequest $solicitud, VacationRequestApproval $paso): bool
    {
        return VacationRequestApproval::where('vacation_request_id', $solicitud->id)
            ->where('id', '!=', $paso->id)
            ->whereIn('decision', [VacationRequestApproval::APPROVED, VacationRequestApproval::REJECTED])
            ->where(function ($q) use ($jefe) {
                $q->where('decided_by_employee_id', $jefe->id);
                if ($jefe->user_id !== null) {
                    $q->orWhere('decided_by', $jefe->user_id);
                }
            })
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
