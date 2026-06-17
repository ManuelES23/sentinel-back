<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $applicationId = DB::table('applications')
            ->join('enterprises', 'applications.enterprise_id', '=', 'enterprises.id')
            ->where('applications.slug', 'administration')
            ->where('enterprises.slug', 'splendidfarms')
            ->value('applications.id');

        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'reportes')
            ->value('id');

        if (! $moduleId) {
            $moduleId = DB::table('modules')->insertGetId([
                'application_id' => $applicationId,
                'slug' => 'reportes',
                'name' => 'Reportes',
                'description' => 'Reportes administrativos de embarques',
                'icon' => 'BarChart3',
                'path' => null,
                'order' => 6,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $submoduleId = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'embarques')
            ->value('id');

        if (! $submoduleId) {
            $submoduleId = DB::table('submodules')->insertGetId([
                'module_id' => $moduleId,
                'slug' => 'embarques',
                'name' => 'Embarques',
                'description' => 'Reporte administrativo de embarques y manifiestos',
                'icon' => 'Truck',
                'path' => null,
                'order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionTypes = [
            ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver y listar registros', 'order' => 1],
            ['slug' => 'create', 'name' => 'Crear', 'description' => 'Crear nuevos registros', 'order' => 2],
            ['slug' => 'edit', 'name' => 'Editar', 'description' => 'Editar registros existentes', 'order' => 3],
            ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Eliminar registros', 'order' => 4],
        ];

        $permissionTypeIds = [];

        foreach ($permissionTypes as $permissionType) {
            $permissionTypeId = DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduleId)
                ->where('slug', $permissionType['slug'])
                ->value('id');

            if (! $permissionTypeId) {
                $permissionTypeId = DB::table('submodule_permission_types')->insertGetId([
                    'submodule_id' => $submoduleId,
                    'slug' => $permissionType['slug'],
                    'name' => $permissionType['name'],
                    'description' => $permissionType['description'],
                    'order' => $permissionType['order'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $permissionTypeIds[] = $permissionTypeId;
        }

        $userIds = DB::table('users')
            ->whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])
            ->pluck('id');

        foreach ($userIds as $userId) {
            DB::table('user_module_access')->updateOrInsert(
                [
                    'user_id' => $userId,
                    'module_id' => $moduleId,
                ],
                [
                    'is_active' => true,
                    'granted_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            DB::table('user_submodule_access')->updateOrInsert(
                [
                    'user_id' => $userId,
                    'submodule_id' => $submoduleId,
                ],
                [
                    'is_active' => true,
                    'granted_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            foreach ($permissionTypeIds as $permissionTypeId) {
                DB::table('user_submodule_permissions')->updateOrInsert(
                    [
                        'user_id' => $userId,
                        'submodule_id' => $submoduleId,
                        'permission_type_id' => $permissionTypeId,
                    ],
                    [
                        'is_granted' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        $applicationId = DB::table('applications')
            ->join('enterprises', 'applications.enterprise_id', '=', 'enterprises.id')
            ->where('applications.slug', 'administration')
            ->where('enterprises.slug', 'splendidfarms')
            ->value('applications.id');

        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'reportes')
            ->value('id');

        if (! $moduleId) {
            return;
        }

        $submoduleId = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'embarques')
            ->value('id');

        if ($submoduleId) {
            DB::table('user_submodule_permissions')
                ->where('submodule_id', $submoduleId)
                ->delete();

            DB::table('user_submodule_access')
                ->where('submodule_id', $submoduleId)
                ->delete();

            DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduleId)
                ->delete();

            DB::table('submodules')
                ->where('id', $submoduleId)
                ->delete();
        }

        DB::table('user_module_access')
            ->where('module_id', $moduleId)
            ->delete();

        DB::table('modules')
            ->where('id', $moduleId)
            ->delete();
    }
};