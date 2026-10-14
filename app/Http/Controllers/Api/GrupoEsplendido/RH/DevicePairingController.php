<?php
// sentinel-back/app/Http/Controllers/Api/GrupoEsplendido/RH/DevicePairingController.php
namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\DevicePairing;
use App\Services\RH\VerificadorPinEmpleado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicePairingController extends Controller
{
    /**
     * Emparejamiento personal: el propio empleado teclea número+PIN UNA sola
     * vez para autorizar su celular. De ahí en adelante el dispositivo usa el
     * token devuelto aquí — nunca vuelve a pedir número ni PIN.
     */
    public function pairSelf(Request $request, VerificadorPinEmpleado $verificador): JsonResponse
    {
        $validated = $request->validate([
            'employee_number' => 'required|string|max:20',
            'pin' => 'required|string|size:6',
        ]);

        $employee = $verificador->verificar($validated['employee_number'], $validated['pin']);

        if (! $employee) {
            return response()->json([
                'status' => 'error',
                'message' => VerificadorPinEmpleado::MENSAJE_ERROR,
            ], 422);
        }

        $rawToken = DevicePairing::generateToken();

        DevicePairing::create([
            'device_token_hash' => DevicePairing::hashToken($rawToken),
            'mode' => DevicePairing::MODE_SELF,
            'paired_by_employee_id' => $employee->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dispositivo emparejado correctamente.',
            'data' => ['device_token' => $rawToken],
        ], 201);
    }

    /**
     * Emparejamiento del kiosco fijo: requiere que un usuario de RH ya
     * autenticado lo autorice explícitamente — no cualquier empleado con
     * número+PIN, porque este dispositivo se queda instalado en un lugar
     * físico y escanea a cualquiera que pase, no solo a su dueño.
     */
    public function pairKiosk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => 'nullable|string|max:255',
        ]);

        $rawToken = DevicePairing::generateToken();

        DevicePairing::create([
            'device_token_hash' => DevicePairing::hashToken($rawToken),
            'mode' => DevicePairing::MODE_KIOSK,
            'paired_by_user_id' => $request->user()->id,
            'label' => $validated['label'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dispositivo fijo emparejado correctamente.',
            'data' => ['device_token' => $rawToken],
        ], 201);
    }
}
