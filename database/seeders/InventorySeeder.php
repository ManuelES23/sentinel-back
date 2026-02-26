<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InventoryMovementType;
use App\Models\InventoryCategory;
use Illuminate\Support\Facades\DB;

class InventorySeeder extends Seeder
{
    /**
     * Seed tipos de movimiento y categorías iniciales de inventario
     */
    public function run(): void
    {
        // Desactivar verificación de claves foráneas temporalmente
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Limpiar datos existentes
        InventoryMovementType::truncate();
        InventoryCategory::truncate();

        // 1. Tipos de Movimiento
        $movementTypes = [
            [
                'code' => 'ENTRADA',
                'name' => 'Entrada',
                'slug' => 'entrada',
                'description' => 'Ingreso de productos al inventario (compras, devoluciones, producción)',
                'operation' => 'IN',
                'affects_stock' => true,
                'is_active' => true,
            ],
            [
                'code' => 'SALIDA',
                'name' => 'Salida',
                'slug' => 'salida',
                'description' => 'Salida de productos del inventario (ventas, consumos, mermas)',
                'operation' => 'OUT',
                'affects_stock' => true,
                'is_active' => true,
            ],
            [
                'code' => 'TRANSFERENCIA',
                'name' => 'Transferencia',
                'slug' => 'transferencia',
                'description' => 'Transferencia de productos entre ubicaciones (entidades/áreas)',
                'operation' => 'TRANSFER',
                'affects_stock' => true,
                'requires_destination' => true,
                'is_active' => true,
            ],
            [
                'code' => 'AJUSTE',
                'name' => 'Ajuste',
                'slug' => 'ajuste',
                'description' => 'Ajuste de inventario por inventario físico o correcciones',
                'operation' => 'ADJUST',
                'affects_stock' => true,
                'is_active' => true,
            ],
        ];

        foreach ($movementTypes as $type) {
            InventoryMovementType::create($type);
        }

        // 2. Categorías de Inventario (Ejemplo)
        $categories = [
            [
                'code' => 'MAT-PRI',
                'name' => 'Materias Primas',
                'slug' => 'materias-primas',
                'description' => 'Insumos básicos para producción',
                'parent_id' => null,
                'is_active' => true,
            ],
            [
                'code' => 'PROD-TER',
                'name' => 'Productos Terminados',
                'slug' => 'productos-terminados',
                'description' => 'Productos listos para la venta',
                'parent_id' => null,
                'is_active' => true,
            ],
            [
                'code' => 'INSUMOS',
                'name' => 'Insumos',
                'slug' => 'insumos',
                'description' => 'Insumos agrícolas y de producción',
                'parent_id' => null,
                'is_active' => true,
            ],
            [
                'code' => 'HERRAMIENTAS',
                'name' => 'Herramientas y Equipos',
                'slug' => 'herramientas-equipos',
                'description' => 'Herramientas, maquinaria y equipos',
                'parent_id' => null,
                'is_active' => true,
            ],
        ];

        foreach ($categories as $category) {
            InventoryCategory::create($category);
        }

        // 3. Subcategorías (ejemplo de jerarquía)
        $insumosParent = InventoryCategory::where('code', 'INSUMOS')->first();
        
        if ($insumosParent) {
            $subcategories = [
                [
                    'code' => 'FERT',
                    'name' => 'Fertilizantes',
                    'slug' => 'fertilizantes',
                    'description' => 'Fertilizantes químicos y orgánicos',
                    'parent_id' => $insumosParent->id,
                    'is_active' => true,
                ],
                [
                    'code' => 'PEST',
                    'name' => 'Pesticidas',
                    'slug' => 'pesticidas',
                    'description' => 'Productos para control de plagas',
                    'parent_id' => $insumosParent->id,
                    'is_active' => true,
                ],
                [
                    'code' => 'HERB',
                    'name' => 'Herbicidas',
                    'slug' => 'herbicidas',
                    'description' => 'Productos para control de malezas',
                    'parent_id' => $insumosParent->id,
                    'is_active' => true,
                ],
            ];

            foreach ($subcategories as $subcategory) {
                InventoryCategory::create($subcategory);
            }
        }

        // Reactivar verificación de claves foráneas
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->command->info('✓ Tipos de movimiento creados: ' . count($movementTypes));
        $this->command->info('✓ Categorías principales creadas: ' . count($categories));
        $this->command->info('✓ Subcategorías creadas: 3');
        $this->command->info('✓ Inventario inicializado correctamente');
    }
}
