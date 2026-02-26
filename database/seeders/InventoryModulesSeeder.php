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

class InventoryModulesSeeder extends Seeder
{
    /**
     * Crea los módulos y submódulos para el sistema de inventario
     */
    public function run(): void
    {
        $app = Application::where('slug', 'administration')->first();
        
        if (!$app) {
            $this->command->error('No se encontró la aplicación Administration');
            return;
        }

        $this->command->info("Creando módulos de inventario para Application ID: {$app->id}");

        // ===== MÓDULO CATÁLOGOS =====
        $catalogos = Module::firstOrCreate(
            ['slug' => 'catalogos', 'application_id' => $app->id],
            [
                'name' => 'Catálogos',
                'icon' => 'FolderOpen',
                'order' => 3,
                'is_active' => true,
            ]
        );

        $categorias = Submodule::firstOrCreate(
            ['slug' => 'categorias', 'module_id' => $catalogos->id],
            ['name' => 'Categorías', 'icon' => 'Tags', 'order' => 1, 'is_active' => true]
        );

        $articulos = Submodule::firstOrCreate(
            ['slug' => 'articulos', 'module_id' => $catalogos->id],
            ['name' => 'Artículos', 'icon' => 'Package', 'order' => 2, 'is_active' => true]
        );

        $this->command->info('✓ Módulo Catálogos creado');

        // ===== MÓDULO OPERACIONES =====
        $operaciones = Module::firstOrCreate(
            ['slug' => 'operaciones', 'application_id' => $app->id],
            [
                'name' => 'Operaciones',
                'icon' => 'ArrowLeftRight',
                'order' => 4,
                'is_active' => true,
            ]
        );

        $entradas = Submodule::firstOrCreate(
            ['slug' => 'entradas', 'module_id' => $operaciones->id],
            ['name' => 'Entradas', 'icon' => 'ArrowDownLeft', 'order' => 1, 'is_active' => true]
        );

        $salidas = Submodule::firstOrCreate(
            ['slug' => 'salidas', 'module_id' => $operaciones->id],
            ['name' => 'Salidas', 'icon' => 'ArrowUpRight', 'order' => 2, 'is_active' => true]
        );

        $transferencias = Submodule::firstOrCreate(
            ['slug' => 'transferencias', 'module_id' => $operaciones->id],
            ['name' => 'Transferencias', 'icon' => 'ArrowLeftRight', 'order' => 3, 'is_active' => true]
        );

        $ajustes = Submodule::firstOrCreate(
            ['slug' => 'ajustes', 'module_id' => $operaciones->id],
            ['name' => 'Ajustes', 'icon' => 'SlidersHorizontal', 'order' => 4, 'is_active' => true]
        );

        $this->command->info('✓ Módulo Operaciones creado');

        // ===== MÓDULO REPORTES =====
        $reportes = Module::firstOrCreate(
            ['slug' => 'reportes', 'application_id' => $app->id],
            [
                'name' => 'Reportes',
                'icon' => 'BarChart3',
                'order' => 5,
                'is_active' => true,
            ]
        );

        $stock = Submodule::firstOrCreate(
            ['slug' => 'stock', 'module_id' => $reportes->id],
            ['name' => 'Existencias', 'icon' => 'Boxes', 'order' => 1, 'is_active' => true]
        );

        $movimientos = Submodule::firstOrCreate(
            ['slug' => 'movimientos', 'module_id' => $reportes->id],
            ['name' => 'Movimientos', 'icon' => 'History', 'order' => 2, 'is_active' => true]
        );

        $valorizado = Submodule::firstOrCreate(
            ['slug' => 'valorizado', 'module_id' => $reportes->id],
            ['name' => 'Valorizado', 'icon' => 'DollarSign', 'order' => 3, 'is_active' => true]
        );

        $this->command->info('✓ Módulo Reportes creado');

        // ===== ASIGNAR PERMISOS AL ADMIN =====
        $admin = User::where('role', 'admin')->first();
        
        if ($admin) {
            $modules = [$catalogos, $operaciones, $reportes];
            $submodules = [
                $categorias, $articulos,
                $entradas, $salidas, $transferencias, $ajustes,
                $stock, $movimientos, $valorizado
            ];

            // Asignar acceso a módulos
            foreach ($modules as $module) {
                UserModuleAccess::firstOrCreate(
                    ['user_id' => $admin->id, 'module_id' => $module->id],
                    ['is_active' => true, 'granted_at' => now()]
                );
            }

            // Asignar acceso y permisos a submódulos
            $permissionTypes = SubmodulePermissionType::all();
            
            foreach ($submodules as $submodule) {
                UserSubmoduleAccess::firstOrCreate(
                    ['user_id' => $admin->id, 'submodule_id' => $submodule->id],
                    ['is_active' => true, 'granted_at' => now()]
                );

                // Asignar todos los permisos
                foreach ($permissionTypes as $permType) {
                    UserSubmodulePermission::firstOrCreate([
                        'user_id' => $admin->id,
                        'submodule_id' => $submodule->id,
                        'permission_type_id' => $permType->id,
                    ]);
                }
            }

            $this->command->info('✓ Permisos asignados al usuario admin');
        }

        // ===== ASIGNAR PERMISOS AL USUARIO DEMO =====
        $demo = User::where('email', 'demo@sentinel.com')->first();
        
        if ($demo) {
            $modules = [$catalogos, $operaciones, $reportes];
            $submodules = [
                $categorias, $articulos,
                $entradas, $salidas, $transferencias, $ajustes,
                $stock, $movimientos, $valorizado
            ];

            foreach ($modules as $module) {
                UserModuleAccess::firstOrCreate(
                    ['user_id' => $demo->id, 'module_id' => $module->id],
                    ['is_active' => true, 'granted_at' => now()]
                );
            }

            $permissionTypes = SubmodulePermissionType::all();
            
            foreach ($submodules as $submodule) {
                UserSubmoduleAccess::firstOrCreate(
                    ['user_id' => $demo->id, 'submodule_id' => $submodule->id],
                    ['is_active' => true, 'granted_at' => now()]
                );

                foreach ($permissionTypes as $permType) {
                    UserSubmodulePermission::firstOrCreate([
                        'user_id' => $demo->id,
                        'submodule_id' => $submodule->id,
                        'permission_type_id' => $permType->id,
                    ]);
                }
            }

            $this->command->info('✓ Permisos asignados al usuario demo');
        }

        $this->command->info('');
        $this->command->info('¡Módulos de inventario creados exitosamente!');
    }
}
