<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Planeación deja de tener un solo submódulo «Programa semanal» y pasa a seis,
 * cada uno con sus permisos: Tablero semanal, Plan de corte, Necesidades de
 * venta, Embarques, Resumen de temporada y Configuración.
 *
 * Quien tenía permisos en «Programa semanal» recibe los equivalentes en los
 * nuevos (con su acceso al submódulo) y después «Programa semanal» se apaga sin
 * borrarse, para poder regresar con down(). admin@ y demo@ reciben todo.
 *
 * Es idempotente y no falla si Planeación no existe todavía (base donde la
 * entrega 1 no se ha desplegado): en ese caso no hay nada que dividir.
 */
return new class extends Migration
{
    private const VIEJO = 'programa-semanal';

    /** Orden de la lista = orden del menú. */
    private const SUBMODULOS = [
        'tablero-semanal' => [
            'name' => 'Tablero semanal',
            'description' => 'Tablero de la semana: cortes, ventas, embarques y balance por color',
            'icon' => 'LayoutDashboard',
            'permisos' => [
                ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver el tablero semanal'],
                ['slug' => 'editar-notas', 'name' => 'Editar notas', 'description' => 'Editar las notas de la semana y los acuerdos de la junta'],
            ],
        ],
        'plan-corte' => [
            'name' => 'Plan de corte',
            'description' => 'Hectáreas a cosechar por lote y etapa, y rendimiento de la semana',
            'icon' => 'Scissors',
            'permisos' => [
                ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver el plan de corte'],
                ['slug' => 'capturar', 'name' => 'Capturar', 'description' => 'Capturar las hectáreas a cosechar'],
            ],
        ],
        'necesidades-venta' => [
            'name' => 'Necesidades de venta',
            'description' => 'Cajas que necesita cada cliente por día',
            'icon' => 'ShoppingCart',
            'permisos' => [
                ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver las necesidades de venta'],
                ['slug' => 'capturar', 'name' => 'Capturar', 'description' => 'Capturar las necesidades de los clientes'],
            ],
        ],
        'embarques' => [
            'name' => 'Embarques',
            'description' => 'Número de cargas por bodega, tipo y día',
            'icon' => 'Truck',
            'permisos' => [
                ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver los embarques'],
                ['slug' => 'capturar', 'name' => 'Capturar', 'description' => 'Capturar las cargas de la semana'],
            ],
        ],
        'resumen-temporada' => [
            'name' => 'Resumen de temporada',
            'description' => 'Resumen semana a semana de toda la temporada',
            'icon' => 'ChartColumn',
            'permisos' => [
                ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver el resumen de temporada'],
            ],
        ],
        'configuracion' => [
            'name' => 'Configuración',
            'description' => 'Catálogos, grupos de balance, recetas y rendimientos',
            'icon' => 'Settings',
            'permisos' => [
                ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver la configuración de Planeación'],
                ['slug' => 'editar', 'name' => 'Editar', 'description' => 'Editar catálogos, grupos, recetas y rendimientos'],
            ],
        ],
    ];

    /** Permiso viejo de «programa-semanal» → [submódulo nuevo, permiso nuevo]. */
    private const MAPA = [
        'view' => [['tablero-semanal', 'view'], ['resumen-temporada', 'view']],
        'capturar-corte' => [['plan-corte', 'view'], ['plan-corte', 'capturar']],
        'capturar-ventas' => [['necesidades-venta', 'view'], ['necesidades-venta', 'capturar']],
        'capturar-logistica' => [['embarques', 'view'], ['embarques', 'capturar']],
        'configurar' => [['configuracion', 'view'], ['configuracion', 'editar']],
        'acuerdos' => [['tablero-semanal', 'editar-notas']],
    ];

    public function up(): void
    {
        $moduloId = $this->modulo();
        if (! $moduloId) {
            return;
        }

        // Submódulos y tipos de permiso: slug => id, y "submódulo/permiso" => id.
        $submoduloIds = [];
        $tipoIds = [];
        $orden = 0;
        foreach (self::SUBMODULOS as $slug => $def) {
            $orden++;
            $submoduloId = DB::table('submodules')->where('module_id', $moduloId)->where('slug', $slug)->value('id');
            if (! $submoduloId) {
                $submoduloId = DB::table('submodules')->insertGetId([
                    'module_id' => $moduloId,
                    'slug' => $slug,
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'icon' => $def['icon'],
                    'path' => null,
                    'order' => $orden,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $submoduloIds[$slug] = (int) $submoduloId;

            $ordenPermiso = 0;
            foreach ($def['permisos'] as $permiso) {
                $ordenPermiso++;
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
                        'order' => $ordenPermiso,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $tipoIds[$slug.'/'.$permiso['slug']] = (int) $tipoId;
            }
        }

        // Quien tenía permisos concedidos en «Programa semanal».
        $viejoId = DB::table('submodules')->where('module_id', $moduloId)->where('slug', self::VIEJO)->value('id');
        if ($viejoId) {
            // Solo cuenta quien conserva el acceso activo al submódulo viejo: si el
            // panel se lo desactivó, sus filas de permisos viejas no abren nada nuevo.
            $concedidos = DB::table('user_submodule_permissions')
                ->join('submodule_permission_types', 'submodule_permission_types.id', '=', 'user_submodule_permissions.permission_type_id')
                ->join('user_submodule_access', function ($join) {
                    $join->on('user_submodule_access.user_id', '=', 'user_submodule_permissions.user_id')
                        ->on('user_submodule_access.submodule_id', '=', 'user_submodule_permissions.submodule_id');
                })
                ->where('user_submodule_permissions.submodule_id', $viejoId)
                ->where('user_submodule_permissions.is_granted', true)
                ->where('user_submodule_access.is_active', true)
                ->get(['user_submodule_permissions.user_id', 'submodule_permission_types.slug as permiso']);

            foreach ($concedidos as $fila) {
                foreach (self::MAPA[$fila->permiso] ?? [] as [$submodulo, $permiso]) {
                    $this->conceder((int) $fila->user_id, $submoduloIds[$submodulo], $tipoIds[$submodulo.'/'.$permiso], false);
                }
            }
        }

        // admin@ y demo@ reciben todo.
        $usuarios = DB::table('users')->whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])->pluck('id');
        foreach ($usuarios as $userId) {
            DB::table('user_module_access')->updateOrInsert(
                ['user_id' => $userId, 'module_id' => $moduloId],
                ['is_active' => true, 'granted_at' => now(), 'updated_at' => now(), 'created_at' => now()],
            );
            foreach ($tipoIds as $clave => $tipoId) {
                $this->conceder((int) $userId, $submoduloIds[explode('/', $clave)[0]], $tipoId, true);
            }
        }

        // El submódulo viejo queda apagado, no borrado.
        DB::table('submodules')
            ->where('module_id', $moduloId)
            ->where('slug', self::VIEJO)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    /**
     * Reactiva «Programa semanal» y borra los seis con sus permisos y concesiones.
     * Los permisos viejos nunca se tocaron, así que siguen donde estaban.
     */
    public function down(): void
    {
        $moduloId = $this->modulo();
        if (! $moduloId) {
            return;
        }

        $submoduloIds = DB::table('submodules')
            ->where('module_id', $moduloId)
            ->whereIn('slug', array_keys(self::SUBMODULOS))
            ->pluck('id');

        DB::table('user_submodule_permissions')->whereIn('submodule_id', $submoduloIds)->delete();
        DB::table('user_submodule_access')->whereIn('submodule_id', $submoduloIds)->delete();
        DB::table('submodule_permission_types')->whereIn('submodule_id', $submoduloIds)->delete();
        DB::table('submodules')->whereIn('id', $submoduloIds)->delete();

        DB::table('submodules')
            ->where('module_id', $moduloId)
            ->where('slug', self::VIEJO)
            ->update(['is_active' => true, 'updated_at' => now()]);
    }

    /**
     * Acceso al submódulo y permiso concedido. Con `$forzar` (admin@ y demo@) se
     * deja siempre activo; sin él solo se crea lo que falta, para que una segunda
     * corrida no reactive lo que alguien revocó a mano en un submódulo nuevo.
     */
    private function conceder(int $userId, int $submoduloId, int $tipoId, bool $forzar): void
    {
        $acceso = DB::table('user_submodule_access')->where('user_id', $userId)->where('submodule_id', $submoduloId);
        if (! $acceso->exists()) {
            DB::table('user_submodule_access')->insert([
                'user_id' => $userId, 'submodule_id' => $submoduloId,
                'is_active' => true, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } elseif ($forzar) {
            $acceso->update(['is_active' => true, 'granted_at' => now(), 'updated_at' => now()]);
        }

        $permiso = DB::table('user_submodule_permissions')
            ->where('user_id', $userId)->where('submodule_id', $submoduloId)->where('permission_type_id', $tipoId);
        if (! $permiso->exists()) {
            DB::table('user_submodule_permissions')->insert([
                'user_id' => $userId, 'submodule_id' => $submoduloId, 'permission_type_id' => $tipoId,
                'is_granted' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } elseif ($forzar) {
            $permiso->update(['is_granted' => true, 'updated_at' => now()]);
        }
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
