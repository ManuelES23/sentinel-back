<?php

namespace App\Services\ActivosFijos;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserSubmodulePermission;
use Illuminate\Http\Request;

/**
 * Permisos de Activos Fijos (user_submodule_permissions) del submódulo
 * administration/activos-fijos/{activos|tipos-activo|asignaciones} de la empresa actual.
 * El catálogo de tipos es central: solo se escribe desde Grupo Espléndido.
 */
class PermisosActivos
{
    private const PERMISOS = ['view', 'create', 'edit', 'delete'];

    public function __construct(private AlcanceActivos $alcance)
    {
    }

    public function puede(User $user, Enterprise $empresa, string $submodulo, string $permiso): bool
    {
        if (EnsureUserIsAdmin::esAdmin($user)) {
            return true;
        }

        return UserSubmodulePermission::query()
            ->where('user_id', $user->id)
            ->where('is_granted', true)
            ->whereHas('permissionType', fn ($q) => $q->where('slug', $permiso))
            ->whereHas('submodule', fn ($q) => $q->where('slug', $submodulo)
                ->whereHas('module', fn ($m) => $m->where('slug', 'activos-fijos')
                    ->whereHas('application', fn ($a) => $a->where('slug', 'administration')
                        ->where('enterprise_id', $empresa->id))))
            ->exists();
    }

    public function autorizar(Request $request, string $submodulo, string $permiso): void
    {
        $empresa = $this->alcance->empresaActual($request);

        abort_unless($this->puede($request->user(), $empresa, $submodulo, $permiso), 403,
            'No tienes permiso para realizar esta acción en Activos Fijos.');
    }

    /** Ver tipos: lo necesita también quien solo captura activos (formulario). */
    public function autorizarVerTipos(Request $request): void
    {
        $empresa = $this->alcance->empresaActual($request);
        $user = $request->user();

        abort_unless(
            $this->puede($user, $empresa, 'activos', 'view') || $this->puede($user, $empresa, 'tipos-activo', 'view'),
            403,
            'No tienes permiso para ver los tipos de activo.',
        );
    }

    public function autorizarEscrituraTipos(Request $request, string $permiso): void
    {
        abort_unless($this->alcance->esCorporativo($request), 403,
            'El catálogo de tipos de activo solo se administra desde Grupo Espléndido.');

        $this->autorizar($request, 'tipos-activo', $permiso);
    }

    /** Para el front: qué botones mostrar y si la vista es corporativa. */
    public function resumen(Request $request): array
    {
        $empresa = $this->alcance->empresaActual($request);
        $user = $request->user();
        $corporativo = $this->alcance->esCorporativo($request);

        $activos = [];
        $tipos = [];
        $asignaciones = [];
        foreach (self::PERMISOS as $permiso) {
            $activos[$permiso] = $this->puede($user, $empresa, 'activos', $permiso);
            $tipos[$permiso] = $permiso === 'view'
                ? $this->puede($user, $empresa, 'tipos-activo', 'view')
                : $corporativo && $this->puede($user, $empresa, 'tipos-activo', $permiso);
            $asignaciones[$permiso] = $this->puede($user, $empresa, 'asignaciones', $permiso);
        }

        return [
            'activos' => $activos,
            'tipos_activo' => $tipos,
            'asignaciones' => $asignaciones,
            'corporativo' => $corporativo,
        ];
    }
}
