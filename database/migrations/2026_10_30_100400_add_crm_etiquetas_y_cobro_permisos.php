<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM · la oportunidad como eje (spec 2026-10-30 §9). Dos novedades de
 * permisos en cada aplicación crm ya sembrada; las empresas nuevas las
 * reciben de CrmPermisosSeeder (mantener ambos iguales):
 *
 * 1. Submódulo `catalogos.etiquetas-actividad` con sus tipos ver/crear/editar/
 *    eliminar. A quien ya tiene CONCEDIDO `catalogos.configuracion-comercial.editar`
 *    le concede los 4 permisos y el acceso al submódulo (sin el acceso no ve la
 *    entrada del menú).
 * 2. Tipo `oportunidades.oportunidades.cobro` («Marcar cobro»), concedido a quien
 *    ya tiene CONCEDIDO `oportunidades.oportunidades.cerrar` (la gerencia).
 *
 * Solo inserta filas nuevas: una concesión o un acceso ya presentes, aunque
 * estén revocados, no se tocan. Idempotente. Sin estructura CRM no hace nada.
 */
return new class extends Migration
{
    private const SUBMODULO = 'etiquetas-actividad';

    /** slug => [nombre, orden] (los mismos de CrmPermisosSeeder::permisosBase()). */
    private const PERMISOS = [
        'ver' => ['Ver', 1],
        'crear' => ['Crear', 2],
        'editar' => ['Editar', 3],
        'eliminar' => ['Eliminar', 4],
    ];

    public function up(): void
    {
        if (! $this->hayEstructura()) {
            return;
        }

        $ahora = now();

        foreach (DB::table('applications')->where('slug', 'crm')->pluck('id') as $aplicacionId) {
            $this->crearCatalogo((int) $aplicacionId, $ahora);
            $this->crearCobro((int) $aplicacionId, $ahora);
        }
    }

    /**
     * Quita lo que creó `up()` en todos los CRM: el submódulo con sus tipos,
     * concesiones y accesos, y el tipo `cobro` con sus concesiones (también las
     * que dieron después el seeder, un perfil o un administrador).
     */
    public function down(): void
    {
        if (! $this->hayEstructura()) {
            return;
        }

        $submoduloIds = DB::table('submodules')
            ->join('modules', 'modules.id', '=', 'submodules.module_id')
            ->join('applications', 'applications.id', '=', 'modules.application_id')
            ->where('applications.slug', 'crm')
            ->where('modules.slug', 'catalogos')
            ->where('submodules.slug', self::SUBMODULO)
            ->pluck('submodules.id');

        if ($submoduloIds->isNotEmpty()) {
            DB::table('user_submodule_permissions')->whereIn('submodule_id', $submoduloIds)->delete();
            DB::table('user_submodule_access')->whereIn('submodule_id', $submoduloIds)->delete();
            DB::table('submodule_permission_types')->whereIn('submodule_id', $submoduloIds)->delete();
            DB::table('submodules')->whereIn('id', $submoduloIds)->delete();
        }

        $cobroIds = DB::table('submodule_permission_types')
            ->join('submodules', 'submodules.id', '=', 'submodule_permission_types.submodule_id')
            ->join('modules', 'modules.id', '=', 'submodules.module_id')
            ->join('applications', 'applications.id', '=', 'modules.application_id')
            ->where('applications.slug', 'crm')
            ->where('modules.slug', 'oportunidades')
            ->where('submodules.slug', 'oportunidades')
            ->where('submodule_permission_types.slug', 'cobro')
            ->pluck('submodule_permission_types.id');

        if ($cobroIds->isNotEmpty()) {
            DB::table('user_submodule_permissions')->whereIn('permission_type_id', $cobroIds)->delete();
            DB::table('submodule_permission_types')->whereIn('id', $cobroIds)->delete();
        }
    }

    private function crearCatalogo(int $aplicacionId, $ahora): void
    {
        $catalogosId = DB::table('modules')
            ->where('application_id', $aplicacionId)
            ->where('slug', 'catalogos')
            ->value('id');

        if (! $catalogosId) {
            return;
        }

        $submoduloId = DB::table('submodules')
            ->where('module_id', $catalogosId)
            ->where('slug', self::SUBMODULO)
            ->value('id');

        if (! $submoduloId) {
            $submoduloId = DB::table('submodules')->insertGetId([
                'module_id' => $catalogosId,
                'slug' => self::SUBMODULO,
                'name' => 'Etiquetas de actividad',
                'icon' => 'Tag',
                'order' => 9,
                'is_active' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        $tipoIds = [];
        foreach (self::PERMISOS as $slug => [$nombre, $orden]) {
            $tipoIds[] = $this->tipoId((int) $submoduloId, $slug, $nombre, $orden, $ahora);
        }

        foreach ($this->usuariosConConcesion($aplicacionId, 'catalogos', 'configuracion-comercial', 'editar') as $userId) {
            $this->darAcceso($userId, (int) $submoduloId, $ahora);
            foreach ($tipoIds as $tipoId) {
                $this->conceder($userId, (int) $submoduloId, $tipoId, $ahora);
            }
        }
    }

    private function crearCobro(int $aplicacionId, $ahora): void
    {
        $submoduloId = DB::table('submodules')
            ->join('modules', 'modules.id', '=', 'submodules.module_id')
            ->where('modules.application_id', $aplicacionId)
            ->where('modules.slug', 'oportunidades')
            ->where('submodules.slug', 'oportunidades')
            ->value('submodules.id');

        if (! $submoduloId) {
            return;
        }

        $cobroId = $this->tipoId((int) $submoduloId, 'cobro', 'Marcar cobro', 7, $ahora);

        foreach ($this->usuariosConConcesion($aplicacionId, 'oportunidades', 'oportunidades', 'cerrar') as $userId) {
            $this->conceder($userId, (int) $submoduloId, $cobroId, $ahora);
        }
    }

    /** Id del tipo de permiso del submódulo; lo crea si falta. */
    private function tipoId(int $submoduloId, string $slug, string $nombre, int $orden, $ahora): int
    {
        $tipoId = DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduloId)
            ->where('slug', $slug)
            ->value('id');

        if (! $tipoId) {
            $tipoId = DB::table('submodule_permission_types')->insertGetId([
                'submodule_id' => $submoduloId,
                'slug' => $slug,
                'name' => $nombre,
                'order' => $orden,
                'is_active' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        return (int) $tipoId;
    }

    /** @return int[] usuarios con el permiso CONCEDIDO en ESTE CRM */
    private function usuariosConConcesion(int $aplicacionId, string $modulo, string $submodulo, string $permiso): array
    {
        return DB::table('user_submodule_permissions')
            ->join('submodule_permission_types', 'submodule_permission_types.id', '=', 'user_submodule_permissions.permission_type_id')
            ->join('submodules', 'submodules.id', '=', 'user_submodule_permissions.submodule_id')
            ->join('modules', 'modules.id', '=', 'submodules.module_id')
            ->whereColumn('submodule_permission_types.submodule_id', 'user_submodule_permissions.submodule_id')
            ->where('modules.application_id', $aplicacionId)
            ->where('modules.slug', $modulo)
            ->where('submodules.slug', $submodulo)
            ->where('submodule_permission_types.slug', $permiso)
            ->where('user_submodule_permissions.is_granted', true)
            ->distinct()
            ->orderBy('user_submodule_permissions.user_id')
            ->pluck('user_submodule_permissions.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Inserta la concesión solo si no hay fila (concedida o revocada). */
    private function conceder(int $userId, int $submoduloId, int $tipoId, $ahora): void
    {
        $existe = DB::table('user_submodule_permissions')
            ->where('user_id', $userId)
            ->where('submodule_id', $submoduloId)
            ->where('permission_type_id', $tipoId)
            ->exists();

        if (! $existe) {
            DB::table('user_submodule_permissions')->insert([
                'user_id' => $userId,
                'submodule_id' => $submoduloId,
                'permission_type_id' => $tipoId,
                'is_granted' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    /** Inserta el acceso al submódulo (entrada del menú) solo si no hay fila. */
    private function darAcceso(int $userId, int $submoduloId, $ahora): void
    {
        $existe = DB::table('user_submodule_access')
            ->where('user_id', $userId)
            ->where('submodule_id', $submoduloId)
            ->exists();

        if (! $existe) {
            DB::table('user_submodule_access')->insert([
                'user_id' => $userId,
                'submodule_id' => $submoduloId,
                'is_active' => true,
                'granted_at' => $ahora,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    private function hayEstructura(): bool
    {
        foreach (['applications', 'modules', 'submodules', 'submodule_permission_types', 'user_submodule_permissions', 'user_submodule_access'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                return false;
            }
        }

        return true;
    }
};
