<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns;

use App\Models\Enterprise;
use App\Models\Submodule;
use App\Models\UserSubmodulePermission;
use App\Support\PermisoSubmodulo;
use Illuminate\Support\Facades\Auth;

/**
 * Permisos de los submódulos de Splendid Farms → Administración → Planeación
 * (Tablero semanal, Plan de corte, Necesidades de venta, Embarques, Resumen de
 * temporada y Configuración). Cada endpoint exige el par submódulo + permiso
 * que le toca.
 */
trait PermisoPlaneacion
{
    private ?int $empresaSfId = null;

    /**
     * Submódulo => [llave en el payload, [permiso => llave del permiso]].
     * El orden es el del contrato de `permisos`.
     */
    private const SUBMODULOS_PLANEACION = [
        'tablero-semanal' => ['tablero', ['view' => 'view', 'editar-notas' => 'editar_notas']],
        'plan-corte' => ['plan_corte', ['view' => 'view', 'capturar' => 'capturar']],
        'necesidades-venta' => ['necesidades', ['view' => 'view', 'capturar' => 'capturar']],
        'embarques' => ['embarques', ['view' => 'view', 'capturar' => 'capturar']],
        'resumen-temporada' => ['resumen', ['view' => 'view']],
        'configuracion' => ['configuracion', ['view' => 'view', 'editar' => 'editar']],
    ];

    private const MENSAJE_SIN_PERMISO = 'No tienes permiso para esta acción de Planeación.';

    protected function empresaSf(): int
    {
        if ($this->empresaSfId === null) {
            $id = Enterprise::where('slug', 'splendidfarms')->value('id');
            abort_unless($id, 403, self::MENSAJE_SIN_PERMISO);
            $this->empresaSfId = (int) $id;
        }

        return $this->empresaSfId;
    }

    /** 403 si el usuario no tiene `$permiso` en el submódulo `$submodulo` de Planeación. */
    protected function exigirPermiso(string $submodulo, string $permiso): void
    {
        abort_unless(
            PermisoSubmodulo::tiene(Auth::id(), $this->empresaSf(), 'administration', 'planeacion', $submodulo, $permiso),
            403,
            self::MENSAJE_SIN_PERMISO,
        );
    }

    /**
     * 403 si el usuario no tiene ninguno de los pares dados.
     *
     * @param  list<array{0: string, 1: string}>  $pares  p. ej. `[['tablero-semanal', 'view'], ['plan-corte', 'view']]`
     */
    protected function exigirAlguno(array $pares): void
    {
        $concedidos = $this->concedidosPlaneacion();

        foreach ($pares as [$submodulo, $permiso]) {
            if (in_array($permiso, $concedidos[$submodulo] ?? [], true)) {
                return;
            }
        }

        abort(403, self::MENSAJE_SIN_PERMISO);
    }

    /**
     * Permisos del usuario en todos los submódulos de Planeación, con la forma
     * `permisos` del payload: `{ tablero: { view, editar_notas }, plan_corte: … }`.
     *
     * @return array<string, array<string, bool>>
     */
    protected function permisosUsuario(): array
    {
        $concedidos = $this->concedidosPlaneacion();

        $salida = [];
        foreach (self::SUBMODULOS_PLANEACION as $submodulo => [$llave, $permisos]) {
            foreach ($permisos as $permiso => $llavePermiso) {
                $salida[$llave][$llavePermiso] = in_array($permiso, $concedidos[$submodulo] ?? [], true);
            }
        }

        return $salida;
    }

    /**
     * Slugs de permiso concedidos al usuario por submódulo de Planeación, en
     * dos consultas y con las mismas reglas que PermisoSubmodulo::tiene
     * (concedido y del mismo submódulo).
     *
     * @return array<string, list<string>> submódulo => permisos
     */
    private function concedidosPlaneacion(): array
    {
        if (! Auth::id()) {
            return [];
        }

        $empresaId = $this->empresaSf();
        $submodulos = Submodule::whereIn('slug', array_keys(self::SUBMODULOS_PLANEACION))
            ->whereHas('module', fn ($q) => $q->where('slug', 'planeacion')
                ->whereHas('application', fn ($a) => $a->where('enterprise_id', $empresaId)->where('slug', 'administration')))
            ->pluck('slug', 'id');
        if ($submodulos->isEmpty()) {
            return [];
        }

        $concedidos = [];
        UserSubmodulePermission::whereIn('submodule_id', $submodulos->keys())
            ->where('user_id', Auth::id())
            ->where('is_granted', true)
            ->with('permissionType:id,slug,submodule_id')
            ->get()
            ->each(function (UserSubmodulePermission $p) use ($submodulos, &$concedidos) {
                $tipo = $p->permissionType;
                if ($tipo && (int) $tipo->submodule_id === (int) $p->submodule_id) {
                    $concedidos[$submodulos[$p->submodule_id]][] = $tipo->slug;
                }
            });

        return $concedidos;
    }
}
