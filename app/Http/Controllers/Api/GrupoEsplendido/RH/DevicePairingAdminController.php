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
     *
     * pairedByEmployee se resuelve con withTrashed(): Employee usa SoftDeletes,
     * y Employee::destroy() (a diferencia de terminate(), que no borra el
     * registro) lo excluiría en silencio del global scope — dejando el
     * emparejamiento invisible para siempre aunque el token del dispositivo
     * siga activo. Un emparejamiento self cuyo empleado fue eliminado por
     * completo (forceDelete, paired_by_employee_id -> null por nullOnDelete)
     * se trata como huérfano y se muestra a cualquier admin de RH con acceso
     * activo a alguna empresa, igual que un kiosco: no hay empresa a la cual
     * asociarlo, y dejarlo inaccesible para siempre sería peor.
     */
    public function index(Request $request): JsonResponse
    {
        $enterpriseIds = UserEnterpriseAccess::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->pluck('enterprise_id');

        $query = DevicePairing::query()
            ->with([
                'pairedByEmployee' => fn ($q) => $q->withTrashed()
                    ->select(['id', 'employee_number', 'first_name', 'last_name', 'second_last_name', 'deleted_at']),
                'pairedByUser:id,name',
            ])
            ->where(function ($q) use ($enterpriseIds) {
                $q->where('mode', DevicePairing::MODE_KIOSK)
                    ->orWhere(function ($q2) use ($enterpriseIds) {
                        $q2->where('mode', DevicePairing::MODE_SELF)
                            ->where(function ($q3) use ($enterpriseIds) {
                                $q3->whereHas('pairedByEmployee', fn ($eq) => $eq->withTrashed()->whereIn('enterprise_id', $enterpriseIds))
                                    ->orWhereDoesntHave('pairedByEmployee', fn ($eq) => $eq->withTrashed());
                            });
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
        // Si es un kiosco, cualquier admin de RH autenticado puede revocarlo.
        // Si es un emparejamiento personal (self), solo el admin de RH que tiene
        // acceso a la empresa del empleado puede revocarlo.
        if ($devicePairing->mode === DevicePairing::MODE_SELF) {
            // withTrashed(): ver nota en index() — si no, un empleado dado de
            // baja (soft-delete) resuelve enterprise_id como null -> (int) 0,
            // y authorizeEnterpriseAccess() falla cerrado con 403 incluso
            // para un admin con acceso real a la empresa del empleado.
            $devicePairing->load(['pairedByEmployee' => fn ($q) => $q->withTrashed()]);

            if ($devicePairing->pairedByEmployee === null) {
                // Empleado eliminado por completo (forceDelete): no hay
                // empresa a la cual asociar el emparejamiento. Mismo criterio
                // que un kiosco — cualquier admin de RH con acceso activo a
                // alguna empresa puede revocarlo.
                abort_unless(
                    UserEnterpriseAccess::where('user_id', $request->user()->id)
                        ->where('is_active', true)
                        ->exists(),
                    403,
                    'No tienes acceso a esta empresa'
                );
            } else {
                $this->authorizeEnterpriseAccess($request, (int) $devicePairing->pairedByEmployee->enterprise_id);
            }
        }

        $devicePairing->update(['revoked_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Dispositivo revocado. Ya no puede sincronizar ni descargar el paquete de plantillas.',
            'data' => $devicePairing->fresh(['pairedByEmployee', 'pairedByUser']),
        ]);
    }

    private function authorizeEnterpriseAccess(Request $request, int $enterpriseId): void
    {
        abort_unless(
            UserEnterpriseAccess::where('user_id', $request->user()->id)
                ->where('enterprise_id', $enterpriseId)
                ->where('is_active', true)
                ->exists(),
            403,
            'No tienes acceso a esta empresa'
        );
    }
}
