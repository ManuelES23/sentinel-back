<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissionTypes = [
            ['name' => 'Ver', 'slug' => 'view', 'description' => 'Permiso para ver/listar registros', 'order' => 1],
            ['name' => 'Crear', 'slug' => 'create', 'description' => 'Permiso para crear nuevos registros', 'order' => 2],
            ['name' => 'Editar', 'slug' => 'edit', 'description' => 'Permiso para modificar registros existentes', 'order' => 3],
            ['name' => 'Eliminar', 'slug' => 'delete', 'description' => 'Permiso para eliminar registros', 'order' => 4],
        ];

        foreach ($permissionTypes as $type) {
            if (!DB::table('submodule_permission_types')->where('slug', $type['slug'])->exists()) {
                DB::table('submodule_permission_types')->insert(array_merge($type, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        $this->command->info('Tipos de permisos creados exitosamente!');
    }
}
