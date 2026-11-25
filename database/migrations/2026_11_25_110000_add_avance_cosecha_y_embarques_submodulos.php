<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Submódulos «Avance de cosecha» (justo después de «Plan de corte») y «Avance de
 * embarques» (justo después de «Embarques») de Planeación (Splendid Farms →
 * Administración): los que les siguen se recorren una posición. Son de solo
 * lectura, cada uno con un único permiso `view`.
 *
 * Reciben acceso y `view`: admin@ y demo@ (siempre, como en las migraciones
 * anteriores) y quien ya tiene `tablero-semanal.view` concedido con el acceso
 * activo al Tablero semanal. Esta concesión solo crea filas que faltan: nunca
 * reactiva un acceso o permiso que alguien revocó a mano (is_active = 0 o
 * is_granted = 0 sobre el submódulo nuevo).
 *
 * Es idempotente (el recorrido de orden solo ocurre al crear cada submódulo) y no
 * hace nada si Planeación no existe todavía. Si Planeación existe pero falta
 * «Plan de corte» o «Embarques» lanza una excepción SIN escribir nada: así no queda
 * anotada como corrida sin haber creado los submódulos.
 *
 * Crea primero «Avance de cosecha»: eso recorre «Embarques», y después se lee el
 * orden ya recorrido de «Embarques» para colocar «Avance de embarques».
 */
return new class extends Migration
{
    /** En el orden en que se crean. */
    private const SUBMODULOS = [
        [
            'slug' => 'avance-cosecha',
            'despues_de' => 'plan-corte',
            'name' => 'Avance de cosecha',
            'description' => 'Cosecha real contra el plan de corte',
            'icon' => 'Wheat',
            'permiso_descripcion' => 'Ver el avance de cosecha',
        ],
        [
            'slug' => 'avance-embarques',
            'despues_de' => 'embarques',
            'name' => 'Avance de embarques',
            'description' => 'Embarques reales contra las cargas programadas',
            'icon' => 'Route',
            'permiso_descripcion' => 'Ver el avance de embarques',
        ],
    ];

    public function up(): void
    {
        $moduloId = $this->modulo();
        if (! $moduloId) {
            return;
        }

        // Valida todo antes de escribir: un `return` silencioso o una falla a medias dejaría esta
        // migración anotada como corrida sin crear nada (`migrate --path` ya no la repetiría).
        foreach (self::SUBMODULOS as $definicion) {
            $existe = DB::table('submodules')->where('module_id', $moduloId)->where('slug', $definicion['slug'])->exists();
            $ancla = DB::table('submodules')->where('module_id', $moduloId)->where('slug', $definicion['despues_de'])->exists();
            if (! $existe && ! $ancla) {
                throw new RuntimeException(
                    "Falta el submódulo {$definicion['despues_de']} de Planeación: {$definicion['name']} va justo después de él."
                );
            }
        }

        $usuarios = DB::table('users')->whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])->pluck('id');

        foreach (self::SUBMODULOS as $definicion) {
            $submoduloId = $this->submodulo($moduloId, $definicion);
            $tipoId = $this->permisoView($submoduloId, $definicion['permiso_descripcion']);

            $this->concederAAdminYDemo($moduloId, $submoduloId, $tipoId, $usuarios->all());
            $this->concederATablero($moduloId, $submoduloId, $tipoId, $usuarios->all());
        }
    }

    /** El submódulo existente o uno nuevo justo después de su ancla (los siguientes se recorren). */
    private function submodulo(int $moduloId, array $definicion): int
    {
        $submoduloId = DB::table('submodules')->where('module_id', $moduloId)->where('slug', $definicion['slug'])->value('id');
        if ($submoduloId) {
            return (int) $submoduloId;
        }

        $orden = (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', $definicion['despues_de'])->value('order') + 1;

        DB::table('submodules')
            ->where('module_id', $moduloId)
            ->where('order', '>=', $orden)
            ->increment('order');

        return (int) DB::table('submodules')->insertGetId([
            'module_id' => $moduloId,
            'slug' => $definicion['slug'],
            'name' => $definicion['name'],
            'description' => $definicion['description'],
            'icon' => $definicion['icon'],
            'path' => null,
            'order' => $orden,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function permisoView(int $submoduloId, string $descripcion): int
    {
        $tipoId = DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduloId)
            ->where('slug', 'view')
            ->value('id');
        if ($tipoId) {
            return (int) $tipoId;
        }

        return (int) DB::table('submodule_permission_types')->insertGetId([
            'submodule_id' => $submoduloId,
            'slug' => 'view',
            'name' => 'Ver',
            'description' => $descripcion,
            'order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** admin@ y demo@ reciben acceso y el permiso (se reafirman en cada corrida). */
    private function concederAAdminYDemo(int $moduloId, int $submoduloId, int $tipoId, array $usuarios): void
    {
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

    /**
     * Quien ya veía el Tablero semanal (`view` concedido y acceso activo al submódulo) recibe
     * acceso y `view` del submódulo nuevo. Solo se CREAN las filas que faltan: si ya existe
     * una (activa o revocada) no se toca, y el acceso al módulo existente tampoco.
     *
     * @param  list<int|string>  $yaAtendidos  admin@ y demo@, que ya se trataron
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

    /** Quita ambos submódulos con sus permisos y accesos y regresa los siguientes a su lugar. */
    public function down(): void
    {
        $moduloId = $this->modulo();
        if (! $moduloId) {
            return;
        }

        // Al revés de como se crearon, así cada recorrido regresa a su posición original.
        foreach (array_reverse(self::SUBMODULOS) as $definicion) {
            $submodulo = DB::table('submodules')->where('module_id', $moduloId)->where('slug', $definicion['slug'])->first(['id', 'order']);
            if (! $submodulo) {
                continue;
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
