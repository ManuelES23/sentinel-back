<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso `acordar` («Acordar plan») del submódulo «Tablero semanal» de
 * Planeación (Splendid Farms → Administración): quien lo tiene guarda la copia
 * del plan acordado en la junta. No crea un submódulo: agrega un tipo de permiso
 * al que ya existe.
 *
 * Lo reciben SOLO admin@ y demo@ (se reafirma en cada corrida); a los demás se
 * les concede a mano desde el panel. No reactiva a nadie más ni toca los accesos
 * de otros usuarios.
 *
 * Es idempotente y no hace nada si el módulo Planeación no existe todavía. Si
 * Planeación existe pero aún no existe «Tablero semanal» lanza una excepción
 * (debe correr después de 2026_11_11_100100_dividir_planeacion_en_submodulos): así
 * no queda anotada como corrida sin haber creado nada.
 */
return new class extends Migration
{
    private const SUBMODULO = 'tablero-semanal';

    private const PERMISO = 'acordar';

    public function up(): void
    {
        $moduloId = $this->modulo();
        if (! $moduloId) {
            return;
        }

        $tableroId = DB::table('submodules')->where('module_id', $moduloId)->where('slug', self::SUBMODULO)->value('id');
        if (! $tableroId) {
            // Un `return` silencioso dejaría esta migración anotada como corrida sin crear el permiso
            // (`migrate --path` ya no la repetiría), así que falla con un mensaje claro y no escribe.
            throw new RuntimeException(
                'Corre primero 2026_11_11_100100_dividir_planeacion_en_submodulos: el permiso «Acordar plan» va en el Tablero semanal.'
            );
        }
        $tableroId = (int) $tableroId;

        $tipoId = DB::table('submodule_permission_types')
            ->where('submodule_id', $tableroId)
            ->where('slug', self::PERMISO)
            ->value('id');
        if (! $tipoId) {
            $tipoId = DB::table('submodule_permission_types')->insertGetId([
                'submodule_id' => $tableroId,
                'slug' => self::PERMISO,
                'name' => 'Acordar plan',
                'description' => 'Guardar la copia del plan acordado en la junta',
                'order' => (int) DB::table('submodule_permission_types')->where('submodule_id', $tableroId)->max('order') + 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $tipoId = (int) $tipoId;

        // admin@ y demo@ reciben acceso y el permiso (se reafirman en cada corrida).
        $usuarios = DB::table('users')->whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])->pluck('id');
        foreach ($usuarios as $userId) {
            DB::table('user_module_access')->updateOrInsert(
                ['user_id' => $userId, 'module_id' => $moduloId],
                ['is_active' => true, 'granted_at' => now(), 'updated_at' => now(), 'created_at' => now()],
            );

            $acceso = DB::table('user_submodule_access')->where('user_id', $userId)->where('submodule_id', $tableroId);
            if ($acceso->exists()) {
                $acceso->update(['is_active' => true, 'granted_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('user_submodule_access')->insert([
                    'user_id' => $userId, 'submodule_id' => $tableroId,
                    'is_active' => true, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $permiso = DB::table('user_submodule_permissions')
                ->where('user_id', $userId)->where('submodule_id', $tableroId)->where('permission_type_id', $tipoId);
            if ($permiso->exists()) {
                $permiso->update(['is_granted' => true, 'updated_at' => now()]);
            } else {
                DB::table('user_submodule_permissions')->insert([
                    'user_id' => $userId, 'submodule_id' => $tableroId, 'permission_type_id' => $tipoId,
                    'is_granted' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /** Quita el permiso `acordar` con sus concesiones. */
    public function down(): void
    {
        $moduloId = $this->modulo();
        if (! $moduloId) {
            return;
        }

        $tableroId = DB::table('submodules')->where('module_id', $moduloId)->where('slug', self::SUBMODULO)->value('id');
        if (! $tableroId) {
            return;
        }

        $tipoId = DB::table('submodule_permission_types')
            ->where('submodule_id', $tableroId)
            ->where('slug', self::PERMISO)
            ->value('id');
        if (! $tipoId) {
            return;
        }

        DB::table('user_submodule_permissions')->where('permission_type_id', $tipoId)->delete();
        DB::table('submodule_permission_types')->where('id', $tipoId)->delete();
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
