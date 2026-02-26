<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Enterprise;
use App\Models\Application;
use App\Models\Submodule;
use Illuminate\Support\Facades\DB;

class CompletePermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::where('email', 'admin@sentinel.com')->first();
        
        if (!$admin) {
            $this->command->error('Usuario admin no encontrado. Ejecuta AdminUserSeeder primero.');
            return;
        }

        $enterprise = Enterprise::where('slug', 'splendidfarms')->first();
        $application = Application::where('slug', 'administration')->first();

        if (!$enterprise || !$application) {
            $this->command->error('Estructura del sistema no encontrada. Ejecuta SystemStructureSeeder primero.');
            return;
        }

        // Asignar empresa al usuario
        $admin->enterprises()->syncWithoutDetaching([$enterprise->id]);

        // Asignar aplicación al usuario
        $admin->applications()->syncWithoutDetaching([$application->id]);

        // Obtener todos los submódulos
        $submodules = Submodule::whereHas('module', function($query) use ($application) {
            $query->where('application_id', $application->id);
        })->get();

        $this->command->info("Configurando permisos para {$submodules->count()} submódulos...");

        $standardPermissions = [
            ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver y listar registros', 'order' => 1],
            ['slug' => 'create', 'name' => 'Crear', 'description' => 'Crear nuevos registros', 'order' => 2],
            ['slug' => 'edit', 'name' => 'Editar', 'description' => 'Editar registros existentes', 'order' => 3],
            ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Eliminar registros', 'order' => 4],
        ];

        foreach ($submodules as $submodule) {
            // Dar acceso al submódulo
            DB::table('user_submodule_access')->insertOrIgnore([
                'user_id' => $admin->id,
                'submodule_id' => $submodule->id,
                'is_active' => true,
                'granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Crear tipos de permisos para este submódulo
            foreach ($standardPermissions as $perm) {
                $permissionType = DB::table('submodule_permission_types')
                    ->where('submodule_id', $submodule->id)
                    ->where('slug', $perm['slug'])
                    ->first();

                if (!$permissionType) {
                    $permissionType = DB::table('submodule_permission_types')->insertGetId([
                        'submodule_id' => $submodule->id,
                        'slug' => $perm['slug'],
                        'name' => $perm['name'],
                        'description' => $perm['description'],
                        'order' => $perm['order'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $permissionType = $permissionType->id;
                }

                // Asignar el permiso al admin
                DB::table('user_submodule_permissions')->insertOrIgnore([
                    'user_id' => $admin->id,
                    'submodule_id' => $submodule->id,
                    'permission_type_id' => $permissionType,
                    'is_granted' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->command->info("✓ Submódulo: {$submodule->name}");
        }

        $this->command->info('');
        $this->command->info('¡Permisos asignados exitosamente al usuario admin!');
        $this->command->info('El usuario tiene acceso total a todos los módulos.');
    }
}
