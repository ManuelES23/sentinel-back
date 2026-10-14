<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\VacationPortalSession;
use App\Services\RH\SesionPortalVacaciones;
use App\Services\RH\VerificadorPinEmpleado;
use App\Support\RH\PresentadorPortalVacaciones;
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
            ],
        ]);
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
