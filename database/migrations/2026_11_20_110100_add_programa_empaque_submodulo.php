<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Submódulo «Programa de empaque» de Planeación (Splendid Farms → Administración),
 * justo después de «Necesidades de venta»: los submódulos que lo siguen se
 * recorren una posición. admin@ y demo@ reciben acceso y los dos permisos.
 *
 * Es idempotente (el recorrido de orden solo ocurre al crear el submódulo) y no
 * hace nada si Planeación no existe todavía o si aún no existe «Necesidades de
 * venta» (debe correr después de 2026_11_11_100100_dividir_planeacion_en_submodulos).
 */
return new class extends Migration
{
    private const SLUG = 'programa-empaque';

    private const DESPUES_DE = 'necesidades-venta';

    private const PERMISOS = [
        ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver el programa de empaque'],
        ['slug' => 'capturar', 'name' => 'Capturar', 'description' => 'Capturar el empaque del sobrante'],
    ];

    public function up(): void
    {
        $moduloId = $this->modulo();
        if (! $moduloId) {
            return;
        }

        $submoduloId = DB::table('submodules')->where('module_id', $moduloId)->where('slug', self::SLUG)->value('id');
        if (! $submoduloId) {
            // Debe correr DESPUÉS de 2026_11_11_100100_dividir_planeacion_en_submodulos: sin «Necesidades
            // de venta» el submódulo caería al final del menú y la división, al insertar sus seis con
            // order 1..6, dejaría órdenes repetidos. Sin él no se hace nada (se vuelve a correr después).
            $orden = DB::table('submodules')->where('module_id', $moduloId)->where('slug', self::DESPUES_DE)->value('order');
            if ($orden === null) {
                return;
            }
            $orden = (int) $orden + 1;

            DB::table('submodules')
                ->where('module_id', $moduloId)
                ->where('order', '>=', $orden)
                ->increment('order');

            $submoduloId = DB::table('submodules')->insertGetId([
                'module_id' => $moduloId,
                'slug' => self::SLUG,
                'name' => 'Programa de empaque',
                'description' => 'Empaque y destino del sobrante de la semana',
                'icon' => 'PackageOpen',
                'path' => null,
                'order' => $orden,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $submoduloId = (int) $submoduloId;

        $tipoIds = [];
        foreach (self::PERMISOS as $indice => $permiso) {
            $tipoId = DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduloId)
                ->where('slug', $permiso['slug'])
                ->value('id');
            if (! $tipoId) {
                $tipoId = DB::table('submodule_permission_types')->insertGetId([
                    'submodule_id' => $submoduloId,
                    'slug' => $permiso['slug'],
                    'name' => $permiso['name'],
                    'description' => $permiso['description'],
                    'order' => $indice + 1,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $tipoIds[] = (int) $tipoId;
        }

        // admin@ y demo@ reciben acceso y los dos permisos.
        $usuarios = DB::table('users')->whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])->pluck('id');
        foreach ($usuarios as $userId) {
            DB::table('user_module_access')->updateOrInsert(
                ['user_id' => $userId, 'module_id' => $moduloId],
                ['is_active' => true, 'granted_at' => now(), 'updated_at' => now(), 'created_at' => now()],
            );

            $acceso = DB::table('user_submodule_access')->where('user_id', $userId)->where('submodule_id', $submoduloId);
            if ($acceso->exists()) {
                $acceso->update(['is_active' => true, 'granted_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('user_submodule_access')->insert([
                    'user_id' => $userId, 'submodule_id' => $submoduloId,
                    'is_active' => true, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            foreach ($tipoIds as $tipoId) {
                $permiso = DB::table('user_submodule_permissions')
                    ->where('user_id', $userId)->where('submodule_id', $submoduloId)->where('permission_type_id', $tipoId);
                if ($permiso->exists()) {
                    $permiso->update(['is_granted' => true, 'updated_at' => now()]);
                } else {
                    DB::table('user_submodule_permissions')->insert([
                        'user_id' => $userId, 'submodule_id' => $submoduloId, 'permission_type_id' => $tipoId,
                        'is_granted' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /** Quita el submódulo con sus permisos y accesos y regresa los siguientes a su lugar. */
    public function down(): void
    {
        $moduloId = $this->modulo();
        if (! $moduloId) {
            return;
        }

        $submodulo = DB::table('submodules')->where('module_id', $moduloId)->where('slug', self::SLUG)->first(['id', 'order']);
        if (! $submodulo) {
            return;
        }

        DB::table('user_submodule_permissions')->where('submodule_id', $submodulo->id)->delete();
        DB::table('user_submodule_access')->where('submodule_id', $submodulo->id)->delete();
        DB::table('submodule_permission_types')->where('submodule_id', $submodulo->id)->delete();
        DB::table('submodules')->where('id', $submodulo->id)->delete();

        DB::table('submodules')
            ->where('module_id', $moduloId)
            ->where('order', '>', $submodulo->order)
            ->decrement('order');
    }

    /**
     * Módulo «planeacion» de Splendid Farms. Vive en Administración; si todavía
     * estuviera en Operación Agrícola (movimiento sin correr) también se encuentra.
     */
    private function modulo(): ?int
    {
        $moduloId = DB::table('modules')
            ->join('applications', 'applications.id', '=', 'modules.application_id')
            ->join('enterprises', 'enterprises.id', '=', 'applications.enterprise_id')
            ->where('enterprises.slug', 'splendidfarms')
            ->where('modules.slug', 'planeacion')
            ->orderByRaw("case when applications.slug = 'administration' then 0 else 1 end")
            ->value('modules.id');

        return $moduloId ? (int) $moduloId : null;
    }
};
