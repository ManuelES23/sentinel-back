<?php
// sentinel-back/app/Http/Controllers/Api/GrupoEsplendido/RH/DevicePairingController.php
namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\DevicePairing;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicePairingController extends Controller
{
    /**
     * Emparejamiento personal: el propio empleado teclea número+PIN UNA sola
     * vez para autorizar su celular. De ahí en adelante el dispositivo usa el
     * token devuelto aquí — nunca vuelve a pedir número ni PIN.
     */
    public function pairSelf(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_number' => 'required|string',
            'pin' => 'required|string|size:6',
        ]);

        $employee = Employee::where('employee_number', $validated['employee_number'])
            ->where('pin', $validated['pin'])
            ->where('status', Employee::STATUS_ACTIVE)
            ->first();

        if (! $employee) {
            return response()->json([
                'status' => 'error',
                'message' => 'Número de empleado o PIN incorrecto, o empleado inactivo.',
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
}
