<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Enterprise;
use App\Models\Application;
use App\Models\Module;
use App\Models\Submodule;
use Illuminate\Support\Facades\DB;

class AssignPermissionsSeeder extends Seeder
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

        // Obtener todos los tipos de permisos
        $permissionTypes = DB::table('submodule_permission_types')->get();

        if ($permissionTypes->isEmpty()) {
            $this->command->error('No hay tipos de permisos. Ejecuta PermissionTypesSeeder primero.');
            return;
        }

        // Asignar todos los permisos a cada submódulo
        foreach ($submodules as $submodule) {
            foreach ($permissionTypes as $permType) {
                DB::table('user_submodule_permissions')->insertOrIgnore([
                    'user_id' => $admin->id,
                    'submodule_id' => $submodule->id,
                    'permission_type_id' => $permType->id,
                    'is_granted' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->command->info('Permisos asignados exitosamente al usuario admin!');
        $this->command->info('El usuario tiene acceso total a todos los módulos.');
    }
}
