<?php

use App\Support\CRM\CategoriasRechazoPredeterminadas;
use App\Support\CRM\EmpresasCrm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM · categorías de rechazo (spec 2026-10-23 §4.5): submódulo
 * `catalogos.categorias-rechazo` en cada aplicación crm ya sembrada. Las
 * empresas nuevas lo reciben de CrmPermisosSeeder.
 *
 * En cada CRM por separado:
 * 1. Crea el submódulo y sus tipos ver/crear/editar/eliminar si faltan.
 * 2. A quien ya tiene CONCEDIDO `catalogos.configuracion-comercial.editar`
 *    le concede los 4 permisos y el acceso al submódulo (sin el acceso no ve
 *    la entrada del menú). Solo inserta filas nuevas: una concesión o un
 *    acceso ya presentes, aunque estén revocados, no se tocan.
 *
 * Además siembra el catálogo SOLO en Splendid by Porvenir (por slug; si no
 * existe no siembra nada). Las demás empresas quedan sin catálogo y su modal
 * de pérdida sigue como hoy.
 *
 * Idempotente. Sin estructura CRM no hace nada.
 */
return new class extends Migration
{
    private const SUBMODULO = 'categorias-rechazo';

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

        foreach (DB::table('applications')->where('slug', 'crm')->get(['id', 'enterprise_id']) as $aplicacion) {
            $catalogosId = DB::table('modules')
                ->where('application_id', $aplicacion->id)
                ->where('slug', 'catalogos')
                ->value('id');

            if (! $catalogosId) {
                continue;
            }

            $submoduloId = DB::table('submodules')
                ->where('module_id', $catalogosId)
                ->where('slug', self::SUBMODULO)
                ->value('id');

            if (! $submoduloId) {
                $submoduloId = DB::table('submodules')->insertGetId([
                    'module_id' => $catalogosId,
                    'slug' => self::SUBMODULO,
                    'name' => 'Categorías de rechazo',
                    'icon' => 'ThumbsDown',
                    'order' => 8,
                    'is_active' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }

            $tipoIds = [];
            foreach (self::PERMISOS as $slug => [$nombre, $orden]) {
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

                $tipoIds[] = (int) $tipoId;
            }

            foreach ($this->editoresDeConfiguracionComercial((int) $catalogosId) as $userId) {
                $this->darAcceso($userId, (int) $submoduloId, $ahora);
                foreach ($tipoIds as $tipoId) {
                    $this->conceder($userId, (int) $submoduloId, $tipoId, $ahora);
                }
            }
        }

        // Solo Splendid by Porvenir arranca con catálogo (por slug, nunca por id).
        $sbpId = EmpresasCrm::idPorSlug(EmpresasCrm::SLUG_SBP);
        if ($sbpId !== null) {
            CategoriasRechazoPredeterminadas::sembrar($sbpId);
        }
    }

    /**
     * Quita el submódulo de todos los CRM con sus tipos, concesiones y accesos
     * (también los que dieron después el seeder, un perfil o un administrador).
     * No borra categorías: las tablas las quita 2026_10_23_100200.
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

        if ($submoduloIds->isEmpty()) {
            return;
        }

        DB::table('user_submodule_permissions')->whereIn('submodule_id', $submoduloIds)->delete();
        DB::table('user_submodule_access')->whereIn('submodule_id', $submoduloIds)->delete();
        DB::table('submodule_permission_types')->whereIn('submodule_id', $submoduloIds)->delete();
        DB::table('submodules')->whereIn('id', $submoduloIds)->delete();
    }

    /** @return int[] usuarios con catalogos.configuracion-comercial.editar CONCEDIDO en este CRM */
    private function editoresDeConfiguracionComercial(int $catalogosId): array
    {
        return DB::table('user_submodule_permissions')
            ->join('submodule_permission_types', 'submodule_permission_types.id', '=', 'user_submodule_permissions.permission_type_id')
            ->join('submodules', 'submodules.id', '=', 'user_submodule_permissions.submodule_id')
            ->whereColumn('submodule_permission_types.submodule_id', 'user_submodule_permissions.submodule_id')
            ->where('submodules.module_id', $catalogosId)
            ->where('submodules.slug', 'configuracion-comercial')
            ->where('submodule_permission_types.slug', 'editar')
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
        foreach (['applications', 'modules', 'submodules', 'submodule_permission_types', 'user_submodule_permissions', 'user_submodule_access', 'crm_categorias_rechazo', 'crm_motivos_rechazo'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                return false;
            }
        }

        return true;
    }
};
