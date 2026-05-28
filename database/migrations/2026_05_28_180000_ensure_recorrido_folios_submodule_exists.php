<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $enterpriseId = DB::table('enterprises')
            ->where('slug', 'splendidfarms')
            ->value('id');

        if (! $enterpriseId) {
            return;
        }

        $applicationId = DB::table('applications')
            ->where('enterprise_id', $enterpriseId)
            ->where('slug', 'operacion-agricola')
            ->value('id');

        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'empaque')
            ->value('id');

        if (! $moduleId) {
            return;
        }

        $submoduleId = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'recorrido-folios')
            ->value('id');

        if (! $submoduleId) {
            $nextOrder = ((int) DB::table('submodules')
                ->where('module_id', $moduleId)
                ->max('order')) + 1;

            $submoduleId = DB::table('submodules')->insertGetId([
                'module_id' => $moduleId,
                'slug' => 'recorrido-folios',
                'name' => 'Recorrido de Folios',
                'description' => 'Trazabilidad completa del folio desde recepcion hasta embarque y rezagas',
                'icon' => 'Route',
                'path' => null,
                'order' => $nextOrder,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('submodules')
                ->where('id', $submoduleId)
                ->update([
                    'name' => 'Recorrido de Folios',
                    'icon' => 'Route',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
        }

        $basePermissions = [
            ['slug' => 'view', 'name' => 'Ver', 'description' => 'Permite ver el submodulo'],
            ['slug' => 'create', 'name' => 'Crear', 'description' => 'Permite crear registros en el submodulo'],
            ['slug' => 'edit', 'name' => 'Editar', 'description' => 'Permite editar registros en el submodulo'],
            ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Permite eliminar registros en el submodulo'],
        ];

        foreach ($basePermissions as $permission) {
            $exists = DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduleId)
                ->where('slug', $permission['slug'])
                ->exists();

            if ($exists) {
                continue;
            }

            $nextOrder = ((int) (DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduleId)
                ->max('order') ?? 0)) + 1;

            DB::table('submodule_permission_types')->insert([
                'submodule_id' => $submoduleId,
                'slug' => $permission['slug'],
                'name' => $permission['name'],
                'description' => $permission['description'],
                'order' => $nextOrder,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $enterpriseId = DB::table('enterprises')
            ->where('slug', 'splendidfarms')
            ->value('id');

        if (! $enterpriseId) {
            return;
        }

        $applicationId = DB::table('applications')
            ->where('enterprise_id', $enterpriseId)
            ->where('slug', 'operacion-agricola')
            ->value('id');

        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'empaque')
            ->value('id');

        if (! $moduleId) {
            return;
        }

        $submoduleId = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'recorrido-folios')
            ->value('id');

        if (! $submoduleId) {
            return;
        }

        DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduleId)
            ->whereIn('slug', ['view', 'create', 'edit', 'delete'])
            ->delete();

        DB::table('submodules')
            ->where('id', $submoduleId)
            ->delete();
    }
};
