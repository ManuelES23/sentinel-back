<?php

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\UserApplicationAccess;
use App\Models\UserModuleAccess;
use Illuminate\Database\Migrations\Migration;

/**
 * Activos Fijos pasa de Inventario a Administración en Splendid Farms,
 * Splendid by Porvenir y Grupo Espléndido (en GE se crea Administración).
 *
 * Igual que el traslado de Compras: se MUEVEN los submódulos (los permisos
 * cuelgan del submodule_id) y se arrastra el acceso al menú, que vive en
 * tablas aparte. El catálogo de tipos solo se administra desde GE, así que
 * en las demás empresas su submódulo se oculta (sin borrar sus permisos).
 */
return new class extends Migration
{
    private const EMPRESAS = ['splendidfarms', 'splendidbyporvenir', 'grupoesplendido'];
    private const CORPORATIVA = 'grupoesplendido';

    public function up(): void
    {
        foreach (self::EMPRESAS as $slug) {
            $empresa = Enterprise::where('slug', $slug)->first();
            if (! $empresa) {
                continue; // base sin esta empresa (p. ej. pruebas en limpio)
            }

            $inventario = Application::where('enterprise_id', $empresa->id)->where('slug', 'inventario')->first();
            $moduloViejo = $inventario
                ? Module::where('application_id', $inventario->id)->where('slug', 'activos-fijos')->first()
                : null;

            if (! $moduloViejo) {
                continue; // ya migrada o nunca tuvo el módulo
            }

            // `applications` no tiene columna `order`.
            $administracion = Application::firstOrCreate(
                ['enterprise_id' => $empresa->id, 'slug' => 'administration'],
                [
                    'name' => 'Administración',
                    'path' => "/{$slug}/administration",
                    'description' => 'Administración',
                    'icon' => 'Building2',
                    'is_active' => true,
                ],
            );

            $modulo = Module::firstOrCreate(
                ['application_id' => $administracion->id, 'slug' => 'activos-fijos'],
                [
                    'name' => 'Activos Fijos',
                    'path' => '/activos-fijos',
                    'icon' => $moduloViejo->icon ?? 'Truck',
                    'order' => ((int) Module::where('application_id', $administracion->id)->max('order')) + 1,
                    'is_active' => true,
                ],
            );

            Submodule::where('module_id', $moduloViejo->id)->where('slug', 'activos')
                ->update(['module_id' => $modulo->id, 'order' => 1]);
            Submodule::where('module_id', $moduloViejo->id)->where('slug', 'tipos-activo')
                ->update(['module_id' => $modulo->id, 'order' => 2, 'is_active' => $slug === self::CORPORATIVA]);

            // Acceso al menú. firstOrCreate a propósito: no resucita accesos desactivados a mano.
            $usuarios = UserModuleAccess::where('module_id', $moduloViejo->id)->where('is_active', true)->pluck('user_id')->unique();
            foreach ($usuarios as $userId) {
                UserApplicationAccess::firstOrCreate(
                    ['user_id' => $userId, 'application_id' => $administracion->id],
                    ['is_active' => true, 'granted_at' => now()],
                );
                UserModuleAccess::firstOrCreate(
                    ['user_id' => $userId, 'module_id' => $modulo->id],
                    ['is_active' => true, 'granted_at' => now()],
                );
            }

            // El módulo viejo se elimina solo si quedó vacío: borrarlo arrastraría
            // por cascada cualquier otro submódulo que no sea de Activos Fijos.
            if (! Submodule::where('module_id', $moduloViejo->id)->exists()) {
                UserModuleAccess::where('module_id', $moduloViejo->id)->delete();
                $moduloViejo->delete();
            }
        }
    }

    public function down(): void
    {
        foreach (self::EMPRESAS as $slug) {
            $empresa = Enterprise::where('slug', $slug)->first();
            $administracion = $empresa
                ? Application::where('enterprise_id', $empresa->id)->where('slug', 'administration')->first()
                : null;
            $modulo = $administracion
                ? Module::where('application_id', $administracion->id)->where('slug', 'activos-fijos')->first()
                : null;
            $inventario = $empresa
                ? Application::where('enterprise_id', $empresa->id)->where('slug', 'inventario')->first()
                : null;

            if (! $modulo || ! $inventario) {
                continue;
            }

            $viejo = Module::firstOrCreate(
                ['application_id' => $inventario->id, 'slug' => 'activos-fijos'],
                ['name' => 'Activos Fijos', 'path' => '/activos-fijos', 'icon' => 'Truck', 'is_active' => true],
            );

            Submodule::where('module_id', $modulo->id)->update(['module_id' => $viejo->id, 'is_active' => true]);

            foreach (UserModuleAccess::where('module_id', $modulo->id)->pluck('user_id') as $userId) {
                UserModuleAccess::firstOrCreate(['user_id' => $userId, 'module_id' => $viejo->id], ['is_active' => true, 'granted_at' => now()]);
            }

            UserModuleAccess::where('module_id', $modulo->id)->delete();
            $modulo->delete();
        }
    }
};
