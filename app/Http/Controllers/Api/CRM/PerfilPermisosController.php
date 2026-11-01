<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\VendedorUpdated;
use App\Exceptions\CRM\RolesEquipoException;
use App\Models\Application;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Services\CRM\CambioRolesCrm;
use App\Services\CRM\CrmPerfilPermisosService;
use App\Services\CRM\DeduccionRolesCrm;
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

    public function __construct(
        private readonly CrmPerfilPermisosService $perfiles,
        private readonly DeduccionRolesCrm $deduccion,
        private readonly CambioRolesCrm $cambio,
    ) {}

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

    /**
     * GET /crm/perfiles/resumen
     * Por usuario, sus roles del Equipo comercial en cada empresa con CRM
     * donde tiene algún rol o permisos ajustados a mano (columna CRM del
     * panel). La deducción es una por empresa, nunca una por persona.
     */
    public function resumen(Request $request): JsonResponse
    {
        $this->exigirAdministrador($request);

        $empresas = Enterprise::whereIn(
            'id',
            Application::where('slug', 'crm')->select('enterprise_id'),
        )->orderBy('name')->get(['id', 'name']);

        $porUsuario = [];
        foreach ($empresas as $empresa) {
            foreach ($this->deduccion->deEmpresa($empresa) as $persona) {
                if ($persona['user_id'] === null) {
                    continue;
                }
                $roles = $persona['roles'];
                if (! in_array(true, $roles, true) && ! $persona['ajustado']) {
                    continue;
                }

                $porUsuario[$persona['user_id']][] = [
                    'enterprise_id' => $empresa->id,
                    'nombre' => $empresa->name,
                    'vende' => $roles['vende'],
                    'gerencia' => $roles['gerencia'],
                    'catalogos' => $roles['catalogos'],
                    'ajustado' => $persona['ajustado'],
                ];
            }
        }

        ksort($porUsuario);
        $filas = [];
        foreach ($porUsuario as $userId => $filasEmpresa) {
            $filas[] = ['user_id' => $userId, 'empresas' => $filasEmpresa];
        }

        return $this->jsonSuccess($filas);
    }

    /** GET /crm/perfiles/usuarios/{user}/roles?enterprise_id= */
    public function roles(Request $request, User $user): JsonResponse
    {
        $this->exigirAdministrador($request);

        $validated = $request->validate([
            'enterprise_id' => 'required|integer|exists:enterprises,id',
        ]);

        try {
            return $this->jsonSuccess(
                $this->deduccion->dePersona($user, Enterprise::findOrFail($validated['enterprise_id']))
            );
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }

    /**
     * PUT /crm/perfiles/usuarios/{user}/roles
     * Cambia los roles de UNA persona en una empresa (misma lógica que
     * PUT crm/equipo) y devuelve su deducción nueva. Emite VendedorUpdated si
     * la ficha cambió, solo después de confirmar.
     */
    public function guardarRoles(Request $request, User $user): JsonResponse
    {
        $this->exigirAdministrador($request);

        $validated = $request->validate([
            'enterprise_id' => 'required|integer|exists:enterprises,id',
            'antes' => 'required|array',
            'antes.vende' => 'required|boolean',
            'antes.gerencia' => 'required|boolean',
            'antes.catalogos' => 'required|boolean',
            'vende' => 'required|boolean',
            'gerencia' => 'required|boolean',
            'catalogos' => 'required|boolean',
        ]);

        $empresa = Enterprise::findOrFail($validated['enterprise_id']);

        try {
            $fichas = $this->cambio->guardar($empresa, $request->user(), [[
                'user_id' => $user->id,
                'antes' => $validated['antes'],
                'vende' => $validated['vende'],
                'gerencia' => $validated['gerencia'],
                'catalogos' => $validated['catalogos'],
            ]]);

            foreach ($fichas as $item) {
                $this->difundir(new VendedorUpdated($item['accion'], $item['vendedor']->toArray()));
            }

            return $this->jsonSuccess($this->deduccion->dePersona($user, $empresa), 'Roles guardados correctamente');
        } catch (RolesEquipoException $e) {
            return $this->jsonError($e->getMessage(), $e->status());
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
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
