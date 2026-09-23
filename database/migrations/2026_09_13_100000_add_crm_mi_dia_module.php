<?php

use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 del CRM Comercial: módulo "Mi día" (pantalla de inicio del
 * vendedor) en la aplicación `crm` de cada empresa existente. Las empresas
 * nuevas lo reciben de CrmPermisosSeeder. No asigna permisos a usuarios:
 * eso se hace aplicando los perfiles del CRM.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'ver' => ['Ver Mi día', 1],
        'equipo' => ['Ver el día de otros vendedores', 2],
    ];

    public function up(): void
    {
        $appIds = DB::table('applications')->where('slug', 'crm')->pluck('id');

        foreach ($appIds as $appId) {
            $modulo = Module::firstOrCreate(
                ['application_id' => $appId, 'slug' => 'mi-dia'],
                ['name' => 'Mi día', 'icon' => 'Sun', 'order' => 0, 'is_active' => true],
            );

            $submodulo = Submodule::firstOrCreate(
                ['module_id' => $modulo->id, 'slug' => 'mi-dia'],
                ['name' => 'Mi día', 'icon' => 'Sun', 'order' => 1, 'is_active' => true],
            );

            foreach (self::PERMISOS as $slug => [$nombre, $orden]) {
                SubmodulePermissionType::firstOrCreate(
                    ['submodule_id' => $submodulo->id, 'slug' => $slug],
                    ['name' => $nombre, 'order' => $orden, 'is_active' => true],
                );
            }
        }
    }

    public function down(): void
    {
        $appIds = DB::table('applications')->where('slug', 'crm')->pluck('id');
        $moduloIds = Module::whereIn('application_id', $appIds)->where('slug', 'mi-dia')->pluck('id');
        $submoduloIds = Submodule::whereIn('module_id', $moduloIds)->pluck('id');

        UserSubmodulePermission::whereIn('submodule_id', $submoduloIds)->delete();
        UserSubmoduleAccess::whereIn('submodule_id', $submoduloIds)->delete();
        SubmodulePermissionType::whereIn('submodule_id', $submoduloIds)->delete();
        Submodule::whereIn('id', $submoduloIds)->delete();
        UserModuleAccess::whereIn('module_id', $moduloIds)->delete();
        Module::whereIn('id', $moduloIds)->delete();
    }
};
