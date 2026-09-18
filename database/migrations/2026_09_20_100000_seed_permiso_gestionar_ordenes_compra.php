<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso `gestionar` en inventario > compras > órdenes-compra: equivalente
 * no agrícola de `cotizar` para el acceso de escritura a OC (algunas
 * empresas, como splendidbyporvenir, no tienen la app operacion-agricola,
 * así que `cotizar` es imposible de satisfacer ahí).
 */
return new class extends Migration
{
    private const PERMISOS = [
        ['inventario', 'compras', 'ordenes-compra', 'gestionar', 'Gestionar órdenes de compra', 'Crea, edita y gestiona órdenes de compra fuera del flujo de cotización agrícola'],
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
    }

    public function down(): void
    {
        DB::table('submodule_permission_types')->whereIn('slug', ['gestionar'])->delete();
    }
};
