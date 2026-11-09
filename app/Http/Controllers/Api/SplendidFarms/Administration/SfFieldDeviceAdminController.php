<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\DevicePairing;
use App\Services\SplendidFarms\ChecadorCampo\AutorizacionChecadorCampo;
use App\Support\PermisoSubmodulo;
use App\Traits\AuthorizesEnterpriseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administración de los celulares emparejados al checador de campo SF
 * (modo sf_field): lista con su estado y revocación.
 */
class SfFieldDeviceAdminController extends Controller
{
    use AuthorizesEnterpriseAccess;

    private const ESTADOS = [
        'ok' => 'activo',
        'dispositivo_revocado' => 'revocado',
        'dispositivo_caducado' => 'caducado',
        'sin_permiso' => 'sin_permiso',
    ];

    public function __construct(private readonly AutorizacionChecadorCampo $autorizacion)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
        ]);
        $empresaId = (int) $validated['enterprise_id'];

        $this->autorizarAdministracion($request, $empresaId);

        $pagina = DevicePairing::where('mode', DevicePairing::MODE_SF_FIELD)
            ->where('enterprise_id', $empresaId)
            ->with('pairedByUser:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        $pagina->getCollection()->transform(fn (DevicePairing $p) => $this->presentar($p));

        return response()->json(['success' => true, 'data' => $pagina]);
    }

    public function revocar(Request $request, DevicePairing $devicePairing): JsonResponse
    {
        abort_unless(
            $devicePairing->mode === DevicePairing::MODE_SF_FIELD && $devicePairing->enterprise_id !== null,
            404,
            'Celular no encontrado'
        );

        $this->autorizarAdministracion($request, (int) $devicePairing->enterprise_id);

        if (! $devicePairing->isRevoked()) {
            $devicePairing->update(['revoked_at' => now()]);
        }

        $devicePairing->load('pairedByUser:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Celular revocado',
            'data' => $this->presentar($devicePairing),
        ]);
    }

    /** Acceso a la empresa + (superadmin o permiso «edit» del checador de campo). */
    private function autorizarAdministracion(Request $request, int $empresaId): void
    {
        $this->authorizeEnterpriseAccess($request, $empresaId);

        $user = $request->user();
        abort_unless(
            $user->isSuperadmin() || PermisoSubmodulo::tiene(
                $user->id,
                $empresaId,
                AutorizacionChecadorCampo::APLICACION,
                AutorizacionChecadorCampo::MODULO,
                AutorizacionChecadorCampo::SUBMODULO,
                'edit'
            ),
            403,
            'No tienes permiso para administrar los celulares de campo'
        );
    }

    /** Campos explícitos: nunca se expone device_token_hash. */
    private function presentar(DevicePairing $pairing): array
    {
        return [
            'id' => $pairing->id,
            'label' => $pairing->label,
            'paired_by_user' => $pairing->pairedByUser
                ? ['id' => $pairing->pairedByUser->id, 'name' => $pairing->pairedByUser->name]
                : null,
            'created_at' => $pairing->created_at,
            'last_used_at' => $pairing->last_used_at,
            'revoked_at' => $pairing->revoked_at,
            'estado' => self::ESTADOS[$this->autorizacion->estado($pairing)],
        ];
    }
}
