<?php

namespace App\Services\Abastecimiento;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\User;
use App\Services\ActivosFijos\AlcanceActivos;
use App\Support\PermisoSubmodulo;
use Database\Seeders\AbastecimientoModuleSeeder;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Http\Request;

/**
 * Empresa y permisos de Administración → Abastecimiento. La empresa sale del
 * segmento de la URL (/api/{empresa}/administration/abastecimiento/...) y el
 * módulo debe estar sembrado en ella.
 */
#[Scoped]
class ContextoAbastecimiento
{
    public function __construct(private AlcanceActivos $alcance)
    {
    }

    /** La empresa de la URL; 404 si no tiene el módulo (403 si el usuario no tiene acceso a ella). */
    public function empresa(Request $request): Enterprise
    {
        $empresa = $this->alcance->empresaActual($request);

        $tieneModulo = Module::where('slug', 'abastecimiento')
            ->whereHas('application', fn ($a) => $a->where('slug', 'administration')->where('enterprise_id', $empresa->id))
            ->exists();

        if (! $tieneModulo) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Esta empresa no tiene el módulo de Abastecimiento.',
            ], 404));
        }

        return $empresa;
    }

    public function puede(User $usuario, Enterprise $empresa, string $submodulo, string $permiso): bool
    {
        if (EnsureUserIsAdmin::esAdmin($usuario)) {
            return true;
        }

        return PermisoSubmodulo::tiene($usuario->id, $empresa->id, 'administration', 'abastecimiento', $submodulo, $permiso);
    }

    /** Exige el permiso y devuelve la empresa de la URL. */
    public function autorizar(Request $request, string $submodulo, string $permiso): Enterprise
    {
        $empresa = $this->empresa($request);

        if (! $this->puede($request->user(), $empresa, $submodulo, $permiso)) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'No tienes permiso para esta acción de Abastecimiento.',
            ], 403));
        }

        return $empresa;
    }

    /**
     * Exige AL MENOS UNO de los permisos del submódulo (p. ej. las lecturas de apoyo del formulario de liquidación,
     * que usan tanto quien crea como quien edita un borrador) y devuelve la empresa de la URL.
     *
     * @param  array<int, string>  $permisos
     */
    public function autorizarAlguno(Request $request, string $submodulo, array $permisos): Enterprise
    {
        $empresa = $this->empresa($request);

        foreach ($permisos as $permiso) {
            if ($this->puede($request->user(), $empresa, $submodulo, $permiso)) {
                return $empresa;
            }
        }

        abort(response()->json([
            'status' => 'error',
            'message' => 'No tienes permiso para esta acción de Abastecimiento.',
        ], 403));
    }

    /** @return array<string, array<string, bool>> [submódulo => [permiso => bool]] para el front. */
    public function resumen(Request $request): array
    {
        $empresa = $this->empresa($request);
        $usuario = $request->user();

        $resumen = [];
        foreach (AbastecimientoModuleSeeder::SUBMODULOS as $submodulo => $def) {
            foreach ($def['permisos'] as $permiso) {
                $resumen[$submodulo][$permiso] = $this->puede($usuario, $empresa, $submodulo, $permiso);
            }
        }

        return $resumen;
    }
}
