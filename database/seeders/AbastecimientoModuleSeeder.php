<?php

namespace Database\Seeders;

use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\Abastecimiento\Variedad;
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
use Illuminate\Database\Seeder;

/**
 * Módulo "Abastecimiento" dentro de la app Administración, solo para
 * Splendid by Porvenir (slug splendidbyporvenir; si la empresa no existe no
 * hace nada). Siembra el menú, los tipos de permiso, el acceso de los
 * usuarios base (con permisos sin conceder) y el catálogo inicial.
 *
 * Es idempotente: todo es firstOrCreate y no reactiva lo que se desactivó.
 *
 * Ejecutar: php artisan db:seed --class=AbastecimientoModuleSeeder
 */
class AbastecimientoModuleSeeder extends Seeder
{
    public const EMPRESA = 'splendidbyporvenir';

    /** Submódulos en orden de menú. El plan 2 agrega `liquidaciones` y `abonos`. */
    public const SUBMODULOS = [
        'tablero' => ['nombre' => 'Tablero', 'icono' => 'LayoutDashboard', 'permisos' => ['view']],
        'convenios' => ['nombre' => 'Convenios', 'icono' => 'FileSignature', 'permisos' => ['view', 'create', 'edit', 'delete']],
        'recepciones' => ['nombre' => 'Recepciones', 'icono' => 'PackageCheck', 'permisos' => ['view', 'create', 'edit', 'delete']],
        'productores' => ['nombre' => 'Productores', 'icono' => 'Users', 'permisos' => ['view', 'create', 'edit', 'delete']],
        'temporadas' => ['nombre' => 'Temporadas', 'icono' => 'CalendarRange', 'permisos' => ['view', 'create', 'edit', 'delete']],
        'variedades' => ['nombre' => 'Cultivos y variedades', 'icono' => 'Sprout', 'permisos' => ['view', 'create', 'edit', 'delete']],
        'tipos-carga' => ['nombre' => 'Tipos de carga', 'icono' => 'Package', 'permisos' => ['view', 'create', 'edit', 'delete']],
    ];

    private const PERMISOS = [
        'view' => ['name' => 'Ver', 'description' => 'Ver y listar registros', 'order' => 1],
        'create' => ['name' => 'Crear', 'description' => 'Crear nuevos registros', 'order' => 2],
        'edit' => ['name' => 'Editar', 'description' => 'Editar registros existentes', 'order' => 3],
        'delete' => ['name' => 'Eliminar', 'description' => 'Eliminar registros', 'order' => 4],
    ];

    private const CATALOGO = [
        'Mango' => ['Kent', 'Ataulfo', 'Keitt', 'Tommy Atkins', 'Nam Dok Mai', 'Haden', 'Palmer', 'Criollo'],
        'Elote' => ['Amarillo', 'Blanco', 'Bicolor'],
    ];

    private const TIPO_CARGA_INICIAL = 'Caja empacada';

    public function run(): void
    {
        $this->sembrar(self::EMPRESA);
    }

    /** Siembra el módulo en la empresa del slug dado (las pruebas lo usan para otras empresas). */
    public function sembrar(string $slug): void
    {
        $empresa = Enterprise::where('slug', $slug)->first();
        if (! $empresa) {
            $this->command?->warn("Abastecimiento: no existe la empresa {$slug}, no se sembró nada.");

            return;
        }

        [$app, $modulo, $submodulos] = $this->estructura($empresa);
        $this->accesoUsuariosBase($app, $modulo, $submodulos);
        $this->catalogoInicial($empresa);

        $this->command?->info("  ✓ {$slug}/administration/abastecimiento (".count($submodulos).' submódulos)');
    }

    /** @return array{0: Application, 1: Module, 2: list<Submodule>} */
    private function estructura(Enterprise $empresa): array
    {
        $app = Application::firstOrCreate(
            ['slug' => 'administration', 'enterprise_id' => $empresa->id],
            [
                'name' => 'Administración',
                'description' => 'Administración',
                'icon' => 'Building2',
                'path' => "/{$empresa->slug}/administration",
                'is_active' => true,
            ]
        );

        $orden = (int) (Module::where('application_id', $app->id)->max('order') ?? 0) + 1;
        $modulo = Module::firstOrCreate(
            ['slug' => 'abastecimiento', 'application_id' => $app->id],
            ['name' => 'Abastecimiento', 'path' => '/abastecimiento', 'icon' => 'Handshake', 'order' => $orden, 'is_active' => true]
        );

        $submodulos = [];
        $n = 0;
        foreach (self::SUBMODULOS as $slug => $def) {
            $n++;
            $sub = Submodule::firstOrCreate(
                ['slug' => $slug, 'module_id' => $modulo->id],
                ['name' => $def['nombre'], 'icon' => $def['icono'], 'order' => $n, 'is_active' => true]
            );
            foreach ($def['permisos'] as $permiso) {
                $p = self::PERMISOS[$permiso];
                SubmodulePermissionType::firstOrCreate(
                    ['submodule_id' => $sub->id, 'slug' => $permiso],
                    ['name' => $p['name'], 'description' => $p['description'], 'order' => $p['order']]
                );
            }
            $submodulos[] = $sub;
        }

        return [$app, $modulo, $submodulos];
    }

    /** @param list<Submodule> $submodulos */
    private function accesoUsuariosBase(Application $app, Module $modulo, array $submodulos): void
    {
        $usuarios = User::whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])->get();

        foreach ($usuarios as $user) {
            UserApplicationAccess::firstOrCreate(
                ['user_id' => $user->id, 'application_id' => $app->id],
                ['is_active' => true, 'granted_at' => now()]
            );
            UserModuleAccess::firstOrCreate(
                ['user_id' => $user->id, 'module_id' => $modulo->id],
                ['is_active' => true, 'granted_at' => now()]
            );

            foreach ($submodulos as $sub) {
                UserSubmoduleAccess::firstOrCreate(
                    ['user_id' => $user->id, 'submodule_id' => $sub->id],
                    ['is_active' => true, 'granted_at' => now()]
                );
                foreach (SubmodulePermissionType::where('submodule_id', $sub->id)->get() as $tipo) {
                    UserSubmodulePermission::firstOrCreate([
                        'user_id' => $user->id,
                        'submodule_id' => $sub->id,
                        'permission_type_id' => $tipo->id,
                    ]);
                }
            }
        }
    }

    /** Cultivos, variedades y tipo de carga iniciales; lo ya existente (incluso desactivado) no se toca. */
    private function catalogoInicial(Enterprise $empresa): void
    {
        foreach (self::CATALOGO as $nombre => $variedades) {
            $cultivo = Cultivo::withTrashed()->firstOrCreate(
                ['enterprise_id' => $empresa->id, 'nombre' => $nombre],
                ['is_active' => true]
            );
            foreach ($variedades as $variedad) {
                Variedad::withTrashed()->firstOrCreate(
                    ['cultivo_id' => $cultivo->id, 'nombre' => $variedad],
                    ['is_active' => true]
                );
            }
        }

        TipoCarga::withTrashed()->firstOrCreate(
            ['enterprise_id' => $empresa->id, 'nombre' => self::TIPO_CARGA_INICIAL],
            ['is_active' => true]
        );
    }
}
