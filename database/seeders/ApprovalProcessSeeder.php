<?php

namespace Database\Seeders;

use App\Models\ApprovalProcess;
use Illuminate\Database\Seeder;

class ApprovalProcessSeeder extends Seeder
{
    public function run(): void
    {
        $processes = [
            [
                'code' => 'vacation_requests',
                'name' => 'Solicitudes de Vacaciones',
                'description' => 'Aprobación de solicitudes de vacaciones de empleados según la LFT México',
                'module' => 'grupoesplendido/rh',
                'entity_type' => 'App\\Models\\VacationRequest',
                'requires_approval' => true,
                'is_active' => true,
            ],
            [
                'code' => 'purchase_orders',
                'name' => 'Órdenes de Compra',
                'description' => 'Autorización de órdenes de compra antes de ser enviadas a proveedores',
                'module' => 'splendidfarms/inventario',
                'entity_type' => 'App\\Models\\PurchaseOrder',
                'requires_approval' => true,
                'is_active' => true,
            ],
            [
                'code' => 'incidents',
                'name' => 'Incidencias',
                'description' => 'Aprobación de incidencias laborales reportadas por empleados',
                'module' => 'grupoesplendido/rh',
                'entity_type' => 'App\\Models\\Incident',
                'requires_approval' => true,
                'is_active' => true,
            ],
            [
                'code' => 'inventory_movements',
                'name' => 'Movimientos de Inventario',
                'description' => 'Autorización de movimientos de entrada, salida y transferencia de inventario',
                'module' => 'splendidfarms/inventario',
                'entity_type' => 'App\\Models\\InventoryMovement',
                'requires_approval' => true,
                'is_active' => true,
            ],
        ];

        foreach ($processes as $process) {
            ApprovalProcess::updateOrCreate(
                ['code' => $process['code']],
                $process
            );
        }
    }
}
