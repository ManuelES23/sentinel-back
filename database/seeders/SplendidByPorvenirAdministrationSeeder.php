<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\UserApplicationAccess;
use App\Models\UserEnterpriseAccess;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use Illuminate\Database\Seeder;

class SplendidByPorvenirAdministrationSeeder extends Seeder
{
    public function run(): void
    {
        $enterprise = Enterprise::where('slug', 'splendidbyporvenir')->first();

        if (!$enterprise) {
            $this->command->error('No se encontró la empresa splendidbyporvenir');
            return;
        }

        $administration = Application::firstOrCreate(
            ['slug' => 'administration', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Administración',
                'description' => 'Gestión de organización y entidades',
                'icon' => 'Settings',
                'path' => '/splendidbyporvenir/administration',
                'is_active' => true,
            ]
        );

        $organizacion = Module::firstOrCreate(
            ['slug' => 'organizacion', 'application_id' => $administration->id],
            ['name' => 'Organización', 'icon' => 'Building', 'order' => 1, 'is_active' => true]
        );

        $submodules = [
            ['slug' => 'sucursales', 'name' => 'Sucursales', 'icon' => 'Building2', 'order' => 1],
            ['slug' => 'tipos-entidades', 'name' => 'Tipos de Entidades', 'icon' => 'FileType', 'order' => 2],
            ['slug' => 'entidades', 'name' => 'Entidades', 'icon' => 'Landmark', 'order' => 3],
            ['slug' => 'areas', 'name' => 'Áreas', 'icon' => 'LayoutGrid', 'order' => 4],
        ];

        foreach ($submodules as $submodule) {
            Submodule::firstOrCreate(
                ['slug' => $submodule['slug'], 'module_id' => $organizacion->id],
                [
                    'name' => $submodule['name'],
                    'icon' => $submodule['icon'],
                    'order' => $submodule['order'],
                    'is_active' => true,
                ]
            );
        }

        $submoduleIds = Submodule::where('module_id', $organizacion->id)->pluck('id');

        $userIds = UserEnterpriseAccess::where('enterprise_id', $enterprise->id)
            ->where('is_active', true)
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            UserApplicationAccess::firstOrCreate(
                ['user_id' => $userId, 'application_id' => $administration->id],
                ['is_active' => true, 'granted_at' => now()]
            );

            UserModuleAccess::firstOrCreate(
                ['user_id' => $userId, 'module_id' => $organizacion->id],
                ['is_active' => true, 'granted_at' => now()]
            );

            foreach ($submoduleIds as $submoduleId) {
                UserSubmoduleAccess::firstOrCreate(
                    ['user_id' => $userId, 'submodule_id' => $submoduleId],
                    ['is_active' => true, 'granted_at' => now()]
                );
            }
        }

        $this->command->info('Splendid by Porvenir: Administración > Organización creada/actualizada');
    }
}
