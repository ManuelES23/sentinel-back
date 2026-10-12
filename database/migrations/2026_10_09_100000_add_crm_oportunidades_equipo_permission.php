<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permiso `oportunidades.equipo` del CRM Comercial: quien lo tiene lee las
 * oportunidades de cualquier vendedor; sin él, solo las de su propio
 * CrmVendedor. Las empresas nuevas lo reciben de CrmPermisosSeeder.
 *
 * Para no dejar a la gerencia actual sin ver al equipo en el despliegue, se
 * concede, en cada empresa por separado, a quien en el CRM de ESA empresa ya
 * tiene concedido alguno de:
 * - `oportunidades.cerrar` (solo lo da el perfil gerencia),
 * - `dashboard.ejecutivo` (ya ve las cifras de todo el equipo),
 * - `mi-dia.equipo` (ya ve el día de otros vendedores).
 *
 * Idempotente: el tipo y cada concesión se crean solo si no existen; una fila
 * `equipo` ya presente (aunque esté revocada, is_granted = false) no se toca.
 * Sin estructura CRM no hace nada. Cualquier otro caso se ajusta aplicando el
 * perfil o a mano.
 */
return new class extends Migration
{
    /** [módulo, submódulo, permiso] que ya equivalen a ver al equipo. */
    private const YA_VEN_AL_EQUIPO = [
        ['oportunidades', 'oportunidades', 'cerrar'],
        ['dashboard', 'dashboard', 'ejecutivo'],
        ['mi-dia', 'mi-dia', 'equipo'],
    ];

    public function up(): void
    {
        if (! $this->hayEstructura()) {
            return;
        }

        $ahora = now();

        // Un CRM (application slug=crm) por empresa: todo se resuelve dentro de él.
        $aplicaciones = DB::table('applications')->where('slug', 'crm')->pluck('id');

        foreach ($aplicaciones as $aplicacionId) {
            $submoduloId = DB::table('submodules')
                ->join('modules', 'modules.id', '=', 'submodules.module_id')
                ->where('modules.application_id', $aplicacionId)
                ->where('modules.slug', 'oportunidades')
                ->where('submodules.slug', 'oportunidades')
                ->value('submodules.id');

            if (! $submoduloId) {
                continue;
            }

            $equipoId = DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduloId)
                ->where('slug', 'equipo')
                ->value('id');

            if (! $equipoId) {
                $equipoId = DB::table('submodule_permission_types')->insertGetId([
                    'submodule_id' => $submoduloId,
                    'slug' => 'equipo',
                    'name' => 'Ver las oportunidades de otros vendedores',
                    'order' => 6,
                    'is_active' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }

            foreach ($this->usuariosQueYaVenAlEquipo($aplicacionId) as $userId) {
                $existe = DB::table('user_submodule_permissions')
                    ->where('user_id', $userId)
                    ->where('submodule_id', $submoduloId)
                    ->where('permission_type_id', $equipoId)
                    ->exists();

                if (! $existe) {
                    DB::table('user_submodule_permissions')->insert([
                        'user_id' => $userId,
                        'submodule_id' => $submoduloId,
                        'permission_type_id' => $equipoId,
                        'is_granted' => true,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                }
            }
        }
    }

    /**
     * Quita el tipo `equipo` del submódulo oportunidades de todos los CRM y
     * sus concesiones (también las que dieron después el seeder o un perfil).
     * No toca otros tipos `equipo` (mi-dia).
     */
    public function down(): void
    {
        if (! $this->hayEstructura()) {
            return;
        }

        $tipoIds = DB::table('submodule_permission_types')
            ->join('submodules', 'submodules.id', '=', 'submodule_permission_types.submodule_id')
            ->join('modules', 'modules.id', '=', 'submodules.module_id')
            ->join('applications', 'applications.id', '=', 'modules.application_id')
            ->where('applications.slug', 'crm')
            ->where('modules.slug', 'oportunidades')
            ->where('submodules.slug', 'oportunidades')
            ->where('submodule_permission_types.slug', 'equipo')
            ->pluck('submodule_permission_types.id');

        if ($tipoIds->isEmpty()) {
            return;
        }

        DB::table('user_submodule_permissions')->whereIn('permission_type_id', $tipoIds)->delete();
        DB::table('submodule_permission_types')->whereIn('id', $tipoIds)->delete();
    }

    /** Usuarios con alguno de YA_VEN_AL_EQUIPO concedido en ESTE CRM. */
    private function usuariosQueYaVenAlEquipo(int $aplicacionId): array
    {
        return DB::table('user_submodule_permissions')
            ->join('submodule_permission_types', 'submodule_permission_types.id', '=', 'user_submodule_permissions.permission_type_id')
            ->join('submodules', 'submodules.id', '=', 'user_submodule_permissions.submodule_id')
            ->join('modules', 'modules.id', '=', 'submodules.module_id')
            ->whereColumn('submodule_permission_types.submodule_id', 'user_submodule_permissions.submodule_id')
            ->where('modules.application_id', $aplicacionId)
            ->where('user_submodule_permissions.is_granted', true)
            ->where(function ($q) {
                foreach (self::YA_VEN_AL_EQUIPO as [$modulo, $submodulo, $permiso]) {
                    $q->orWhere(fn ($par) => $par
                        ->where('modules.slug', $modulo)
                        ->where('submodules.slug', $submodulo)
                        ->where('submodule_permission_types.slug', $permiso));
                }
            })
            ->distinct()
            ->orderBy('user_submodule_permissions.user_id')
            ->pluck('user_submodule_permissions.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function hayEstructura(): bool
    {
        foreach (['applications', 'modules', 'submodules', 'submodule_permission_types', 'user_submodule_permissions'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                return false;
            }
        }

        return true;
    }
};
