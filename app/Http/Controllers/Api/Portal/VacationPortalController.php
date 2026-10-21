<?php

namespace App\Http\Controllers\Api\Portal;

use App\Exceptions\RH\VacacionesException;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\VacationPortalSession;
use App\Models\VacationRequest;
use App\Services\RH\SesionPortalVacaciones;
use App\Services\RH\VacationRequestService;
use App\Services\RH\VerificadorPinEmpleado;
use App\Support\RH\PresentadorPortalVacaciones;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API del portal de vacaciones (spec §6.1). El empleado sale siempre de la
 * sesión (AuthenticatePortalSession), nunca de un parámetro; las reglas son
 * las de VacationRequestService y lo que sale pasa por PresentadorPortalVacaciones.
 */
class VacationPortalController extends Controller
{
    public function __construct(private SesionPortalVacaciones $sesiones) {}

    public function login(Request $request, VerificadorPinEmpleado $verificador): JsonResponse
    {
        $datos = $request->validate([
            'employee_number' => 'required|string|max:30',
            'pin' => 'required|string|max:20',
        ]);

        $empleado = $verificador->verificar($datos['employee_number'], $datos['pin']);
        if (! $empleado) {
            return response()->json(['success' => false, 'message' => VerificadorPinEmpleado::MENSAJE_ERROR], 422);
        }

        ['token' => $token, 'sesion' => $sesion] = $this->sesiones->iniciar($empleado, $request);

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'expires_at' => $sesion->expires_at->toIso8601String(),
                'empleado' => PresentadorPortalVacaciones::empleado($empleado),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->sesiones->revocar($this->sesion($request));

        return response()->json(['success' => true, 'message' => 'Sesión cerrada.']);
    }

    public function yo(Request $request): JsonResponse
    {
        $empleado = $this->empleado($request);

        return response()->json([
            'success' => true,
            'data' => [
                'empleado' => PresentadorPortalVacaciones::empleado($empleado),
                'saldo' => PresentadorPortalVacaciones::saldo($empleado),
                // Muestra la pestaña «Por revisar»: el empleado es jefe de algún departamento.
                'es_jefe' => Department::where('manager_id', $empleado->id)->exists(),
            ],
        ]);
    }

    public function calcular(Request $request, VacationRequestService $vacaciones): JsonResponse
    {
        [$inicio, $fin] = $this->fechas($request);

        return response()->json([
            'success' => true,
            'data' => PresentadorPortalVacaciones::calculo($vacaciones->calcular($this->empleado($request), $inicio, $fin)),
        ]);
    }

    /** Periodos que RH capturó del historial (lo que llevaba en Excel); cuentan como días tomados. */
    public function historial(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => PresentadorPortalVacaciones::historial($this->empleado($request))]);
    }

    public function solicitudes(Request $request): JsonResponse
    {
        $solicitudes = VacationRequest::where('employee_id', $this->empleado($request)->id)
            ->with('approvals')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $solicitudes->map(fn (VacationRequest $solicitud) => PresentadorPortalVacaciones::resumen($solicitud))->values(),
        ]);
    }

    public function detalle(Request $request, int $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => PresentadorPortalVacaciones::detalle($this->propia($request, $id))]);
    }

    public function crear(Request $request, VacationRequestService $vacaciones): JsonResponse
    {
        [$inicio, $fin] = $this->fechas($request);
        $motivo = $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null;

        $solicitud = $vacaciones->crear($this->empleado($request), $inicio, $fin, $motivo, VacationRequest::SOURCE_PORTAL);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud enviada.',
            'data' => PresentadorPortalVacaciones::detalle($this->propia($request, $solicitud->id)),
        ], 201);
    }

    public function cancelar(Request $request, int $id, VacationRequestService $vacaciones): JsonResponse
    {
        $vacaciones->cancelar($this->propia($request, $id), VacationRequestService::CANCELA_EMPLEADO);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud cancelada.',
            'data' => PresentadorPortalVacaciones::detalle($this->propia($request, $id)),
        ]);
    }

    public function formato(Request $request, int $id): JsonResponse
    {
        $solicitud = $this->propia($request, $id);
        if ($solicitud->status !== 'approved') {
            throw new VacacionesException('El formato solo está disponible para solicitudes aprobadas.');
        }

        return response()->json(['success' => true, 'data' => PresentadorPortalVacaciones::formato($solicitud)]);
    }

    /** Solicitud del empleado de la sesión; una ajena o inexistente da el mismo 404 (spec §3.5). */
    private function propia(Request $request, int $id): VacationRequest
    {
        $solicitud = VacationRequest::whereKey($id)
            ->where('employee_id', $this->empleado($request)->id)
            ->with(['approvals.decider:id,name', 'approvals.decidedByEmployee:id,first_name,last_name,second_last_name', 'approver:id,name', 'employee'])
            ->first();

        if (! $solicitud) {
            throw new VacacionesException('Solicitud no encontrada.', 404);
        }

        return $solicitud;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function fechas(Request $request): array
    {
        $datos = $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
        ]);

        return [
            Carbon::createFromFormat('Y-m-d', $datos['start_date'])->startOfDay(),
            Carbon::createFromFormat('Y-m-d', $datos['end_date'])->startOfDay(),
        ];
    }

    private function empleado(Request $request): Employee
    {
        return $request->attributes->get('portal_employee');
    }

    private function sesion(Request $request): VacationPortalSession
    {
        return $request->attributes->get('portal_session');
    }
}
