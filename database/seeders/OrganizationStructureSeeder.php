<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Enterprise;
use App\Models\Branch;
use App\Models\EntityType;
use App\Models\Entity;
use App\Models\Area;

class OrganizationStructureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener la empresa Splendid Farms
        $enterprise = Enterprise::where('slug', 'splendidfarms')->first();
        
        if (!$enterprise) {
            $this->command->error('No se encontró la empresa Splendid Farms');
            return;
        }

        $this->command->info('Creando tipos de entidades...');
        
        // Crear tipos de entidades
        $entityTypes = [
            [
                'code' => 'BODEGA',
                'name' => 'Bodega',
                'slug' => 'bodega',
                'description' => 'Almacén o bodega de almacenamiento',
                'icon' => 'Warehouse',
                'color' => '#3B82F6', // blue-500
                'is_active' => true,
                'order' => 1,
            ],
            [
                'code' => 'EMPAQUE',
                'name' => 'Planta de Empaque',
                'slug' => 'empaque',
                'description' => 'Instalación de empaque y procesamiento',
                'icon' => 'Package',
                'color' => '#10B981', // green-500
                'is_active' => true,
                'order' => 2,
            ],
            [
                'code' => 'OFICINA',
                'name' => 'Oficina',
                'slug' => 'oficina',
                'description' => 'Oficinas administrativas',
                'icon' => 'Building2',
                'color' => '#8B5CF6', // violet-500
                'is_active' => true,
                'order' => 3,
            ],
            [
                'code' => 'CAMPO',
                'name' => 'Campo',
                'slug' => 'campo',
                'description' => 'Área de cultivo o campo agrícola',
                'icon' => 'Sprout',
                'color' => '#22C55E', // green-600
                'is_active' => true,
                'order' => 4,
            ],
            [
                'code' => 'TALLER',
                'name' => 'Taller',
                'slug' => 'taller',
                'description' => 'Taller de mantenimiento y reparaciones',
                'icon' => 'Wrench',
                'color' => '#F59E0B', // amber-500
                'is_active' => true,
                'order' => 5,
            ],
        ];

        foreach ($entityTypes as $typeData) {
            EntityType::firstOrCreate(
                ['code' => $typeData['code']],
                $typeData
            );
        }

        $this->command->info('Creando sucursal principal...');

        // Crear sucursal principal
        $mainBranch = Branch::firstOrCreate(
            ['code' => 'SF-MAIN'],
            [
                'enterprise_id' => $enterprise->id,
                'name' => 'Casa Matriz',
                'slug' => 'casa-matriz',
                'description' => 'Sucursal principal de Splendid Farms',
                'address' => 'Dirección principal',
                'city' => 'Ciudad',
                'state' => 'Estado',
                'country' => 'México',
                'is_active' => true,
                'is_main' => true,
            ]
        );

        $this->command->info('Creando entidades de ejemplo...');

        // Obtener los tipos para crear entidades
        $bodegaType = EntityType::where('code', 'BODEGA')->first();
        $oficinaType = EntityType::where('code', 'OFICINA')->first();
        $empaqueType = EntityType::where('code', 'EMPAQUE')->first();

        // Crear entidades de ejemplo
        $bodegaPrincipal = Entity::firstOrCreate(
            ['code' => 'BOD-001'],
            [
                'branch_id' => $mainBranch->id,
                'entity_type_id' => $bodegaType->id,
                'name' => 'Bodega Principal',
                'slug' => 'bodega-principal',
                'description' => 'Bodega principal de almacenamiento',
                'location' => 'Planta baja, zona norte',
                'area_m2' => 500.00,
                'is_active' => true,
            ]
        );

        $oficinaCentral = Entity::firstOrCreate(
            ['code' => 'OFC-001'],
            [
                'branch_id' => $mainBranch->id,
                'entity_type_id' => $oficinaType->id,
                'name' => 'Oficinas Administrativas',
                'slug' => 'oficinas-administrativas',
                'description' => 'Oficinas del personal administrativo',
                'location' => 'Primer piso',
                'area_m2' => 250.00,
                'is_active' => true,
            ]
        );

        $empaqueCentral = Entity::firstOrCreate(
            ['code' => 'EMP-001'],
            [
                'branch_id' => $mainBranch->id,
                'entity_type_id' => $empaqueType->id,
                'name' => 'Planta de Empaque Central',
                'slug' => 'planta-empaque-central',
                'description' => 'Planta principal de empaque',
                'location' => 'Nave industrial 1',
                'area_m2' => 1200.00,
                'is_active' => true,
                'is_external' => false,
            ]
        );

        // Crear empaque externo de ejemplo
        $empaqueExterno = Entity::firstOrCreate(
            ['code' => 'EMP-002'],
            [
                'branch_id' => $mainBranch->id,
                'entity_type_id' => $empaqueType->id,
                'name' => 'Empacadora del Valle',
                'slug' => 'empacadora-del-valle',
                'description' => 'Empaque externo para temporada de invierno - Región Sinaloa',
                'location' => 'Carretera a Empalme Km 15, Guasave, Sinaloa',
                'area_m2' => 2500.00,
                'is_active' => true,
                'is_external' => true,
                'owner_company' => 'Empacadora del Valle S.A. de C.V.',
                'contact_person' => 'Roberto González',
                'contact_phone' => '687 123 4567',
                'contact_email' => 'contacto@empacadoravalle.com',
                'contract_number' => 'CONT-2026-001',
                'contract_start_date' => '2026-01-01',
                'contract_end_date' => '2026-06-30',
                'contract_notes' => 'Contrato de maquila para temporada invierno 2026. Capacidad: 50 toneladas diarias. Incluye servicio de refrigeración y control de calidad.',
            ]
        );

        $this->command->info('Creando áreas de ejemplo...');

        // Crear áreas dentro de la bodega principal
        Area::firstOrCreate(
            ['code' => 'BOD-001-INS'],
            [
                'entity_id' => $bodegaPrincipal->id,
                'name' => 'Área de Insumos',
                'slug' => 'area-insumos',
                'description' => 'Almacenamiento de insumos agrícolas',
                'area_m2' => 150.00,
                'is_active' => true,
                'allows_inventory' => true,
            ]
        );

        Area::firstOrCreate(
            ['code' => 'BOD-001-HER'],
            [
                'entity_id' => $bodegaPrincipal->id,
                'name' => 'Área de Herramientas',
                'slug' => 'area-herramientas',
                'description' => 'Almacenamiento de herramientas y equipos',
                'area_m2' => 100.00,
                'is_active' => true,
                'allows_inventory' => true,
            ]
        );

        Area::firstOrCreate(
            ['code' => 'BOD-001-REF'],
            [
                'entity_id' => $bodegaPrincipal->id,
                'name' => 'Área de Refrigeración',
                'slug' => 'area-refrigeracion',
                'description' => 'Cámara de refrigeración',
                'area_m2' => 200.00,
                'is_active' => true,
                'allows_inventory' => true,
            ]
        );

        // Crear áreas en oficinas
        Area::firstOrCreate(
            ['code' => 'OFC-001-ADM'],
            [
                'entity_id' => $oficinaCentral->id,
                'name' => 'Administración',
                'slug' => 'administracion',
                'description' => 'Oficina de administración',
                'area_m2' => 80.00,
                'is_active' => true,
                'allows_inventory' => false,
            ]
        );

        Area::firstOrCreate(
            ['code' => 'OFC-001-CONT'],
            [
                'entity_id' => $oficinaCentral->id,
                'name' => 'Contabilidad',
                'slug' => 'contabilidad',
                'description' => 'Oficina de contabilidad',
                'area_m2' => 60.00,
                'is_active' => true,
                'allows_inventory' => false,
            ]
        );

        // Crear áreas en empaque
        Area::firstOrCreate(
            ['code' => 'EMP-001-PROD'],
            [
                'entity_id' => $empaqueCentral->id,
                'name' => 'Área de Producción',
                'slug' => 'area-produccion',
                'description' => 'Línea de producción y empaque',
                'area_m2' => 800.00,
                'is_active' => true,
                'allows_inventory' => true,
            ]
        );

        Area::firstOrCreate(
            ['code' => 'EMP-001-CAL'],
            [
                'entity_id' => $empaqueCentral->id,
                'name' => 'Control de Calidad',
                'slug' => 'control-calidad',
                'description' => 'Área de control de calidad',
                'area_m2' => 100.00,
                'is_active' => true,
                'allows_inventory' => false,
            ]
        );

        $this->command->info('✓ Estructura organizacional creada exitosamente');
        $this->command->info('  - 5 tipos de entidades');
        $this->command->info('  - 1 sucursal principal');
        $this->command->info('  - 4 entidades (bodega, oficinas, 2 empaques: 1 interno + 1 externo)');
        $this->command->info('  - 7 áreas distribuidas');
    }
}
