<?php
// database/seeders/CanesAgroInventoryModuleSeeder.php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use Illuminate\Database\Seeder;

/**
 * Da de alta Inventario -> Catálogos (Artículos, Categorías, Marcas,
 * Recetas) para Canes Agro. Solo Catálogos — Canes Agro no necesita
 * Activos Fijos, Operaciones, Compras ni Reportes de Inventario (fuera de
 * alcance, ver docs/superpowers/specs/2026-09-09-recetas-generalization-design.md).
 * Idempotente: correr dos veces no duplica nada.
 */
class CanesAgroInventoryModuleSeeder extends Seeder
{
    public function run(): void
    {
        $enterprise = Enterprise::where('slug', 'canes-agro')->firstOrFail();

        $application = Application::firstOrCreate(
            ['enterprise_id' => $enterprise->id, 'slug' => 'inventario'],
            ['name' => 'Inventario', 'description' => 'Gestión de inventario y catálogos', 'icon' => 'Package', 'path' => '/inventario', 'is_active' => true],
        );

        $catalogos = Module::firstOrCreate(
            ['application_id' => $application->id, 'slug' => 'catalogos'],
            ['name' => 'Catálogos', 'icon' => 'FolderOpen', 'order' => 1, 'is_active' => true],
        );

        $submodules = [
            ['slug' => 'categorias', 'name' => 'Categorías', 'icon' => 'Tag', 'order' => 1],
            ['slug' => 'marcas', 'name' => 'Marcas', 'icon' => 'Award', 'order' => 2],
            ['slug' => 'articulos', 'name' => 'Artículos', 'icon' => 'Package', 'order' => 3],
            ['slug' => 'recetas', 'name' => 'Recetas', 'icon' => 'FlaskConical', 'order' => 4],
        ];

        foreach ($submodules as $sub) {
            Submodule::firstOrCreate(
                ['module_id' => $catalogos->id, 'slug' => $sub['slug']],
                array_merge($sub, ['is_active' => true]),
            );
        }
    }
}
