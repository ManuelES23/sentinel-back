<?php

namespace App\Http\Controllers\Api\Portal;

use App\Exceptions\RH\VacacionesException;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\RH\AprobadorVacaciones;
use App\Services\RH\VacationRequestService;
use App\Support\RH\PresentadorPortalVacaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Revisión de solicitudes por el jefe de departamento desde el portal (número + PIN), con o sin
 * usuario de Sentinel. El jefe sale siempre de la sesión; solo ve las solicitudes cuyo paso actual
 * le toca decidir (AprobadorVacaciones::puedeDecidirComoEmpleado). Una ajena o que ya no le toca
 * da el mismo 404 que una inexistente. Lo que sale pasa por PresentadorPortalVacaciones::paraRevisar.
 */
class VacationPortalRevisionController extends Controller
{
    public function __construct(private AprobadorVacaciones $aprobador, private VacationRequestService $vacaciones) {}

    public function index(Request $request): JsonResponse
    {
        $jefe = $this->jefe($request);

        return response()->json([
            'success' => true,
            'data' => $this->revisables($jefe)->map(fn (VacationRequest $solicitud) => PresentadorPortalVacaciones::paraRevisar($solicitud))->values(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => PresentadorPortalVacaciones::paraRevisar($this->revisable($this->jefe($request), $id)),
        ]);
    }

    public function aprobar(Request $request, int $id): JsonResponse
    {
        $jefe = $this->jefe($request);
        $solicitud = $this->vacaciones->aprobarPaso($this->revisable($jefe, $id), $jefe);

        return response()->json([
            'success' => true,
            'message' => $solicitud->status === 'approved' ? 'Solicitud aprobada.' : 'Tu aprobación quedó registrada; la solicitud sigue su revisión.',
            'data' => ['id' => $solicitud->id, 'estado' => $solicitud->status],
        ]);
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $jefe = $this->jefe($request);
        $motivo = $request->validate(['reason' => 'required|string|max:500'])['reason'];
        $solicitud = $this->vacaciones->rechazar($this->revisable($jefe, $id), $jefe, $motivo);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud rechazada.',
            'data' => ['id' => $solicitud->id, 'estado' => $solicitud->status],
        ]);
    }

    /** @return Collection<int, VacationRequest> pendientes cuyo paso actual puede decidir el jefe hoy */
    private function revisables(Employee $jefe): Collection
    {
        $departamentos = Department::where('manager_id', $jefe->id)->pluck('id');
        if ($departamentos->isEmpty()) {
            return collect();
        }

        return $this->consulta()
            ->whereHas('employee', fn ($q) => $q->whereIn('department_id', $departamentos))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (VacationRequest $solicitud) => $this->pasoRevisable($jefe, $solicitud) !== null)
            ->values();
    }

    private function revisable(Employee $jefe, int $id): VacationRequest
    {
        $solicitud = $this->consulta()->whereKey($id)->first();

        if (! $solicitud || $this->pasoRevisable($jefe, $solicitud) === null) {
            throw new VacacionesException('Solicitud no encontrada.', 404);
        }

        return $solicitud;
    }

    private function consulta()
    {
        return VacationRequest::where('status', 'pending')
            ->whereNotNull('current_step')
            ->with([
                'employee.position:id,name',
                'employee.department:id,name,manager_id',
                'approvals.decider:id,name',
                'approvals.decidedByEmployee:id,first_name,last_name,second_last_name',
            ]);
    }

    private function pasoRevisable(Employee $jefe, VacationRequest $solicitud): ?VacationRequestApproval
    {
        $paso = $solicitud->approvals->first(
            fn (VacationRequestApproval $p) => $p->step_order === $solicitud->current_step && $p->decision === VacationRequestApproval::WAITING
        );

        return $paso && ($this->aprobador->puedeDecidirComoEmpleado($jefe, $solicitud, $paso, 'aprobar')
            || $this->aprobador->puedeDecidirComoEmpleado($jefe, $solicitud, $paso, 'rechazar')) ? $paso : null;
    }

    private function jefe(Request $request): Employee
    {
        return $request->attributes->get('portal_employee');
    }
}
