<?php

namespace Database\Seeders;

use App\Models\MovementType;
use Illuminate\Database\Seeder;

class MovementTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $movementTypes = [
            // Entradas
            [
                'code' => 'COMPRA',
                'name' => 'Compra',
                'description' => 'Entrada por compra de productos',
                'direction' => 'in',
                'effect' => 'increase',
                'requires_source_entity' => false,
                'requires_destination_entity' => true,
                'is_system' => true,
                'color' => 'green',
                'icon' => 'ShoppingCart',
                'order' => 1,
                'is_active' => true,
            ],
            [
                'code' => 'PRODUCCION',
                'name' => 'Producción',
                'description' => 'Entrada por producción interna',
                'direction' => 'in',
                'effect' => 'increase',
                'requires_source_entity' => false,
                'requires_destination_entity' => true,
                'is_system' => true,
                'color' => 'green',
                'icon' => 'Factory',
                'order' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'DEVOLUCION-CLIENTE',
                'name' => 'Devolución de Cliente',
                'description' => 'Entrada por devolución de mercancía por cliente',
                'direction' => 'in',
                'effect' => 'increase',
                'requires_source_entity' => false,
                'requires_destination_entity' => true,
                'is_system' => true,
                'color' => 'green',
                'icon' => 'PackageCheck',
                'order' => 3,
                'is_active' => true,
            ],
            
            // Salidas
            [
                'code' => 'VENTA',
                'name' => 'Venta',
                'description' => 'Salida por venta de productos',
                'direction' => 'out',
                'effect' => 'decrease',
                'requires_source_entity' => true,
                'requires_destination_entity' => false,
                'is_system' => true,
                'color' => 'red',
                'icon' => 'ShoppingBag',
                'order' => 1,
                'is_active' => true,
            ],
            [
                'code' => 'CONSUMO',
                'name' => 'Consumo Interno',
                'description' => 'Salida por consumo interno',
                'direction' => 'out',
                'effect' => 'decrease',
                'requires_source_entity' => true,
                'requires_destination_entity' => false,
                'is_system' => true,
                'color' => 'red',
                'icon' => 'Utensils',
                'order' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'DEVOLUCION-PROVEEDOR',
                'name' => 'Devolución a Proveedor',
                'description' => 'Salida por devolución de mercancía a proveedor',
                'direction' => 'out',
                'effect' => 'decrease',
                'requires_source_entity' => true,
                'requires_destination_entity' => false,
                'is_system' => true,
                'color' => 'red',
                'icon' => 'PackageX',
                'order' => 3,
                'is_active' => true,
            ],
            [
                'code' => 'MERMA',
                'name' => 'Merma',
                'description' => 'Salida por productos dañados o vencidos',
                'direction' => 'out',
                'effect' => 'decrease',
                'requires_source_entity' => true,
                'requires_destination_entity' => false,
                'is_system' => true,
                'color' => 'red',
                'icon' => 'Trash2',
                'order' => 4,
                'is_active' => true,
            ],
            
            // Transferencias
            [
                'code' => 'TRANSFERENCIA',
                'name' => 'Transferencia',
                'description' => 'Movimiento entre ubicaciones/almacenes',
                'direction' => 'transfer',
                'effect' => 'neutral',
                'requires_source_entity' => true,
                'requires_destination_entity' => true,
                'is_system' => true,
                'color' => 'blue',
                'icon' => 'ArrowLeftRight',
                'order' => 1,
                'is_active' => true,
            ],
            
            // Ajustes
            [
                'code' => 'AJUSTE-POSITIVO',
                'name' => 'Ajuste Positivo',
                'description' => 'Ajuste por conteo físico (incremento)',
                'direction' => 'adjustment',
                'effect' => 'increase',
                'requires_source_entity' => false,
                'requires_destination_entity' => true,
                'is_system' => true,
                'color' => 'amber',
                'icon' => 'TrendingUp',
                'order' => 1,
                'is_active' => true,
            ],
            [
                'code' => 'AJUSTE-NEGATIVO',
                'name' => 'Ajuste Negativo',
                'description' => 'Ajuste por conteo físico (decremento)',
                'direction' => 'adjustment',
                'effect' => 'decrease',
                'requires_source_entity' => true,
                'requires_destination_entity' => false,
                'is_system' => true,
                'color' => 'amber',
                'icon' => 'TrendingDown',
                'order' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'INVENTARIO-INICIAL',
                'name' => 'Inventario Inicial',
                'description' => 'Carga de inventario inicial del sistema',
                'direction' => 'adjustment',
                'effect' => 'increase',
                'requires_source_entity' => false,
                'requires_destination_entity' => true,
                'is_system' => true,
                'color' => 'purple',
                'icon' => 'ClipboardList',
                'order' => 3,
                'is_active' => true,
            ],
        ];

        foreach ($movementTypes as $type) {
            MovementType::updateOrCreate(
                ['code' => $type['code']],
                $type
            );
        }

        $this->command->info('✓ Tipos de movimiento de inventario creados');
    }
}
