<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Administración → Reportes → «Empaque» y «Productores» solo los creaba
 * MasterStructureSeeder, sin tipos de permiso: en producción no existían o no
 * se podían conceder desde el panel. Esta migración los asegura (submódulo +
 * view/create/edit/delete) y da acceso a admin@ y demo@, como las de
 * «Embarques» y «Producción». Es idempotente.
 */
return new class extends Migration
{
    private const SUBMODULOS = [
        [
            'slug' => 'productores',
            'name' => 'Productores',
            'description' => 'Reporte administrativo de productores',
            'icon' => 'Users',
            'order' => 2,
        ],
        [
            'slug' => 'empaque',
            'name' => 'Empaque',
            'description' => 'Reportes de empaque: recepción y recepciones vs. embarques',
            'icon' => 'ClipboardList',
            'order' => 3,
        ],
    ];

    private const TIPOS_PERMISO = [
        ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver y listar registros', 'order' => 1],
        ['slug' => 'create', 'name' => 'Crear', 'description' => 'Crear nuevos registros', 'order' => 2],
        ['slug' => 'edit', 'name' => 'Editar', 'description' => 'Editar registros existentes', 'order' => 3],
        ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Eliminar registros', 'order' => 4],
    ];

    public function up(): void
    {
        $moduleId = $this->moduloReportes();
        if (! $moduleId) {
            return;
        }

        $userIds = DB::table('users')
            ->whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])
            ->pluck('id');

        foreach (self::SUBMODULOS as $submodulo) {
            $submoduleId = DB::table('submodules')
                ->where('module_id', $moduleId)
                ->where('slug', $submodulo['slug'])
                ->value('id');

            if (! $submoduleId) {
                $submoduleId = DB::table('submodules')->insertGetId([
                    'module_id' => $moduleId,
                    'slug' => $submodulo['slug'],
                    'name' => $submodulo['name'],
                    'description' => $submodulo['description'],
                    'icon' => $submodulo['icon'],
                    'path' => null,
                    'order' => $submodulo['order'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $permissionTypeIds = [];
            foreach (self::TIPOS_PERMISO as $tipo) {
                $permissionTypeId = DB::table('submodule_permission_types')
                    ->where('submodule_id', $submoduleId)
                    ->where('slug', $tipo['slug'])
                    ->value('id');

                if (! $permissionTypeId) {
                    $permissionTypeId = DB::table('submodule_permission_types')->insertGetId([
                        'submodule_id' => $submoduleId,
                        'slug' => $tipo['slug'],
                        'name' => $tipo['name'],
                        'description' => $tipo['description'],
                        'order' => $tipo['order'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $permissionTypeIds[] = $permissionTypeId;
            }

            foreach ($userIds as $userId) {
                DB::table('user_module_access')->updateOrInsert(
                    ['user_id' => $userId, 'module_id' => $moduleId],
                    ['is_active' => true, 'granted_at' => now(), 'updated_at' => now(), 'created_at' => now()],
                );

                DB::table('user_submodule_access')->updateOrInsert(
                    ['user_id' => $userId, 'submodule_id' => $submoduleId],
                    ['is_active' => true, 'granted_at' => now(), 'updated_at' => now(), 'created_at' => now()],
                );

                foreach ($permissionTypeIds as $permissionTypeId) {
                    DB::table('user_submodule_permissions')->updateOrInsert(
                        [
                            'user_id' => $userId,
                            'submodule_id' => $submoduleId,
                            'permission_type_id' => $permissionTypeId,
                        ],
                        ['is_granted' => true, 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            }
        }
    }

    /**
     * Quita los tipos de permiso y sus concesiones. Los submódulos se dejan:
     * pudieron existir antes (los crea MasterStructureSeeder).
     */
    public function down(): void
    {
        $moduleId = $this->moduloReportes();
        if (! $moduleId) {
            return;
        }

        $submoduleIds = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->whereIn('slug', array_column(self::SUBMODULOS, 'slug'))
            ->pluck('id');

        DB::table('user_submodule_permissions')->whereIn('submodule_id', $submoduleIds)->delete();
        DB::table('submodule_permission_types')->whereIn('submodule_id', $submoduleIds)->delete();
    }

    private function moduloReportes(): ?int
    {
        $applicationId = DB::table('applications')
            ->join('enterprises', 'applications.enterprise_id', '=', 'enterprises.id')
            ->where('applications.slug', 'administration')
            ->where('enterprises.slug', 'splendidfarms')
            ->value('applications.id');

        if (! $applicationId) {
            return null;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'reportes')
            ->value('id');

        return $moduleId ? (int) $moduleId : null;
    }
};
