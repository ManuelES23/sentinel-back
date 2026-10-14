<?php

use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use App\Services\RH\AprobadorVacaciones;
use Illuminate\Database\Migrations\Migration;

/**
 * Permisos de RH → Gestión → Vacaciones, solo de la empresa corporativa
 * (la única que usa este submódulo). Hasta hoy la API no exigía ninguno, así
 * que quien ya entra al submódulo (acceso activo) debe conservar la función:
 *
 * - view/create/edit: se FUERZAN a concedido (updateOrCreate). Sus filas eran
 *   valores por defecto del seeder (is_granted = 0) que esta API nunca hacía
 *   valer; conceder esos tres preserva el comportamiento real de hoy.
 * - aprobar: es un tipo nuevo; se crea concedido solo si no existe la fila
 *   (firstOrCreate). Una fila existente, incluso revocada, no se toca.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'view' => ['Ver', 1],
        'create' => ['Crear', 2],
        'edit' => ['Editar', 3],
        'aprobar' => ['Aprobar como RH', 5],
    ];

    /** Tipos cuyas filas existentes se fuerzan a concedido. */
    private const FORZADOS = ['view', 'create', 'edit'];

    public function up(): void
    {
        foreach ($this->submodulos() as $submodulo) {
            $tipos = collect(self::PERMISOS)->map(fn ($datos, $slug) => SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $submodulo->id, 'slug' => $slug],
                ['name' => $datos[0], 'order' => $datos[1], 'is_active' => true],
            ));

            $usuarios = UserSubmoduleAccess::where('submodule_id', $submodulo->id)->where('is_active', true)->pluck('user_id');

            foreach ($usuarios as $usuarioId) {
                foreach ($tipos as $slug => $tipo) {
                    $llave = ['user_id' => $usuarioId, 'submodule_id' => $submodulo->id, 'permission_type_id' => $tipo->id];

                    in_array($slug, self::FORZADOS, true)
                        ? UserSubmodulePermission::updateOrCreate($llave, ['is_granted' => true])
                        : UserSubmodulePermission::firstOrCreate($llave, ['is_granted' => true]);
                }
            }
        }
    }

    public function down(): void
    {
        $tipoIds = SubmodulePermissionType::where('slug', 'aprobar')
            ->whereIn('submodule_id', $this->submodulos()->pluck('id'))
            ->pluck('id');

        UserSubmodulePermission::whereIn('permission_type_id', $tipoIds)->delete();
        SubmodulePermissionType::whereIn('id', $tipoIds)->delete();
    }

    private function submodulos()
    {
        return Submodule::where('slug', 'vacaciones')
            ->whereHas('module', fn ($q) => $q->where('slug', 'gestion')
                ->whereHas('application', fn ($a) => $a->where('slug', 'rh')
                    ->whereHas('enterprise', fn ($e) => $e->where('slug', AprobadorVacaciones::EMPRESA_CORPORATIVA))))
            ->get();
    }
};
