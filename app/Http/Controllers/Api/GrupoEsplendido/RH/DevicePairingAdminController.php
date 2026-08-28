<?php
// sentinel-back/app/Http/Controllers/Api/GrupoEsplendido/RH/DevicePairingAdminController.php
namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\DevicePairing;
use App\Models\UserEnterpriseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicePairingAdminController extends Controller
{
    /**
     * Emparejamientos personales se filtran por las empresas del usuario de
     * RH (mismo criterio ya corregido en el sistema de revisión de checador
     * — nunca asumir una sola empresa dueña). Los kioscos NO se filtran: no
     * pertenecen a la empresa de un empleado, y ocultarle kioscos a otros
     * administradores de RH estorbaría la gestión colaborativa real.
     */
    public function index(Request $request): JsonResponse
    {
        $enterpriseIds = UserEnterpriseAccess::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->pluck('enterprise_id');

        $query = DevicePairing::query()
            ->with(['pairedByEmployee:id,employee_number,first_name,last_name,second_last_name', 'pairedByUser:id,name'])
            ->where(function ($q) use ($enterpriseIds) {
                $q->where('mode', DevicePairing::MODE_KIOSK)
                    ->orWhere(function ($q2) use ($enterpriseIds) {
                        $q2->where('mode', DevicePairing::MODE_SELF)
                            ->whereHas('pairedByEmployee', fn ($eq) => $eq->whereIn('enterprise_id', $enterpriseIds));
                    });
            })
            ->orderByDesc('created_at');

        return response()->json([
            'success' => true,
            'data' => $query->paginate(20),
        ]);
    }

    public function revoke(Request $request, DevicePairing $devicePairing): JsonResponse
    {
        $devicePairing->update(['revoked_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Dispositivo revocado. Ya no puede sincronizar ni descargar el paquete de plantillas.',
            'data' => $devicePairing->fresh(),
        ]);
    }
}
