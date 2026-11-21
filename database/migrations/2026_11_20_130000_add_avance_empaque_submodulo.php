<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Submódulo «Avance de empaque» de Planeación (Splendid Farms → Administración),
 * justo después de «Programa de empaque»: los submódulos que lo siguen se
 * recorren una posición. Es de solo lectura, con un único permiso `view`.
 *
 * Reciben acceso y `view`: admin@ y demo@ (siempre, como en las migraciones
 * anteriores) y quien ya tiene `tablero-semanal.view` concedido con el acceso
 * activo al Tablero semanal. Esta concesión solo crea filas que faltan: nunca
 * reactiva un acceso o permiso que alguien revocó a mano (is_active = 0 o
 * is_granted = 0 sobre «Avance de empaque»).
 *
 * Es idempotente (el recorrido de orden solo ocurre al crear el submódulo) y no
 * hace nada si Planeación no existe todavía. Si Planeación existe pero aún no
 * existe «Programa de empaque» lanza una excepción (debe correr después de
 * 2026_11_20_110100_add_programa_empaque_submodulo): así no queda anotada como
 * corrida sin haber creado nada.
 */
return new class extends Migration
{
    private const SLUG = 'avance-empaque';

    private const DESPUES_DE = 'programa-empaque';

    private const PERMISOS = [
        ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver el avance de empaque'],
    ];

    public function up(): void
    {
        $moduloId = $this->modulo();
        if (! $moduloId) {
            return;
        }

        $submoduloId = DB::table('submodules')->where('module_id', $moduloId)->where('slug', self::SLUG)->value('id');
        if (! $submoduloId) {
            // Sin «Programa de empaque» el submódulo caería al final del menú y los órdenes
            // quedarían repetidos cuando aquel se cree. Un `return` silencioso dejaría esta migración
            // anotada como corrida sin crear nada (`migrate --path` ya no la repetiría), así que falla.
            $orden = DB::table('submodules')->where('module_id', $moduloId)->where('slug', self::DESPUES_DE)->value('order');
            if ($orden === null) {
                throw new RuntimeException(
                    'Corre primero 2026_11_20_110100_add_programa_empaque_submodulo: Avance de empaque va después de Programa de empaque.'
                );
            }
            $orden = (int) $orden + 1;

            DB::table('submodules')
                ->where('module_id', $moduloId)
                ->where('order', '>=', $orden)
                ->increment('order');

            $submoduloId = DB::table('submodules')->insertGetId([
                'module_id' => $moduloId,
                'slug' => self::SLUG,
                'name' => 'Avance de empaque',
                'description' => 'Empaque real contra el programa',
                'icon' => 'ClipboardCheck',
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
            $tipoIds[$permiso['slug']] = (int) $tipoId;
        }

        // admin@ y demo@ reciben acceso y el permiso (se reafirman en cada corrida).
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

        $this->concederATablero($moduloId, $submoduloId, $tipoIds['view'], $usuarios->all());
    }

    /**
     * Quien ya veía el Tablero semanal (`view` concedido y acceso activo al submódulo) recibe
     * acceso y `view` de «Avance de empaque». Solo se CREAN las filas que faltan: si ya existe
     * una (activa o revocada) no se toca, y el acceso al módulo existente tampoco.
     *
     * @param  list<int|string>  $yaAtendidos  admin@ y demo@, que ya se trataron arriba
     */
    private function concederATablero(int $moduloId, int $submoduloId, int $tipoViewId, array $yaAtendidos): void
    {
        $tablero = DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'tablero-semanal')->first(['id']);
        if (! $tablero) {
            return;
        }
        $tipoTableroId = DB::table('submodule_permission_types')
            ->where('submodule_id', $tablero->id)
            ->where('slug', 'view')
            ->value('id');
        if (! $tipoTableroId) {
            return;
        }

        $usuarios = DB::table('user_submodule_permissions')
            ->where('submodule_id', $tablero->id)
            ->where('permission_type_id', $tipoTableroId)
            ->where('is_granted', true)
            ->whereIn('user_id', DB::table('user_submodule_access')
                ->where('submodule_id', $tablero->id)
                ->where('is_active', true)
                ->select('user_id'))
            ->pluck('user_id')
            ->unique()
            ->reject(fn ($id) => in_array($id, $yaAtendidos, false));

        foreach ($usuarios as $userId) {
            if (! DB::table('user_module_access')->where('user_id', $userId)->where('module_id', $moduloId)->exists()) {
                DB::table('user_module_access')->insert([
                    'user_id' => $userId, 'module_id' => $moduloId,
                    'is_active' => true, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            if (! DB::table('user_submodule_access')->where('user_id', $userId)->where('submodule_id', $submoduloId)->exists()) {
                DB::table('user_submodule_access')->insert([
                    'user_id' => $userId, 'submodule_id' => $submoduloId,
                    'is_active' => true, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            if (! DB::table('user_submodule_permissions')
                ->where('user_id', $userId)->where('submodule_id', $submoduloId)->where('permission_type_id', $tipoViewId)->exists()) {
                DB::table('user_submodule_permissions')->insert([
                    'user_id' => $userId, 'submodule_id' => $submoduloId, 'permission_type_id' => $tipoViewId,
                    'is_granted' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
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
