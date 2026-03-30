<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Enterprise;
use App\Models\Application;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserApplicationAccess;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use App\Models\SubmodulePermissionType;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder maestro para toda la estructura del sistema SENTINEL 3.0
 * 
 * Incluye:
 * - Empresas: Grupo Espléndido, Splendid Farms, Splendid by Porvenir
 * - Aplicaciones con sus módulos y submódulos
 * - Asignación de permisos a usuarios
 * 
 * Ejecutar: php artisan db:seed --class=MasterStructureSeeder
 */
class MasterStructureSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('╔════════════════════════════════════════════════════════════╗');
        $this->command->info('║       SENTINEL 3.0 - Master Structure Seeder               ║');
        $this->command->info('╚════════════════════════════════════════════════════════════╝');
        $this->command->info('');

        // 1. Crear usuarios
        $this->createUsers();

        // 2. Crear empresas
        $enterprises = $this->createEnterprises();

        // 3. Crear aplicaciones, módulos y submódulos
        $this->createGrupoEsplendidoApps($enterprises['grupoesplendido']);
        $this->createSplendidFarmsApps($enterprises['splendidfarms']);
        $this->createSplendidByPorvenirApps($enterprises['splendidbyporvenir']);

        // 4. Asignar permisos
        $this->assignPermissions();

        $this->command->info('');
        $this->command->info('╔════════════════════════════════════════════════════════════╗');
        $this->command->info('║          ¡Estructura creada exitosamente!                  ║');
        $this->command->info('╚════════════════════════════════════════════════════════════╝');
    }

    /**
     * Crear usuarios del sistema
     */
    private function createUsers(): void
    {
        $this->command->info('📦 Creando usuarios...');

        User::firstOrCreate(
            ['email' => 'admin@sentinel.com'],
            [
                'name' => 'Administrador',
                'password' => Hash::make('password123'),
                'is_admin' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'demo@sentinel.com'],
            [
                'name' => 'Usuario Demo',
                'password' => Hash::make('password123'),
                'is_admin' => false,
            ]
        );

        $this->command->info('  ✓ admin@sentinel.com (Administrador)');
        $this->command->info('  ✓ demo@sentinel.com (Usuario Demo)');
    }

    /**
     * Crear las empresas del grupo
     */
    private function createEnterprises(): array
    {
        $this->command->info('');
        $this->command->info('🏢 Creando empresas...');

        $grupoesplendido = Enterprise::firstOrCreate(
            ['slug' => 'grupoesplendido'],
            [
                'name' => 'Grupo Espléndido',
                'description' => 'Corporativo - Gestión centralizada de todas las empresas',
                'color' => '#6366F1',
                'is_active' => true,
            ]
        );
        $this->command->info('  ✓ Grupo Espléndido (Corporativo)');

        $splendidfarms = Enterprise::firstOrCreate(
            ['slug' => 'splendidfarms'],
            [
                'name' => 'Splendid Farms',
                'description' => 'Empresa agrícola especializada en cultivos',
                'color' => '#10B981',
                'is_active' => true,
            ]
        );
        $this->command->info('  ✓ Splendid Farms (Agrícola)');

        $splendidbyporvenir = Enterprise::firstOrCreate(
            ['slug' => 'splendidbyporvenir'],
            [
                'name' => 'Splendid by Porvenir',
                'description' => 'Empresa de exportación y ventas de fruta',
                'color' => '#3B82F6',
                'is_active' => true,
            ]
        );
        $this->command->info('  ✓ Splendid by Porvenir (Exportación)');

        return [
            'grupoesplendido' => $grupoesplendido,
            'splendidfarms' => $splendidfarms,
            'splendidbyporvenir' => $splendidbyporvenir,
        ];
    }

    /**
     * Aplicaciones de Grupo Espléndido (Corporativo)
     */
    private function createGrupoEsplendidoApps(Enterprise $enterprise): void
    {
        $this->command->info('');
        $this->command->info("📱 Creando aplicaciones para: {$enterprise->name}");

        // ========================================
        // APLICACIÓN: RECURSOS HUMANOS
        // ========================================
        $rh = Application::firstOrCreate(
            ['slug' => 'rh', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Recursos Humanos',
                'description' => 'Gestión de personal y asistencia',
                'icon' => 'Users',
                'path' => '/grupoesplendido/rh',
                'is_active' => true,
            ]
        );
        $this->command->info("  ✓ Recursos Humanos");

        // Módulo: Catálogos
        $rhCatalogos = Module::firstOrCreate(
            ['slug' => 'catalogos', 'application_id' => $rh->id],
            ['name' => 'Catálogos', 'icon' => 'BookOpen', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'departamentos', 'module_id' => $rhCatalogos->id],
            ['name' => 'Departamentos', 'icon' => 'Building2', 'order' => 1, 'is_active' => true]
        );
        Submodule::firstOrCreate(
            ['slug' => 'puestos', 'module_id' => $rhCatalogos->id],
            ['name' => 'Puestos', 'icon' => 'Briefcase', 'order' => 2, 'is_active' => true]
        );
        Submodule::firstOrCreate(
            ['slug' => 'horarios', 'module_id' => $rhCatalogos->id],
            ['name' => 'Horarios', 'icon' => 'Calendar', 'order' => 3, 'is_active' => true]
        );
        $this->command->info("    → Catálogos: Departamentos, Puestos, Horarios");

        // Módulo: Empleados
        $rhEmpleados = Module::firstOrCreate(
            ['slug' => 'empleados', 'application_id' => $rh->id],
            ['name' => 'Empleados', 'icon' => 'UserCircle', 'order' => 2, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'lista', 'module_id' => $rhEmpleados->id],
            ['name' => 'Lista de Empleados', 'icon' => 'Users', 'order' => 1, 'is_active' => true]
        );
        $this->command->info("    → Empleados: Lista de Empleados");

        // Módulo: Asistencia
        $rhAsistencia = Module::firstOrCreate(
            ['slug' => 'asistencia', 'application_id' => $rh->id],
            ['name' => 'Asistencia', 'icon' => 'Clock', 'order' => 3, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'registros', 'module_id' => $rhAsistencia->id],
            ['name' => 'Registros', 'icon' => 'ClipboardList', 'order' => 1, 'is_active' => true]
        );
        $this->command->info("    → Asistencia: Registros");

        Submodule::firstOrCreate(
            ['slug' => 'checador', 'module_id' => $rhAsistencia->id],
            ['name' => 'Checador', 'icon' => 'ScanLine', 'order' => 2, 'is_active' => true]
        );
        $this->command->info("    → Asistencia: Checador");

        // Módulo: Gestión (Vacaciones e Incidencias)
        $rhGestion = Module::firstOrCreate(
            ['slug' => 'gestion', 'application_id' => $rh->id],
            ['name' => 'Gestión', 'icon' => 'ClipboardCheck', 'order' => 4, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'vacaciones', 'module_id' => $rhGestion->id],
            ['name' => 'Vacaciones', 'icon' => 'Sun', 'order' => 1, 'is_active' => true]
        );
        $this->command->info("    → Gestión: Vacaciones");

        Submodule::firstOrCreate(
            ['slug' => 'incidencias', 'module_id' => $rhGestion->id],
            ['name' => 'Incidencias', 'icon' => 'AlertTriangle', 'order' => 2, 'is_active' => true]
        );
        $this->command->info("    → Gestión: Incidencias");
    }

    /**
     * Aplicaciones de Splendid Farms
     */
    private function createSplendidFarmsApps(Enterprise $enterprise): void
    {
        $this->command->info('');
        $this->command->info("📱 Creando aplicaciones para: {$enterprise->name}");

        // ========================================
        // APLICACIÓN: ADMINISTRACIÓN
        // ========================================
        $administration = Application::firstOrCreate(
            ['slug' => 'administration', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Administración',
                'description' => 'Gestión administrativa general',
                'icon' => 'Settings',
                'path' => '/splendidfarms/administration',
                'is_active' => true,
            ]
        );
        $this->command->info("  ✓ Administración");

        // Módulo: Agrícola
        $agricola = Module::firstOrCreate(
            ['slug' => 'agricola', 'application_id' => $administration->id],
            ['name' => 'Agrícola', 'icon' => 'Sprout', 'order' => 1, 'is_active' => true]
        );

        $agricolaSubmodules = [
            ['slug' => 'cultivos', 'name' => 'Cultivos', 'icon' => 'Sprout', 'order' => 1],
            ['slug' => 'ciclos-agricolas', 'name' => 'Ciclos Agrícolas', 'icon' => 'RefreshCw', 'order' => 2],
            ['slug' => 'temporadas', 'name' => 'Temporadas', 'icon' => 'CalendarDays', 'order' => 3],
            ['slug' => 'variedades-cultivo', 'name' => 'Variedades de Cultivo', 'icon' => 'Leaf', 'order' => 4],
            ['slug' => 'tipos-variedades', 'name' => 'Tipos de Variedad', 'icon' => 'Carrot', 'order' => 5],
            ['slug' => 'productores', 'name' => 'Productores', 'icon' => 'Tractor', 'order' => 6],
            ['slug' => 'zonas-cultivo', 'name' => 'Zonas de Cultivo', 'icon' => 'MapPin', 'order' => 7],
            ['slug' => 'lotes', 'name' => 'Lotes', 'icon' => 'Map', 'order' => 8],
            ['slug' => 'calibres', 'name' => 'Calibres', 'icon' => 'Ruler', 'order' => 9],
        ];

        foreach ($agricolaSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $agricola->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }
        $this->command->info("    → Agrícola: Cultivos, Ciclos, Temporadas, Variedades, Productores, Zonas, Lotes, Calibres");

        // Módulo: Compras Agrícolas
        $comprasAgricolas = Module::firstOrCreate(
            ['slug' => 'compras-agricolas', 'application_id' => $administration->id],
            ['name' => 'Compras Agrícolas', 'icon' => 'HandCoins', 'order' => 4, 'is_active' => true]
        );

        $comprasAgricolasSubmodules = [
            ['slug' => 'convenios-compra', 'name' => 'Convenios de Compra', 'icon' => 'Handshake', 'order' => 1],
            ['slug' => 'liquidaciones', 'name' => 'Liquidaciones', 'icon' => 'Receipt', 'order' => 2],
            ['slug' => 'tablero-productores', 'name' => 'Tablero de Productores', 'icon' => 'BarChart3', 'order' => 3],
        ];

        foreach ($comprasAgricolasSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $comprasAgricolas->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }
        $this->command->info("    → Compras Agrícolas: Convenios, Liquidaciones, Tablero Productores");

        // Módulo: Organización
        $organizacion = Module::firstOrCreate(
            ['slug' => 'organizacion', 'application_id' => $administration->id],
            ['name' => 'Organización', 'icon' => 'Building', 'order' => 2, 'is_active' => true]
        );

        $orgSubmodules = [
            ['slug' => 'sucursales', 'name' => 'Sucursales', 'icon' => 'Building2', 'order' => 1],
            ['slug' => 'tipos-entidades', 'name' => 'Tipos de Entidades', 'icon' => 'FileType', 'order' => 2],
            ['slug' => 'entidades', 'name' => 'Entidades', 'icon' => 'Landmark', 'order' => 3],
            ['slug' => 'areas', 'name' => 'Áreas', 'icon' => 'LayoutGrid', 'order' => 4],
        ];

        foreach ($orgSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $organizacion->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }
        $this->command->info("    → Organización: Sucursales, Tipos Entidades, Entidades, Áreas");

        // Módulo: Catálogos
        $catalogos = Module::firstOrCreate(
            ['slug' => 'catalogos', 'application_id' => $administration->id],
            ['name' => 'Catálogos', 'icon' => 'FolderOpen', 'order' => 3, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'proveedores', 'module_id' => $catalogos->id],
            ['name' => 'Proveedores', 'icon' => 'Truck', 'order' => 1, 'is_active' => true]
        );
        $this->command->info("    → Catálogos: Proveedores");

        // ========================================
        // APLICACIÓN: INVENTARIO
        // ========================================
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
        $this->command->info("  ✓ Inventario");

        // Módulo: Catálogos de Inventario
        $invCatalogos = Module::firstOrCreate(
            ['slug' => 'catalogos', 'application_id' => $inventario->id],
            ['name' => 'Catálogos', 'icon' => 'FolderOpen', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'categorias', 'module_id' => $invCatalogos->id],
            ['name' => 'Categorías', 'icon' => 'Tags', 'order' => 1, 'is_active' => true]
        );
        Submodule::firstOrCreate(
            ['slug' => 'articulos', 'module_id' => $invCatalogos->id],
            ['name' => 'Artículos', 'icon' => 'Package', 'order' => 2, 'is_active' => true]
        );
        Submodule::firstOrCreate(
            ['slug' => 'recetas', 'module_id' => $invCatalogos->id],
            ['name' => 'Recetas', 'icon' => 'ChefHat', 'order' => 3, 'is_active' => true]
        );
        Submodule::firstOrCreate(
            ['slug' => 'tipos-carga', 'module_id' => $invCatalogos->id],
            ['name' => 'Tipos de Carga', 'icon' => 'BoxSelect', 'order' => 4, 'is_active' => true]
        );
        $this->command->info("    → Catálogos: Categorías, Artículos, Recetas, Tipos de Carga");

        // Módulo: Operaciones
        $operaciones = Module::firstOrCreate(
            ['slug' => 'operaciones', 'application_id' => $inventario->id],
            ['name' => 'Operaciones', 'icon' => 'ArrowLeftRight', 'order' => 2, 'is_active' => true]
        );

        $opSubmodules = [
            ['slug' => 'entradas', 'name' => 'Entradas', 'icon' => 'ArrowDownLeft', 'order' => 1],
            ['slug' => 'salidas', 'name' => 'Salidas', 'icon' => 'ArrowUpRight', 'order' => 2],
            ['slug' => 'transferencias', 'name' => 'Transferencias', 'icon' => 'ArrowLeftRight', 'order' => 3],
            ['slug' => 'ajustes', 'name' => 'Ajustes', 'icon' => 'SlidersHorizontal', 'order' => 4],
        ];

        foreach ($opSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $operaciones->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }
        $this->command->info("    → Operaciones: Entradas, Salidas, Transferencias, Ajustes");

        // Módulo: Reportes
        $reportes = Module::firstOrCreate(
            ['slug' => 'reportes', 'application_id' => $inventario->id],
            ['name' => 'Reportes', 'icon' => 'BarChart3', 'order' => 3, 'is_active' => true]
        );

        $repSubmodules = [
            ['slug' => 'stock', 'name' => 'Existencias', 'icon' => 'Boxes', 'order' => 1],
            ['slug' => 'movimientos', 'name' => 'Movimientos', 'icon' => 'History', 'order' => 2],
            ['slug' => 'valorizado', 'name' => 'Valorizado', 'icon' => 'DollarSign', 'order' => 3],
        ];

        foreach ($repSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $reportes->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }
        $this->command->info("    → Reportes: Existencias, Movimientos, Valorizado");

        // Módulo: Compras
        $compras = Module::firstOrCreate(
            ['slug' => 'compras', 'application_id' => $inventario->id],
            ['name' => 'Compras', 'icon' => 'ShoppingCart', 'order' => 4, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'ordenes-compra', 'module_id' => $compras->id],
            ['name' => 'Órdenes de Compra', 'icon' => 'FileText', 'order' => 1, 'is_active' => true]
        );
        Submodule::firstOrCreate(
            ['slug' => 'recepciones', 'module_id' => $compras->id],
            ['name' => 'Recepciones', 'icon' => 'PackageCheck', 'order' => 2, 'is_active' => true]
        );
        $this->command->info("    → Compras: Órdenes de Compra, Recepciones");

        // ========================================
        // APLICACIÓN: CONTABILIDAD
        // ========================================
        $accounting = Application::firstOrCreate(
            ['slug' => 'accounting', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Contabilidad',
                'description' => 'Gestión contable y financiera',
                'icon' => 'Calculator',
                'path' => '/splendidfarms/accounting',
                'is_active' => true,
            ]
        );
        $this->command->info("  ✓ Contabilidad");

        // Módulo: Cuentas por Pagar
        $cxp = Module::firstOrCreate(
            ['slug' => 'cxp', 'application_id' => $accounting->id],
            ['name' => 'Cuentas por Pagar', 'icon' => 'Receipt', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'documentos', 'module_id' => $cxp->id],
            ['name' => 'Documentos', 'icon' => 'FileText', 'order' => 1, 'is_active' => true]
        );
        $this->command->info("    → Cuentas por Pagar: Documentos");

        // ========================================
        // APLICACIÓN: OPERACIÓN AGRÍCOLA
        // ========================================
        $operacionAgricola = Application::firstOrCreate(
            ['slug' => 'operacion-agricola', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Operación Agrícola',
                'description' => 'Gestión de operaciones agrícolas, siembra, visitas y aplicaciones',
                'icon' => 'Tractor',
                'path' => '/splendidfarms/operacion-agricola',
                'is_active' => true,
            ]
        );
        $this->command->info("  ✓ Operación Agrícola");

        // Módulo: Agrícola
        $oaAgricola = Module::firstOrCreate(
            ['slug' => 'agricola', 'application_id' => $operacionAgricola->id],
            ['name' => 'Agrícola', 'icon' => 'Sprout', 'order' => 1, 'is_active' => true]
        );

        $oaSubmodules = [
            ['slug' => 'productores', 'name' => 'Productores', 'icon' => 'Users', 'order' => 1],
            ['slug' => 'zonas-cultivo', 'name' => 'Zonas de Cultivo', 'icon' => 'Map', 'order' => 2],
            ['slug' => 'lotes', 'name' => 'Lotes', 'icon' => 'LandPlot', 'order' => 3],
            ['slug' => 'etapas', 'name' => 'Etapas', 'icon' => 'Layers', 'order' => 4],
            ['slug' => 'plan-siembra', 'name' => 'Plan de Siembra', 'icon' => 'Calendar', 'order' => 5],
            ['slug' => 'visitas-campo', 'name' => 'Visitas de Campo', 'icon' => 'ClipboardCheck', 'order' => 6],
            ['slug' => 'aplicaciones', 'name' => 'Aplicaciones', 'icon' => 'Beaker', 'order' => 7],
            ['slug' => 'requisiciones', 'name' => 'Requisiciones', 'icon' => 'ShoppingCart', 'order' => 8],
            ['slug' => 'costeo-agricola', 'name' => 'Costeo Agrícola', 'icon' => 'Calculator', 'order' => 9],
        ];

        foreach ($oaSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $oaAgricola->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }
        $this->command->info("    → Agrícola: Productores, Zonas, Lotes, Etapas, Plan Siembra, Visitas, Aplicaciones, Requisiciones, Costeo");

        // Módulo: Cosecha
        $oaCosecha = Module::firstOrCreate(
            ['slug' => 'cosecha', 'application_id' => $operacionAgricola->id],
            ['name' => 'Cosecha', 'icon' => 'Wheat', 'order' => 2, 'is_active' => true]
        );

        $cosechaSubmodules = [
            ['slug' => 'salidas-campo', 'name' => 'Salidas de Campo', 'icon' => 'Truck', 'order' => 1],
            ['slug' => 'cierres-cosecha', 'name' => 'Cierres de Cosecha', 'icon' => 'ClipboardCheck', 'order' => 2],
            ['slug' => 'ventas-cosecha', 'name' => 'Ventas de Cosecha', 'icon' => 'DollarSign', 'order' => 3],
            ['slug' => 'calidad', 'name' => 'Calidad', 'icon' => 'Award', 'order' => 4],
        ];

        foreach ($cosechaSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $oaCosecha->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }
        $this->command->info("    → Cosecha: Salidas de Campo, Cierres, Ventas, Calidad");

        // Módulo: Empaque
        $oaEmpaque = Module::firstOrCreate(
            ['slug' => 'empaque', 'application_id' => $operacionAgricola->id],
            ['name' => 'Empaque', 'icon' => 'Package', 'order' => 3, 'is_active' => true]
        );

        $empaqueSubmodules = [
            ['slug' => 'recepciones',  'name' => 'Recepciones',      'icon' => 'Download',       'order' => 1],
            ['slug' => 'proceso',      'name' => 'Proceso',          'icon' => 'Layers',         'order' => 2],
            ['slug' => 'produccion',   'name' => 'Producción',       'icon' => 'Package',        'order' => 3],
            ['slug' => 'rezaga',       'name' => 'Rezaga',           'icon' => 'Trash2',         'order' => 4],
            ['slug' => 'embarques',    'name' => 'Embarques',        'icon' => 'Truck',          'order' => 5],
            ['slug' => 'venta-rezaga', 'name' => 'Venta de Rezaga',  'icon' => 'ShoppingCart',   'order' => 6],
            ['slug' => 'calidad',      'name' => 'Calidad',          'icon' => 'ClipboardCheck', 'order' => 7],
        ];

        foreach ($empaqueSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $oaEmpaque->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }
        $this->command->info("    → Empaque: Recepciones, Proceso, Producción, Rezaga, Embarques, Venta Rezaga, Calidad");
    }

    /**
     * Aplicaciones de Splendid by Porvenir
     */
    private function createSplendidByPorvenirApps(Enterprise $enterprise): void
    {
        $this->command->info('');
        $this->command->info("📱 Creando aplicaciones para: {$enterprise->name}");

        // ========================================
        // APLICACIÓN: VENTAS
        // ========================================
        $sales = Application::firstOrCreate(
            ['slug' => 'sales', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Ventas',
                'description' => 'Gestión de ventas y clientes',
                'icon' => 'ShoppingBag',
                'path' => '/splendidbyporvenir/sales',
                'is_active' => true,
            ]
        );
        $this->command->info("  ✓ Ventas");

        // Módulo: Clientes
        $clientes = Module::firstOrCreate(
            ['slug' => 'clientes', 'application_id' => $sales->id],
            ['name' => 'Clientes', 'icon' => 'Users', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'catalogo', 'module_id' => $clientes->id],
            ['name' => 'Catálogo de Clientes', 'icon' => 'UserCircle', 'order' => 1, 'is_active' => true]
        );
        $this->command->info("    → Clientes: Catálogo de Clientes");

        // ========================================
        // APLICACIÓN: EXPORTACIONES
        // ========================================
        $exports = Application::firstOrCreate(
            ['slug' => 'exports', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Exportaciones',
                'description' => 'Manejo de exportaciones',
                'icon' => 'Ship',
                'path' => '/splendidbyporvenir/exports',
                'is_active' => true,
            ]
        );
        $this->command->info("  ✓ Exportaciones");

        // Módulo: Embarques
        $embarques = Module::firstOrCreate(
            ['slug' => 'embarques', 'application_id' => $exports->id],
            ['name' => 'Embarques', 'icon' => 'Container', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'programacion', 'module_id' => $embarques->id],
            ['name' => 'Programación', 'icon' => 'Calendar', 'order' => 1, 'is_active' => true]
        );
        $this->command->info("    → Embarques: Programación");

        // ========================================
        // APLICACIÓN: COMPRAS DE FRUTA
        // ========================================
        $purchases = Application::firstOrCreate(
            ['slug' => 'purchases', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Compras de Fruta',
                'description' => 'Gestión de compras de fruta a productores',
                'icon' => 'Apple',
                'path' => '/splendidbyporvenir/purchases',
                'is_active' => true,
            ]
        );
        $this->command->info("  ✓ Compras de Fruta");

        // Módulo: Recepción
        $recepcion = Module::firstOrCreate(
            ['slug' => 'recepcion', 'application_id' => $purchases->id],
            ['name' => 'Recepción', 'icon' => 'PackageCheck', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'tickets', 'module_id' => $recepcion->id],
            ['name' => 'Tickets de Recepción', 'icon' => 'Ticket', 'order' => 1, 'is_active' => true]
        );
        $this->command->info("    → Recepción: Tickets de Recepción");
    }

    /**
     * Asignar permisos completos a usuarios admin y demo
     */
    private function assignPermissions(): void
    {
        $this->command->info('');
        $this->command->info('🔐 Asignando permisos...');

        $users = User::whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])->get();
        $enterprises = Enterprise::all();
        $permissionTypes = SubmodulePermissionType::all();

        foreach ($users as $user) {
            $this->command->info("  Usuario: {$user->email}");

            foreach ($enterprises as $enterprise) {
                // Acceso a empresa
                UserEnterpriseAccess::firstOrCreate(
                    ['user_id' => $user->id, 'enterprise_id' => $enterprise->id],
                    ['is_active' => true, 'granted_at' => now()]
                );

                // Acceso a todas las aplicaciones de la empresa
                $applications = Application::where('enterprise_id', $enterprise->id)->get();
                foreach ($applications as $application) {
                    UserApplicationAccess::firstOrCreate(
                        ['user_id' => $user->id, 'application_id' => $application->id],
                        ['is_active' => true, 'granted_at' => now()]
                    );

                    // Acceso a módulos
                    $modules = Module::where('application_id', $application->id)->get();
                    foreach ($modules as $module) {
                        UserModuleAccess::firstOrCreate(
                            ['user_id' => $user->id, 'module_id' => $module->id],
                            ['is_active' => true, 'granted_at' => now()]
                        );

                        // Acceso a submódulos
                        $submodules = Submodule::where('module_id', $module->id)->get();
                        foreach ($submodules as $submodule) {
                            UserSubmoduleAccess::firstOrCreate(
                                ['user_id' => $user->id, 'submodule_id' => $submodule->id],
                                ['is_active' => true, 'granted_at' => now()]
                            );

                            // Permisos CRUD
                            foreach ($permissionTypes as $permType) {
                                UserSubmodulePermission::firstOrCreate([
                                    'user_id' => $user->id,
                                    'submodule_id' => $submodule->id,
                                    'permission_type_id' => $permType->id,
                                ]);
                            }
                        }
                    }
                }
                $this->command->info("    ✓ {$enterprise->name}");
            }
        }
    }
}
