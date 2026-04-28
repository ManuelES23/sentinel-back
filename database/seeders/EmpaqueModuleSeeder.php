<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Application;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\User;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use App\Models\SubmodulePermissionType;

/**
 * Seeder para el módulo Empaque dentro de Operación Agrícola
 * 
 * Ejecutar: php artisan db:seed --class=EmpaqueModuleSeeder
 */
class EmpaqueModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('📦 Creando módulo Empaque...');

        // Buscar la aplicación Operación Agrícola de Splendid Farms
        $app = Application::where('slug', 'operacion-agricola')
            ->whereHas('enterprise', fn($q) => $q->where('slug', 'splendidfarms'))
            ->first();

        if (!$app) {
            $this->command->error('No se encontró la aplicación Operación Agrícola de Splendid Farms');
            return;
        }

        // Crear módulo Empaque
        $empaque = Module::firstOrCreate(
            ['slug' => 'empaque', 'application_id' => $app->id],
            ['name' => 'Empaque', 'icon' => 'Package', 'order' => 3, 'is_active' => true]
        );
        $this->command->info("  ✓ Módulo: Empaque (ID: {$empaque->id})");

        // Crear submódulos
        $submodules = [
            ['slug' => 'dashboard',    'name' => 'Dashboard',        'icon' => 'LayoutDashboard','order' => 0],
            ['slug' => 'recepciones',  'name' => 'Recepciones',      'icon' => 'Download',       'order' => 1],
            ['slug' => 'lavado',       'name' => 'Lavado',           'icon' => 'Droplets',       'order' => 2],
            ['slug' => 'proceso',      'name' => 'Proceso',          'icon' => 'Layers',         'order' => 3],
            ['slug' => 'produccion',   'name' => 'Producción',       'icon' => 'Package',        'order' => 4],
            ['slug' => 'rezaga',       'name' => 'Rezaga',           'icon' => 'Trash2',         'order' => 5],
            ['slug' => 'embarques',    'name' => 'Embarques',        'icon' => 'Truck',          'order' => 6],
            ['slug' => 'venta-rezaga', 'name' => 'Venta de Rezaga',  'icon' => 'ShoppingCart',   'order' => 7],
            ['slug' => 'calidad',      'name' => 'Calidad',          'icon' => 'ClipboardCheck', 'order' => 8],
            ['slug' => 'reportes',     'name' => 'Reportes',         'icon' => 'FileText',       'order' => 9],
        ];

        $createdSubmodules = [];
        foreach ($submodules as $sub) {
            $submodule = Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $empaque->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
            $createdSubmodules[] = $submodule;
            $this->command->info("    → {$sub['name']} (ID: {$submodule->id})");
        }

        // Crear tipos de permisos estándar para cada submódulo
        $standardPermissions = [
            ['slug' => 'view',   'name' => 'Ver',      'description' => 'Ver y listar registros', 'order' => 1],
            ['slug' => 'create', 'name' => 'Crear',    'description' => 'Crear nuevos registros', 'order' => 2],
            ['slug' => 'edit',   'name' => 'Editar',   'description' => 'Editar registros existentes', 'order' => 3],
            ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Eliminar registros', 'order' => 4],
        ];

        foreach ($createdSubmodules as $submodule) {
            foreach ($standardPermissions as $perm) {
                SubmodulePermissionType::firstOrCreate(
                    ['submodule_id' => $submodule->id, 'slug' => $perm['slug']],
                    ['name' => $perm['name'], 'description' => $perm['description'], 'order' => $perm['order']]
                );
            }
        }
        $this->command->info('  ✓ Tipos de permisos CRUD creados');

        // Asignar permisos a todos los usuarios que ya tienen acceso a operacion-agricola
        $users = User::whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])->get();

        foreach ($users as $user) {
            // Acceso al módulo
            UserModuleAccess::firstOrCreate(
                ['user_id' => $user->id, 'module_id' => $empaque->id],
                ['is_active' => true, 'granted_at' => now()]
            );

            foreach ($createdSubmodules as $submodule) {
                // Acceso al submódulo
                UserSubmoduleAccess::firstOrCreate(
                    ['user_id' => $user->id, 'submodule_id' => $submodule->id],
                    ['is_active' => true, 'granted_at' => now()]
                );

                // Permisos CRUD
                $permTypes = SubmodulePermissionType::where('submodule_id', $submodule->id)->get();
                foreach ($permTypes as $permType) {
                    UserSubmodulePermission::firstOrCreate([
                        'user_id' => $user->id,
                        'submodule_id' => $submodule->id,
                        'permission_type_id' => $permType->id,
                    ]);
                }
            }
            $this->command->info("  ✓ Permisos asignados a: {$user->email}");
        }

        $this->command->info('');
        $this->command->info('✅ Módulo Empaque creado exitosamente con 10 submódulos');
    }
}
