<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use Illuminate\Database\Seeder;

class CrmPermisosSeeder extends Seeder
{
    /**
     * Crea la estructura Application → Modules → Submodules → SubmodulePermissionTypes
     * del CRM en todas las empresas existentes.
     *
     * Para agregar el CRM a una nueva empresa, ejecutar:
     *   php artisan db:seed --class=CrmPermisosSeeder
     * (idempotente gracias a firstOrCreate)
     */
    public function run(): void
    {
        $enterprises = Enterprise::all();

        foreach ($enterprises as $enterprise) {
            $this->crearEstructuraCrm($enterprise);
        }

        if ($this->command) {
            $this->command->info("CRM: estructura de permisos creada para {$enterprises->count()} empresa(s).");
        }
    }

    private function crearEstructuraCrm(Enterprise $enterprise): void
    {
        // ── Aplicación CRM ────────────────────────────────────────────────
        $app = Application::firstOrCreate(
            ['enterprise_id' => $enterprise->id, 'slug' => 'crm'],
            [
                'name'        => 'CRM Comercial',
                'description' => 'Gestión de prospectos, clientes y oportunidades comerciales',
                'icon'        => 'Briefcase',
                'path'        => '/crm',
                'is_active'   => true,
            ]
        );

        // ── Módulos y submódulos ──────────────────────────────────────────
        $this->crearModulo($app, 'catalogos', 'Catálogos', 'Settings2', 1, [
            ['slug' => 'vendedores',  'name' => 'Vendedores',  'icon' => 'UserCheck',  'order' => 1, 'permisos' => $this->permisosBase()],
            ['slug' => 'regiones',    'name' => 'Regiones',    'icon' => 'Map',         'order' => 2, 'permisos' => $this->permisosBase()],
            ['slug' => 'zonas',       'name' => 'Zonas',       'icon' => 'MapPin',      'order' => 3, 'permisos' => $this->permisosBase()],
            ['slug' => 'bodegas',     'name' => 'Bodegas',     'icon' => 'Warehouse',   'order' => 4, 'permisos' => $this->permisosBase()],
            ['slug' => 'productos',   'name' => 'Productos',   'icon' => 'Package',     'order' => 5, 'permisos' => $this->permisosBase()],
            ['slug' => 'configuracion-comercial', 'name' => 'Configuración Comercial', 'icon' => 'Percent', 'order' => 6, 'permisos' => [
                ['slug' => 'ver',    'name' => 'Ver configuración comercial',    'order' => 1],
                ['slug' => 'editar', 'name' => 'Editar configuración comercial', 'order' => 2],
            ]],
        ]);

        $this->crearModulo($app, 'prospectos', 'Prospectos', 'UserPlus', 2, [
            ['slug' => 'prospectos', 'name' => 'Prospectos', 'icon' => 'UserPlus', 'order' => 1, 'permisos' => array_merge(
                $this->permisosBase(),
                [['slug' => 'asignar_vendedor', 'name' => 'Asignar vendedor', 'order' => 5]]
            )],
        ]);

        $this->crearModulo($app, 'clientes', 'Clientes', 'Users', 3, [
            ['slug' => 'clientes', 'name' => 'Clientes', 'icon' => 'Users', 'order' => 1, 'permisos' => array_merge(
                $this->permisosBase(),
                [['slug' => 'asignar_vendedor', 'name' => 'Asignar vendedor', 'order' => 5]]
            )],
        ]);

        $this->crearModulo($app, 'empresas-externas', 'Empresas Externas', 'Building2', 4, [
            ['slug' => 'empresas-externas', 'name' => 'Empresas Externas', 'icon' => 'Building2', 'order' => 1, 'permisos' => array_merge(
                $this->permisosBase(),
                [['slug' => 'gestionar_contactos', 'name' => 'Gestionar contactos', 'order' => 5]]
            )],
        ]);

        $this->crearModulo($app, 'actividades', 'Actividades', 'Activity', 5, [
            ['slug' => 'actividades', 'name' => 'Actividades', 'icon' => 'Activity', 'order' => 1, 'permisos' => $this->permisosBase()],
        ]);

        $this->crearModulo($app, 'oportunidades', 'Oportunidades', 'TrendingUp', 6, [
            ['slug' => 'oportunidades', 'name' => 'Oportunidades', 'icon' => 'TrendingUp', 'order' => 1, 'permisos' => array_merge(
                $this->permisosBase(),
                [['slug' => 'cerrar', 'name' => 'Cerrar oportunidad', 'order' => 5]]
            )],
        ]);

        $this->crearModulo($app, 'cotizaciones', 'Cotizaciones', 'FileText', 7, [
            ['slug' => 'cotizaciones', 'name' => 'Cotizaciones', 'icon' => 'FileText', 'order' => 1, 'permisos' => [
                ['slug' => 'ver',       'name' => 'Ver cotizaciones',       'order' => 1],
                ['slug' => 'crear',     'name' => 'Crear cotización',       'order' => 2],
                ['slug' => 'editar',    'name' => 'Editar cotización',      'order' => 3],
                ['slug' => 'aprobar',   'name' => 'Aprobar cotización',     'order' => 4],
                ['slug' => 'rechazar',  'name' => 'Rechazar cotización',    'order' => 5],
            ]],
        ]);

        $this->crearModulo($app, 'presupuestos', 'Presupuestos', 'Target', 8, [
            ['slug' => 'presupuestos', 'name' => 'Presupuestos', 'icon' => 'Target', 'order' => 1, 'permisos' => [
                ['slug' => 'ver',    'name' => 'Ver presupuestos',    'order' => 1],
                ['slug' => 'crear',  'name' => 'Crear presupuesto',   'order' => 2],
                ['slug' => 'editar', 'name' => 'Editar presupuesto',  'order' => 3],
            ]],
        ]);

        $this->crearModulo($app, 'agenda', 'Agenda', 'CalendarDays', 9, [
            ['slug' => 'agenda', 'name' => 'Agenda', 'icon' => 'CalendarDays', 'order' => 1, 'permisos' => $this->permisosBase()],
        ]);

        $this->crearModulo($app, 'dashboard', 'Dashboard', 'BarChart2', 10, [
            ['slug' => 'dashboard', 'name' => 'Dashboard', 'icon' => 'BarChart2', 'order' => 1, 'permisos' => [
                ['slug' => 'ver',       'name' => 'Ver dashboard',           'order' => 1],
                ['slug' => 'ejecutivo', 'name' => 'Ver dashboard ejecutivo', 'order' => 2],
            ]],
        ]);

        $this->crearModulo($app, 'integraciones', 'Integraciones', 'Plug', 11, [
            ['slug' => 'dialpad', 'name' => 'Dialpad', 'icon' => 'Phone', 'order' => 1, 'permisos' => [
                ['slug' => 'sync',   'name' => 'Sincronizar llamadas', 'order' => 1],
                ['slug' => 'ver',    'name' => 'Ver llamadas',         'order' => 2],
                ['slug' => 'editar', 'name' => 'Clasificar llamadas',  'order' => 3],
            ]],
        ]);
    }

