<?php

use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * Permisos de RH → Gestión → Vacaciones. Hasta hoy la API no exigía ninguno,
 * así que quien ya entra al submódulo recibe los cuatro y nadie pierde la
 * función el día del despliegue. No toca filas existentes (ni las revocadas).
 */
return new class extends Migration
{
    private const PERMISOS = [
        'view' => ['Ver', 1],
        'create' => ['Crear', 2],
        'edit' => ['Editar', 3],
        'aprobar' => ['Aprobar como RH', 5],
    ];

    public function up(): void
    {
        foreach ($this->submodulos() as $submodulo) {
            $tipos = collect(self::PERMISOS)->map(fn ($datos, $slug) => SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $submodulo->id, 'slug' => $slug],
                ['name' => $datos[0], 'order' => $datos[1], 'is_active' => true],
            ));

            $usuarios = UserSubmoduleAccess::where('submodule_id', $submodulo->id)->where('is_active', true)->pluck('user_id');

            foreach ($usuarios as $usuarioId) {
                foreach ($tipos as $tipo) {
                    UserSubmodulePermission::firstOrCreate(
                        ['user_id' => $usuarioId, 'submodule_id' => $submodulo->id, 'permission_type_id' => $tipo->id],
                        ['is_granted' => true],
                    );
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
                ->whereHas('application', fn ($a) => $a->where('slug', 'rh')))
            ->get();
    }
};
