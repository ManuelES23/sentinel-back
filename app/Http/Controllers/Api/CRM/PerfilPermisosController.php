<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Services\CRM\CrmPerfilPermisosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Perfiles de permisos del CRM (vendedor / gerencia / administración).
 * Solo administradores: aplicar un perfil reescribe los permisos de otra
 * persona, así que no basta con estar autenticado.
 */
class PerfilPermisosController extends CrmBaseController
{
    private const ROLES_ADMIN = ['admin', 'superadmin'];

    public function __construct(private readonly CrmPerfilPermisosService $perfiles) {}

    /** GET /crm/perfiles */
    public function index(Request $request): JsonResponse
    {
        $this->exigirAdministrador($request);

        return $this->jsonSuccess($this->perfiles->perfiles());
    }

    /** POST /crm/perfiles/usuarios/{user} */
    public function aplicar(Request $request, User $user): JsonResponse
    {
        $this->exigirAdministrador($request);

        $validated = $request->validate([
            'enterprise_id' => 'required|integer|exists:enterprises,id',
            'perfil' => ['required', 'string', Rule::in(CrmPerfilPermisosService::slugs())],
        ]);

        $tieneAccesoAEmpresa = UserEnterpriseAccess::where('user_id', $user->id)
            ->where('enterprise_id', $validated['enterprise_id'])
            ->where('is_active', true)
            ->exists();

        if (! $tieneAccesoAEmpresa) {
            return $this->jsonError(
                'El usuario no tiene acceso a esta empresa. Dale acceso a la empresa antes de aplicar un perfil del CRM.',
                422,
            );
        }

        try {
            $resumen = $this->perfiles->aplicar(
                $user,
                Enterprise::findOrFail($validated['enterprise_id']),
                $validated['perfil'],
            );
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        return $this->jsonSuccess($resumen, 'Perfil aplicado correctamente');
    }

    private function exigirAdministrador(Request $request): void
    {
        abort_unless(
            in_array($request->user()?->role, self::ROLES_ADMIN, true),
            403,
            'Solo un administrador puede gestionar perfiles de permisos.',
        );
    }
}
