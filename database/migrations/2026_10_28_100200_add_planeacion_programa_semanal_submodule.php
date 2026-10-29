<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Operación Agrícola (Splendid Farms) → módulo «Planeación» → submódulo
 * «Programa semanal», con sus seis permisos propios, y acceso total para
 * admin@ y demo@. Es idempotente; si la aplicación no existe, termina sin error.
 */
return new class extends Migration
{
    private const PERMISOS = [
        ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver el programa semanal', 'order' => 1],
        ['slug' => 'capturar-corte', 'name' => 'Capturar corte', 'description' => 'Capturar el corte de campo y el rendimiento de la semana', 'order' => 2],
        ['slug' => 'capturar-ventas', 'name' => 'Capturar ventas', 'description' => 'Capturar las necesidades de los clientes', 'order' => 3],
        ['slug' => 'capturar-logistica', 'name' => 'Capturar logística', 'description' => 'Capturar los viajes y embarques de la semana', 'order' => 4],
        ['slug' => 'acuerdos', 'name' => 'Acuerdos de la junta', 'description' => 'Registrar la junta y activar o desactivar acuerdos', 'order' => 5],
        ['slug' => 'configurar', 'name' => 'Configurar', 'description' => 'Editar catálogos, grupos, recetas y rendimientos', 'order' => 6],
    ];

    public function up(): void
    {
        $applicationId = $this->aplicacion();
        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'planeacion')
            ->value('id');

        if (! $moduleId) {
            $moduleId = DB::table('modules')->insertGetId([
                'application_id' => $applicationId,
                'slug' => 'planeacion',
                'name' => 'Planeación',
                'description' => 'Planeación semanal de corte, empaque y ventas',
                'icon' => 'CalendarRange',
                'path' => null,
                'order' => 4,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $submoduleId = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'programa-semanal')
            ->value('id');

        if (! $submoduleId) {
            $submoduleId = DB::table('submodules')->insertGetId([
                'module_id' => $moduleId,
                'slug' => 'programa-semanal',
                'name' => 'Programa semanal',
                'description' => 'Programa semanal de corte, empaque, ventas y logística',
                'icon' => 'CalendarDays',
                'path' => null,
                'order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionTypeIds = [];
        foreach (self::PERMISOS as $permiso) {
            $permissionTypeId = DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduleId)
                ->where('slug', $permiso['slug'])
                ->value('id');

            if (! $permissionTypeId) {
                $permissionTypeId = DB::table('submodule_permission_types')->insertGetId([
                    'submodule_id' => $submoduleId,
                    'slug' => $permiso['slug'],
                    'name' => $permiso['name'],
                    'description' => $permiso['description'],
                    'order' => $permiso['order'],
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

    /**
     * Quita el submódulo con sus permisos y concesiones, y el módulo si queda vacío.
     */
    public function down(): void
    {
        $applicationId = $this->aplicacion();
        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'planeacion')
            ->value('id');

        if (! $moduleId) {
            return;
        }

        $submoduleIds = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'programa-semanal')
            ->pluck('id');

        DB::table('user_submodule_permissions')->whereIn('submodule_id', $submoduleIds)->delete();
        DB::table('user_submodule_access')->whereIn('submodule_id', $submoduleIds)->delete();
        DB::table('submodule_permission_types')->whereIn('submodule_id', $submoduleIds)->delete();
        DB::table('submodules')->whereIn('id', $submoduleIds)->delete();

        if (! DB::table('submodules')->where('module_id', $moduleId)->exists()) {
            DB::table('user_module_access')->where('module_id', $moduleId)->delete();
            DB::table('modules')->where('id', $moduleId)->delete();
        }
    }

    private function aplicacion(): ?int
    {
        $applicationId = DB::table('applications')
            ->join('enterprises', 'applications.enterprise_id', '=', 'enterprises.id')
            ->where('applications.slug', 'operacion-agricola')
            ->where('enterprises.slug', 'splendidfarms')
            ->value('applications.id');

        return $applicationId ? (int) $applicationId : null;
    }
};
