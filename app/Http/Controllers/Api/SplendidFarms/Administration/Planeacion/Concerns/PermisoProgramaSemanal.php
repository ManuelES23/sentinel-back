<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns;

use App\Models\Enterprise;
use App\Models\UserSubmodulePermission;
use App\Support\PermisoSubmodulo;
use Illuminate\Support\Facades\Auth;

/**
 * Permisos del submódulo Splendid Farms → Administración → Planeación →
 * Programa semanal. Cada endpoint exige el suyo (view, capturar-corte,
 * capturar-ventas, capturar-logistica, acuerdos, configurar).
 */
trait PermisoProgramaSemanal
{
    private ?int $empresaSfId = null;

    /** Slug del permiso => llave en el payload. */
    private const PERMISOS_PROGRAMA = [
        'view' => 'view',
        'capturar-corte' => 'capturar_corte',
        'capturar-ventas' => 'capturar_ventas',
        'capturar-logistica' => 'capturar_logistica',
        'acuerdos' => 'acuerdos',
        'configurar' => 'configurar',
    ];

    private const MENSAJE_SIN_PERMISO = 'No tienes permiso para esta acción del Programa semanal.';

    protected function empresaSf(): int
    {
        if ($this->empresaSfId === null) {
            $id = Enterprise::where('slug', 'splendidfarms')->value('id');
            abort_unless($id, 403, self::MENSAJE_SIN_PERMISO);
            $this->empresaSfId = (int) $id;
        }

        return $this->empresaSfId;
    }

    protected function exigirPermiso(string $permiso): void
    {
        abort_unless(
            PermisoSubmodulo::tiene(Auth::id(), $this->empresaSf(), 'administration', 'planeacion', 'programa-semanal', $permiso),
            403,
            self::MENSAJE_SIN_PERMISO,
        );
    }

    /**
     * Los seis permisos del usuario en una sola consulta, con las mismas
     * reglas que PermisoSubmodulo::tiene (concedido y del mismo submódulo).
     *
     * @return array<string, bool>
     */
    protected function permisosUsuario(): array
    {
        $salida = array_fill_keys(array_values(self::PERMISOS_PROGRAMA), false);

        $submodulo = PermisoSubmodulo::submodulo($this->empresaSf(), 'administration', 'planeacion', 'programa-semanal');
        if (! $submodulo || ! Auth::id()) {
            return $salida;
        }

        $slugs = UserSubmodulePermission::where('submodule_id', $submodulo->id)
            ->where('user_id', Auth::id())
            ->where('is_granted', true)
            ->whereHas('permissionType', fn ($q) => $q->where('submodule_id', $submodulo->id))
            ->with('permissionType:id,slug')
            ->get()
            ->pluck('permissionType.slug');

        foreach ($slugs as $slug) {
            if (isset(self::PERMISOS_PROGRAMA[$slug])) {
                $salida[self::PERMISOS_PROGRAMA[$slug]] = true;
            }
        }

        return $salida;
    }
}
