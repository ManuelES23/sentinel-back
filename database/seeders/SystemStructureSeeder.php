<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Enterprise;
use App\Models\Application;
use App\Models\Module;
use App\Models\Submodule;

class SystemStructureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear empresa Splendid Farms
        $enterprise = Enterprise::firstOrCreate(
            ['slug' => 'splendidfarms'],
            [
                'name' => 'Splendid Farms',
                'description' => 'Empresa agrícola principal',
            ]
        );

        // Crear aplicación Administration
        $application = Application::firstOrCreate(
            ['slug' => 'administration'],
            [
                'enterprise_id' => $enterprise->id,
                'name' => 'Administration',
                'path' => '/administration',
                'description' => 'Sistema de administración',
            ]
        );

        // Crear módulo Sistema
        $sistemaModule = Module::firstOrCreate(
            ['slug' => 'sistema', 'application_id' => $application->id],
            [
                'name' => 'Sistema',
                'icon' => 'Settings',
                'order' => 1,
            ]
        );

        // Submódulos de Sistema
        Submodule::firstOrCreate(
            ['slug' => 'usuarios', 'module_id' => $sistemaModule->id],
            [
                'name' => 'Usuarios',
                'icon' => 'Users',
                'order' => 1,
            ]
        );

        Submodule::firstOrCreate(
            ['slug' => 'roles-permisos', 'module_id' => $sistemaModule->id],
            [
                'name' => 'Roles y Permisos',
                'icon' => 'Shield',
                'order' => 2,
            ]
        );

        // Crear módulo Agrícola
        $agricolaModule = Module::firstOrCreate(
            ['slug' => 'agricola', 'application_id' => $application->id],
            [
                'name' => 'Agrícola',
                'icon' => 'Sprout',
                'order' => 2,
            ]
        );

        // Submódulos Agrícola
        Submodule::firstOrCreate(
            ['slug' => 'cultivos', 'module_id' => $agricolaModule->id],
            [
                'name' => 'Cultivos',
                'icon' => 'Sprout',
                'order' => 1,
            ]
        );

        Submodule::firstOrCreate(
            ['slug' => 'ciclos-agricolas', 'module_id' => $agricolaModule->id],
            [
                'name' => 'Ciclos Agrícolas',
                'icon' => 'Calendar',
                'order' => 2,
            ]
        );

        Submodule::firstOrCreate(
            ['slug' => 'temporadas', 'module_id' => $agricolaModule->id],
            [
                'name' => 'Temporadas',
                'icon' => 'CalendarDays',
                'order' => 3,
            ]
        );

        Submodule::firstOrCreate(
            ['slug' => 'variedades-cultivo', 'module_id' => $agricolaModule->id],
            [
                'name' => 'Variedades de Cultivo',
                'icon' => 'Leaf',
                'order' => 4,
            ]
        );

        Submodule::firstOrCreate(
            ['slug' => 'tipos-variedades', 'module_id' => $agricolaModule->id],
            [
                'name' => 'Tipos de Variedad',
                'icon' => 'Carrot',
                'order' => 5,
            ]
        );

        $this->command->info('Estructura del sistema creada exitosamente!');
        $this->command->info('Empresa: Splendid Farms');
        $this->command->info('Aplicación: Administration');
        $this->command->info('Módulos: Sistema, Agrícola');
    }
}
