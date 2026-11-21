<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso `enviar` («Enviar por correo») del submódulo «Tablero semanal» de
 * Planeación (Splendid Farms → Administración): quien lo tiene manda el
 * programa de la semana por correo en Excel. No crea un submódulo: agrega un
 * tipo de permiso al que ya existe.
 *
 * Lo reciben: admin@ y demo@ (siempre, se reafirma en cada corrida) y quien
 * tiene `configuracion.editar` concedido con acceso activo a «Configuración»
 * (es quien captura la lista de destinatarios). Esta concesión solo crea
 * filas que faltan: nunca reactiva un permiso `enviar` que alguien revocó a
 * mano (is_granted = 0) ni toca los accesos de los demás.
 *
 * Es idempotente y no hace nada si Planeación o «Tablero semanal» no existen
 * todavía.
 */
return new class extends Migration
{
    private const SUBMODULO = 'tablero-semanal';

    private const PERMISO = 'enviar';

    public function up(): void
    {
        $moduloId = $this->modulo();
        if (! $moduloId) {
            return;
        }

        $tableroId = DB::table('submodules')->where('module_id', $moduloId)->where('slug', self::SUBMODULO)->value('id');
        if (! $tableroId) {
            return;
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
                'name' => 'Enviar por correo',
                'description' => 'Enviar el programa de la semana por correo',
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

        $this->concederAQuienConfigura($moduloId, $tableroId, $tipoId, $usuarios->all());
    }

    /**
     * Quien ya edita la Configuración (`editar` concedido y acceso activo al submódulo) recibe
     * `enviar`. Solo se CREA la fila que falta: una existente (concedida o revocada) no se toca.
     *
     * @param  list<int|string>  $yaAtendidos  admin@ y demo@, que ya se trataron arriba
     */
    private function concederAQuienConfigura(int $moduloId, int $tableroId, int $tipoId, array $yaAtendidos): void
    {
        $configuracionId = DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'configuracion')->value('id');
        if (! $configuracionId) {
            return;
        }
        $tipoEditarId = DB::table('submodule_permission_types')
            ->where('submodule_id', $configuracionId)
            ->where('slug', 'editar')
            ->value('id');
        if (! $tipoEditarId) {
            return;
        }

        $usuarios = DB::table('user_submodule_permissions')
            ->where('submodule_id', $configuracionId)
            ->where('permission_type_id', $tipoEditarId)
            ->where('is_granted', true)
            ->whereIn('user_id', DB::table('user_submodule_access')
                ->where('submodule_id', $configuracionId)
                ->where('is_active', true)
                ->select('user_id'))
            ->pluck('user_id')
            ->unique()
            ->reject(fn ($id) => in_array($id, $yaAtendidos, false));

        foreach ($usuarios as $userId) {
            if (! DB::table('user_submodule_permissions')
                ->where('user_id', $userId)->where('submodule_id', $tableroId)->where('permission_type_id', $tipoId)->exists()) {
                DB::table('user_submodule_permissions')->insert([
                    'user_id' => $userId, 'submodule_id' => $tableroId, 'permission_type_id' => $tipoId,
                    'is_granted' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /** Quita el permiso `enviar` con sus concesiones. */
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
