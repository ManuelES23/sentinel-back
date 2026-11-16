<?php

namespace App\Services\RH;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use Illuminate\Support\Collection;

/**
 * Personas informadas de un paso «Usuario específico» de las vacaciones (p. ej. la asistente del director):
 * ven en solo consulta lo que espera a ese paso y lo que ese paso ya aprobó; nunca deciden.
 *
 * La cadena congelada de cada solicitud guarda en `rules` los ids de las reglas del paso y se leen en vivo:
 * solo cuentan las reglas ACTIVAS. Los pasos de RH (visto bueno y resolución final, `rules = []`) no informan.
 * `rules` se filtra en PHP sobre conjuntos acotados (whereJsonContains no es fiable en SQLite).
 */
class InformadosVacaciones
{
    public function __construct(private AprobadorVacaciones $aprobador) {}

    /** @var array<int, Collection<int, int>> reglas que informa cada usuario (el servicio vive una petición) */
    private array $reglasPorUsuario = [];

    /** @return Collection<int, int> ids de reglas activas «Usuario específico» de vacaciones donde el usuario es informado */
    public function reglasQueInforma(User $usuario): Collection
    {
        return $this->reglasDeUsuario((int) $usuario->id);
    }

    public function esInformado(User $usuario): bool
    {
        return $this->reglasQueInforma($usuario)->isNotEmpty();
    }

    /** ¿El usuario es informado de alguna regla de este paso? Los pasos de RH nunca informan. */
    public function informaDelPaso(User $usuario, VacationRequestApproval $paso): bool
    {
        return $this->compartenRegla($paso, $this->reglasQueInforma($usuario));
    }

    /** @return Collection<int, User> informados de las reglas activas del paso, sin el solicitante */
    public function informadosDelPaso(VacationRequest $solicitud, VacationRequestApproval $paso): Collection
    {
        if ($paso->is_rh_final || $paso->is_rh_review) {
            return collect();
        }

        return $this->reglasActivas($this->idsDeReglas($paso))
            ->with('observers')
            ->get()
            ->flatMap(fn (ApprovalFlowStep $regla) => $regla->observers)
            ->unique('id')
            ->reject(fn (User $usuario) => $this->aprobador->esSolicitante($usuario, $solicitud))
            ->values();
    }

    /** @return Collection<int, VacationRequest> pendientes cuyo paso actual informa el usuario, sin las suyas */
    public function pendientesPara(User $usuario): Collection
    {
        $reglas = $this->reglasQueInforma($usuario);
        if ($reglas->isEmpty()) {
            return collect();
        }

        $propio = $usuario->employee()->value('id');

        return VacationRequest::where('status', 'pending')
            ->whereNotNull('current_step')
            ->when($propio !== null, fn ($q) => $q->where('employee_id', '!=', $propio))
            ->with(['employee.department:id,name,manager_id', 'employee.enterprise:id,name', 'approvals'])
            ->lazyById(100)
            ->filter(function (VacationRequest $solicitud) use ($usuario, $reglas) {
                // Mismo paso que CadenaAprobacionVacaciones::pasoActual, tomado de las aprobaciones ya cargadas.
                $paso = $solicitud->approvals->first(fn (VacationRequestApproval $p) => (int) $p->step_order === (int) $solicitud->current_step
                    && $p->decision === VacationRequestApproval::WAITING);

                return $paso !== null
                    && $this->compartenRegla($paso, $reglas)
                    && ! $this->aprobador->esSolicitante($usuario, $solicitud);
            })
            ->collect()
            ->sortByDesc('created_at')
            ->values();
    }

    /**
     * @return Collection<int, int> ids de solicitudes aprobadas, vigentes (end_date >= hoy), con algún paso aprobado
     *                              que tiene una regla de la que el usuario es informado
     */
    public function idsAprobadasQueInforma(int $userId): Collection
    {
        $reglas = $this->reglasDeUsuario($userId);
        if ($reglas->isEmpty()) {
            return collect();
        }

        return VacationRequestApproval::query()
            ->select(['id', 'vacation_request_id', 'rules', 'is_rh_final', 'is_rh_review'])
            ->where('decision', VacationRequestApproval::APPROVED)
            ->whereHas('vacationRequest', fn ($q) => $q->where('status', 'approved')->whereDate('end_date', '>=', today()))
            ->lazyById(500)
            ->filter(fn (VacationRequestApproval $paso) => $this->compartenRegla($paso, $reglas))
            ->map(fn (VacationRequestApproval $paso) => (int) $paso->vacation_request_id)
            ->collect()
            ->unique()
            ->sort()
            ->values();
    }

    /** @return Collection<int, int> ids de usuarios informados de las reglas activas de los pasos aprobados de la solicitud */
    public function informadosDeAprobadas(VacationRequest $solicitud): Collection
    {
        $reglas = $solicitud->approvals()
            ->where('decision', VacationRequestApproval::APPROVED)
            ->get()
            ->reject(fn (VacationRequestApproval $paso) => $paso->is_rh_final || $paso->is_rh_review)
            ->flatMap(fn (VacationRequestApproval $paso) => $this->idsDeReglas($paso))
            ->unique()
            ->values()
            ->all();

        if ($reglas === []) {
            return collect();
        }

        return $this->reglasActivas($reglas)
            ->with('observers:id')
            ->get()
            ->flatMap(fn (ApprovalFlowStep $regla) => $regla->observers->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function reglasDeUsuario(int $userId): Collection
    {
        if (isset($this->reglasPorUsuario[$userId])) {
            return $this->reglasPorUsuario[$userId];
        }

        $proceso = ApprovalProcess::findByCode(ApprovalProcess::VACATION_REQUESTS);

        return $this->reglasPorUsuario[$userId] = $proceso === null
            ? collect()
            : ApprovalFlowStep::where('approval_process_id', $proceso->id)
                ->where('is_active', true)
                ->where('approver_type', ApprovalFlowStep::TYPE_USER)
                ->whereHas('observers', fn ($q) => $q->where('users.id', $userId))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values();
    }

    /** Reglas activas «Usuario específico» entre los ids dados. */
    private function reglasActivas(array $ids)
    {
        return ApprovalFlowStep::whereIn('id', $ids)
            ->where('is_active', true)
            ->where('approver_type', ApprovalFlowStep::TYPE_USER);
    }

    private function compartenRegla(VacationRequestApproval $paso, Collection $reglas): bool
    {
        if ($paso->is_rh_final || $paso->is_rh_review || $reglas->isEmpty()) {
            return false;
        }

        return array_intersect($this->idsDeReglas($paso), $reglas->all()) !== [];
    }

    /** @return list<int> */
    private function idsDeReglas(VacationRequestApproval $paso): array
    {
        return array_values(array_map('intval', (array) ($paso->rules ?? [])));
    }
}
