<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $submoduleId = DB::table('submodules')
            ->where('slug', 'salida-rezaga')
            ->value('id');

        if (!$submoduleId) {
            return;
        }

        $permissions = [
            [
                'slug' => 'ver_ticket_salida_rezaga',
                'name' => 'Ver ticket de revisión',
                'description' => 'Permite visualizar el ticket de transferencia cargado en la revisión de salida de rezaga',
            ],
            [
                'slug' => 'ver_observaciones_salida_rezaga',
                'name' => 'Ver observaciones de revisión',
                'description' => 'Permite visualizar las observaciones capturadas durante la revisión de salida de rezaga',
            ],
        ];

        $maxOrder = DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduleId)
            ->max('order') ?? 0;

        $permissionTypeIds = [];

        foreach ($permissions as $index => $permission) {
            $permissionTypeId = DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduleId)
                ->where('slug', $permission['slug'])
                ->value('id');

            if (!$permissionTypeId) {
                DB::table('submodule_permission_types')->insert([
                    'submodule_id' => $submoduleId,
                    'slug' => $permission['slug'],
                    'name' => $permission['name'],
                    'description' => $permission['description'],
                    'order' => $maxOrder + $index + 1,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $permissionTypeId = DB::table('submodule_permission_types')
                    ->where('submodule_id', $submoduleId)
                    ->where('slug', $permission['slug'])
                    ->value('id');
            }

            if ($permissionTypeId) {
                $permissionTypeIds[] = $permissionTypeId;
            }
        }

        if (empty($permissionTypeIds)) {
            return;
        }

        $validarPermissionTypeId = DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduleId)
            ->where('slug', 'validar_salida_rezaga')
            ->value('id');

        if (!$validarPermissionTypeId) {
            return;
        }

        $userIds = DB::table('user_submodule_permissions')
            ->where('submodule_id', $submoduleId)
            ->where('permission_type_id', $validarPermissionTypeId)
            ->where('is_granted', true)
            ->pluck('user_id');

        foreach ($userIds as $userId) {
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
        $submoduleId = DB::table('submodules')
            ->where('slug', 'salida-rezaga')
            ->value('id');

        if (!$submoduleId) {
            return;
        }

        $permissionTypeIds = DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduleId)
            ->whereIn('slug', [
                'ver_ticket_salida_rezaga',
                'ver_observaciones_salida_rezaga',
            ])
            ->pluck('id');

        if ($permissionTypeIds->isEmpty()) {
            return;
        }

        DB::table('user_submodule_permissions')
            ->where('submodule_id', $submoduleId)
            ->whereIn('permission_type_id', $permissionTypeIds)
            ->delete();

        DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduleId)
            ->whereIn('id', $permissionTypeIds)
            ->delete();
    }
};
