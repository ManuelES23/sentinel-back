<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use App\Services\ActivosFijos\AlcanceActivos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder del módulo "Organización" (Sucursales, Tipos de Entidades, Entidades y
 * Áreas) dentro de la app Administración, para las empresas que todavía no lo
 * tienen (Canes Agro, Splendid Logistic y las que se agreguen).
 *
 * Recorre TODAS las empresas activas EXCEPTO Grupo Espléndido (corporativo: solo
 * consolida, no captura su propia organización). Las empresas que ya tienen el
 * módulo (Splendid Farms, Splendid by Porvenir) se omiten sin tocarlas. Las
 * inactivas se omiten.
 *
 * Estructura final (por empresa nueva):
 *   Administración
 *     └── Organización (módulo)
 *           ├── Sucursales          (slug: sucursales)
 *           ├── Tipos de Entidades  (slug: tipos-entidades)
 *           ├── Entidades           (slug: entidades)
 *           └── Áreas               (slug: areas)
 *
 * Solo admin@sentinel.com y demo@sentinel.com reciben el acceso y las filas de
 * permiso (sin conceder: `is_granted` queda en su valor por defecto). Los demás
 * usuarios se configuran en el panel de permisos.
 *
 * Es seguro volver a correr este seeder (idempotente: todo es firstOrCreate).
 *
 * Ejecutar: php artisan db:seed --class=OrganizacionEmpresasSeeder
 */
class OrganizacionEmpresasSeeder extends Seeder
{
    private const STANDARD_PERMISSIONS = [
        ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver y listar registros', 'order' => 1],
        ['slug' => 'create', 'name' => 'Crear', 'description' => 'Crear nuevos registros', 'order' => 2],
        ['slug' => 'edit', 'name' => 'Editar', 'description' => 'Editar registros existentes', 'order' => 3],
        ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Eliminar registros', 'order' => 4],
    ];

    private const SUBMODULES = [
        ['slug' => 'sucursales', 'name' => 'Sucursales', 'icon' => 'Building2', 'order' => 1],
        ['slug' => 'tipos-entidades', 'name' => 'Tipos de Entidades', 'icon' => 'FileType', 'order' => 2],
        ['slug' => 'entidades', 'name' => 'Entidades', 'icon' => 'Landmark', 'order' => 3],
        ['slug' => 'areas', 'name' => 'Áreas', 'icon' => 'LayoutGrid', 'order' => 4],
    ];

    public function run(): void
    {
        $this->command?->info('');
        $this->command?->info('🏢 Configurando módulo Organización...');

        $nuevas = [];

        $empresas = Enterprise::where('is_active', true)
            ->where('slug', '!=', AlcanceActivos::EMPRESA_CORPORATIVA)
            ->orderBy('id')
            ->get();

        foreach ($empresas as $enterprise) {
            // Una transacción por empresa: nunca queda un módulo a medias.
            $entry = DB::transaction(fn () => $this->ensureModuleStructure($enterprise));

            if ($entry === null) {
                $this->command?->info("  · {$enterprise->slug}: ya tiene Organización, sin cambios");
                continue;
            }

            $nuevas[] = $entry;
            $this->command?->info("  ✓ {$enterprise->slug}/administration/organizacion/{sucursales,tipos-entidades,entidades,areas}");
        }

        if (empty($nuevas)) {
            $this->command?->info('Ninguna empresa activa necesitaba el módulo Organización.');
            return;
        }

        // Acceso (sin conceder permisos) para los usuarios base del sistema
        $users = User::whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])->get();

        foreach ($users as $user) {
            foreach ($nuevas as $entry) {
                UserApplicationAccess::firstOrCreate(
                    ['user_id' => $user->id, 'application_id' => $entry['app']->id],
                    ['is_active' => true, 'granted_at' => now()]
                );

                UserModuleAccess::firstOrCreate(
                    ['user_id' => $user->id, 'module_id' => $entry['module']->id],
                    ['is_active' => true, 'granted_at' => now()]
                );

                foreach ($entry['submodules'] as $submodule) {
                    UserSubmoduleAccess::firstOrCreate(
                        ['user_id' => $user->id, 'submodule_id' => $submodule->id],
                        ['is_active' => true, 'granted_at' => now()]
                    );

                    foreach (SubmodulePermissionType::where('submodule_id', $submodule->id)->get() as $permType) {
                        UserSubmodulePermission::firstOrCreate([
                            'user_id' => $user->id,
                            'submodule_id' => $submodule->id,
                            'permission_type_id' => $permType->id,
                        ]);
                    }
                }
            }
            $this->command?->info("  ✓ Acceso asignado a: {$user->email}");
        }

        $this->command?->info('');
        $this->command?->info('✅ Módulo Organización listo en '.count($nuevas).' empresa(s)');
        $this->command?->warn('⚠ Falta dar permisos de Organización a los usuarios de cada empresa nueva');
    }

    /**
     * Crea Organización en la empresa. Devuelve null si ya la tenía (no se toca).
     */
    private function ensureModuleStructure(Enterprise $enterprise): ?array
    {
        $enterpriseSlug = $enterprise->slug;

        $app = Application::firstOrCreate(
            ['slug' => 'administration', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Administración',
                'description' => 'Administración',
                'icon' => 'Building2',
                'path' => "/{$enterpriseSlug}/administration",
                'is_active' => true,
            ]
        );

        if (Module::where('application_id', $app->id)->where('slug', 'organizacion')->exists()) {
            return null;
        }

        $order = (int) (Module::where('application_id', $app->id)->max('order') ?? 0) + 1;
        $module = Module::create([
            'application_id' => $app->id,
            'slug' => 'organizacion',
            'name' => 'Organización',
            'icon' => 'Building',
            'order' => $order,
            'is_active' => true,
        ]);

        $submodules = [];
        foreach (self::SUBMODULES as $data) {
            $submodule = Submodule::firstOrCreate(
                ['slug' => $data['slug'], 'module_id' => $module->id],
                ['name' => $data['name'], 'icon' => $data['icon'], 'order' => $data['order'], 'is_active' => true]
            );
            $this->ensurePermissionTypes($submodule);
            $submodules[] = $submodule;
        }

        return ['app' => $app, 'module' => $module, 'submodules' => $submodules];
    }

    private function ensurePermissionTypes(Submodule $submodule): void
    {
        foreach (self::STANDARD_PERMISSIONS as $perm) {
            SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $submodule->id, 'slug' => $perm['slug']],
                ['name' => $perm['name'], 'description' => $perm['description'], 'order' => $perm['order']]
            );
        }
    }
}
