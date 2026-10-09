<?php

use App\Models\SubmodulePermissionType;
use App\Models\UserSubmodulePermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso `oportunidades.equipo` del CRM Comercial: quien lo tiene lee las
 * oportunidades de cualquier vendedor; sin él, solo las de su propio
 * CrmVendedor. Las empresas nuevas lo reciben de CrmPermisosSeeder.
 *
 * Para no dejar a la gerencia actual sin ver al equipo en el despliegue, se
 * concede a quien ya tiene `oportunidades.cerrar` (permiso que solo da el
 * perfil gerencia). Cualquier otro caso se ajusta aplicando el perfil o a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        $submoduloIds = DB::table('submodules')
            ->join('modules', 'modules.id', '=', 'submodules.module_id')
            ->join('applications', 'applications.id', '=', 'modules.application_id')
            ->where('applications.slug', 'crm')
            ->where('modules.slug', 'oportunidades')
            ->where('submodules.slug', 'oportunidades')
            ->pluck('submodules.id');

        foreach ($submoduloIds as $submoduloId) {
            $equipo = SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $submoduloId, 'slug' => 'equipo'],
                ['name' => 'Ver las oportunidades de otros vendedores', 'order' => 6, 'is_active' => true],
            );

            $usuarios = UserSubmodulePermission::where('submodule_id', $submoduloId)
                ->where('is_granted', true)
                ->whereHas('permissionType', fn ($q) => $q->where('slug', 'cerrar'))
                ->pluck('user_id');

            foreach ($usuarios as $userId) {
                UserSubmodulePermission::firstOrCreate(
                    ['user_id' => $userId, 'submodule_id' => $submoduloId, 'permission_type_id' => $equipo->id],
                    ['is_granted' => true],
                );
            }
        }
    }

    public function down(): void
    {
        $tipoIds = SubmodulePermissionType::where('slug', 'equipo')
            ->whereHas('submodule', fn ($q) => $q->where('slug', 'oportunidades')
                ->whereHas('module', fn ($m) => $m->where('slug', 'oportunidades')
                    ->whereHas('application', fn ($a) => $a->where('slug', 'crm'))))
            ->pluck('id');

        UserSubmodulePermission::whereIn('permission_type_id', $tipoIds)->delete();
        SubmodulePermissionType::whereIn('id', $tipoIds)->delete();
    }
};
