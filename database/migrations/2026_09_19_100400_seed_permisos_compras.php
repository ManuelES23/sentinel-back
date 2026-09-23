<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tipos de permiso de la fase 2 (en cada empresa que tenga el submódulo) y el
 * proceso de aprobación de OC (antes solo lo creaba un seeder).
 */
return new class extends Migration
{
    private const PERMISOS = [
        ['operacion-agricola', 'agricola', 'requisiciones', 'cotizar', 'Cotizar', 'Recibe requisiciones, registra cotizaciones y genera la orden de compra'],
        ['inventario', 'compras', 'recepciones', 'confirmar', 'Confirmar entradas', 'Confirma recepciones capturadas en almacén; al confirmar suma stock'],
    ];

    public function up(): void
    {
        foreach (self::PERMISOS as [$app, $modulo, $sub, $slug, $nombre, $descripcion]) {
            $submodulos = DB::table('submodules as s')
                ->join('modules as m', 'm.id', '=', 's.module_id')
                ->join('applications as a', 'a.id', '=', 'm.application_id')
                ->where('a.slug', $app)->where('m.slug', $modulo)->where('s.slug', $sub)
                ->pluck('s.id');

            foreach ($submodulos as $submoduleId) {
                DB::table('submodule_permission_types')->insertOrIgnore([
                    'submodule_id' => $submoduleId,
                    'slug' => $slug,
                    'name' => $nombre,
                    'description' => $descripcion,
                    'color' => 'purple',
                    'order' => 80,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('approval_processes')->insertOrIgnore([
            'code' => 'purchase_orders',
            'name' => 'Órdenes de Compra',
            'description' => 'Autorización de órdenes de compra antes de ser enviadas a proveedores',
            'module' => 'splendidfarms/inventario',
            'entity_type' => 'App\\Models\\PurchaseOrder',
            'requires_approval' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('submodule_permission_types')->whereIn('slug', ['cotizar', 'confirmar'])->delete();
    }
};
