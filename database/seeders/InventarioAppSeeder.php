<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Enterprise;
use App\Models\Application;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use App\Models\SubmodulePermissionType;

class InventarioAppSeeder extends Seeder
{
    /**
     * Crea la aplicación Inventario con sus módulos y submódulos
     */
    public function run(): void
    {
        $enterprise = Enterprise::where('slug', 'splendidfarms')->first();
        
        if (!$enterprise) {
            $this->command->error('No se encontró la empresa Splendid Farms');
            return;
        }

        $this->command->info("Creando aplicación Inventario para: {$enterprise->name}");

        // ===== CREAR APLICACIÓN INVENTARIO =====
        $inventario = Application::firstOrCreate(
            ['slug' => 'inventario', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Inventario',
                'description' => 'Sistema de gestión de inventarios y almacenes',
                'icon' => 'Package',
                'path' => '/splendidfarms/inventario',
                'is_active' => true,
            ]
        );

        $this->command->info("✓ Aplicación Inventario creada (ID: {$inventario->id})");

        // ===== MÓDULO CATÁLOGOS =====
        $catalogos = Module::firstOrCreate(
            ['slug' => 'catalogos', 'application_id' => $inventario->id],
            [
                'name' => 'Catálogos',
                'icon' => 'FolderOpen',
                'order' => 1,
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

        $this->command->info('  ✓ Módulo Catálogos: Categorías, Artículos');

        // ===== MÓDULO OPERACIONES =====
        $operaciones = Module::firstOrCreate(
            ['slug' => 'operaciones', 'application_id' => $inventario->id],
            [
                'name' => 'Operaciones',
                'icon' => 'ArrowLeftRight',
                'order' => 2,
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

        $this->command->info('  ✓ Módulo Operaciones: Entradas, Salidas, Transferencias, Ajustes');

        // ===== MÓDULO REPORTES =====
        $reportes = Module::firstOrCreate(
            ['slug' => 'reportes', 'application_id' => $inventario->id],
            [
                'name' => 'Reportes',
                'icon' => 'BarChart3',
                'order' => 3,
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

        $this->command->info('  ✓ Módulo Reportes: Existencias, Movimientos, Valorizado');

        // ===== ELIMINAR MÓDULOS INCORRECTOS DE ADMINISTRATION =====
        $adminApp = Application::where('slug', 'administration')
            ->where('enterprise_id', $enterprise->id)
            ->first();

        if ($adminApp) {
            // Eliminar módulos de inventario que estaban mal ubicados
            $wrongModules = Module::where('application_id', $adminApp->id)
                ->whereIn('slug', ['catalogos', 'operaciones', 'reportes'])
                ->get();

            foreach ($wrongModules as $module) {
                // Eliminar submódulos primero
                Submodule::where('module_id', $module->id)->delete();
                $module->delete();
                $this->command->info("  ✓ Eliminado módulo incorrecto: {$module->name} de Administration");
            }
        }

        // ===== ASIGNAR PERMISOS =====
        $users = User::whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])->get();
        $modules = [$catalogos, $operaciones, $reportes];
        $submodules = [
            $categorias, $articulos,
            $entradas, $salidas, $transferencias, $ajustes,
            $stock, $movimientos, $valorizado
        ];
        $permissionTypes = SubmodulePermissionType::all();

        foreach ($users as $user) {
            // Acceso a la aplicación
            UserApplicationAccess::firstOrCreate(
                ['user_id' => $user->id, 'application_id' => $inventario->id],
                ['is_active' => true, 'granted_at' => now()]
            );

            // Acceso a módulos
            foreach ($modules as $module) {
                UserModuleAccess::firstOrCreate(
                    ['user_id' => $user->id, 'module_id' => $module->id],
                    ['is_active' => true, 'granted_at' => now()]
                );
            }

            // Acceso y permisos a submódulos
            foreach ($submodules as $submodule) {
                UserSubmoduleAccess::firstOrCreate(
                    ['user_id' => $user->id, 'submodule_id' => $submodule->id],
                    ['is_active' => true, 'granted_at' => now()]
                );

                foreach ($permissionTypes as $permType) {
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
        $this->command->info('========================================');
        $this->command->info('¡Aplicación Inventario creada exitosamente!');
        $this->command->info('Ruta: /splendidfarms/inventario');
        $this->command->info('========================================');
    }
}