    private function crearModulo(Application $app, string $slug, string $name, string $icon, int $order, array $submodulos): void
    {
        $module = Module::updateOrCreate(
            ['application_id' => $app->id, 'slug' => $slug],
            ['name' => $name, 'icon' => $icon, 'order' => $order, 'is_active' => true]
        );

        foreach ($submodulos as $sub) {
            $submodule = Submodule::firstOrCreate(
                ['module_id' => $module->id, 'slug' => $sub['slug']],
                [
                    'name'      => $sub['name'],
                    'icon'      => $sub['icon'],
                    'order'     => $sub['order'],
                    'is_active' => true,
                ]
            );

            foreach ($sub['permisos'] as $i => $permiso) {
                SubmodulePermissionType::firstOrCreate(
                    ['submodule_id' => $submodule->id, 'slug' => $permiso['slug']],
                    [
                        'name'      => $permiso['name'],
                        'order'     => $permiso['order'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    /** Permisos CRUD estándar compartidos por la mayoría de submodulos. */
    private function permisosBase(): array
    {
        return [
            ['slug' => 'ver',      'name' => 'Ver',      'order' => 1],
            ['slug' => 'crear',    'name' => 'Crear',    'order' => 2],
            ['slug' => 'editar',   'name' => 'Editar',   'order' => 3],
            ['slug' => 'eliminar', 'name' => 'Eliminar', 'order' => 4],
        ];
    }
}
