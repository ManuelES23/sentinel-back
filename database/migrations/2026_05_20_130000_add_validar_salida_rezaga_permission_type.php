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

        $permissionTypeId = DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduleId)
            ->where('slug', 'validar_salida_rezaga')
            ->value('id');

        if (!$permissionTypeId) {
            DB::table('submodule_permission_types')->insert([
                'submodule_id' => $submoduleId,
                'slug' => 'validar_salida_rezaga',
                'name' => 'Validar salida de rezaga',
                'description' => 'Permite revisar y validar salidas de rezaga con ticket de transferencia',
                'order' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $permissionTypeId = DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduleId)
                ->where('slug', 'validar_salida_rezaga')
                ->value('id');
        }

        if (!$permissionTypeId) {
            return;
        }

        $editPermissionTypeId = DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduleId)
            ->where('slug', 'edit')
            ->value('id');

        if (!$editPermissionTypeId) {
            return;
        }

        $userIds = DB::table('user_submodule_permissions')
            ->where('submodule_id', $submoduleId)
            ->where('permission_type_id', $editPermissionTypeId)
            ->where('is_granted', true)
            ->pluck('user_id');

        foreach ($userIds as $userId) {
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

    public function down(): void
    {
        $submoduleId = DB::table('submodules')
            ->where('slug', 'salida-rezaga')
            ->value('id');

        if (!$submoduleId) {
            return;
        }

        $permissionTypeId = DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduleId)
            ->where('slug', 'validar_salida_rezaga')
            ->value('id');

        if (!$permissionTypeId) {
            return;
        }

        DB::table('user_submodule_permissions')
            ->where('submodule_id', $submoduleId)
            ->where('permission_type_id', $permissionTypeId)
            ->delete();

        DB::table('submodule_permission_types')
            ->where('id', $permissionTypeId)
            ->delete();
    }
};
