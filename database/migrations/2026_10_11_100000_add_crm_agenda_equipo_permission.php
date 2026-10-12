<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permiso `agenda.equipo` del CRM Comercial: quien lo tiene consulta y asigna
 * la agenda de cualquier vendedor; sin él, solo la de su propio CrmVendedor
 * activo. Las empresas nuevas lo reciben de CrmPermisosSeeder.
 *
 * En cada empresa por separado (dentro de SU aplicación crm):
 * 1. Crea el tipo `equipo` del submódulo agenda y lo concede a quien ya tiene
 *    concedido `agenda.eliminar` (solo lo da el perfil gerencia),
 *    `mi-dia.equipo` u `oportunidades.equipo` (ya ven al equipo).
 * 2. Concede `oportunidades.equipo` a quien tiene concedido
 *    `cotizaciones.aprobar` o `cotizaciones.rechazar`: aprobar o rechazar
 *    exige ahora poder ver la oportunidad, y así un aprobador configurado a
 *    mano no se queda bloqueado. Corre DESPUÉS del paso 1, para que ese
 *    equipo recién concedido no arrastre también el de agenda.
 *
 * Idempotente: el tipo y cada concesión se crean solo si no existen; una fila
 * `equipo` ya presente (aunque esté revocada, is_granted = false) no se toca.
 * Sin estructura CRM no hace nada.
 */
return new class extends Migration
{
    /** [módulo, submódulo, permiso] que ya equivalen a ver la agenda del equipo. */
    private const YA_VEN_LA_AGENDA_DEL_EQUIPO = [
        ['agenda', 'agenda', 'eliminar'],
        ['mi-dia', 'mi-dia', 'equipo'],
        ['oportunidades', 'oportunidades', 'equipo'],
    ];

    /** [módulo, submódulo, permiso] que necesitan ver las oportunidades de todo el equipo. */
    private const APRUEBAN_COTIZACIONES = [
        ['cotizaciones', 'cotizaciones', 'aprobar'],
        ['cotizaciones', 'cotizaciones', 'rechazar'],
    ];

    public function up(): void
    {
        if (! $this->hayEstructura()) {
            return;
        }

        $ahora = now();

        foreach (DB::table('applications')->where('slug', 'crm')->pluck('id') as $aplicacionId) {
            $aplicacionId = (int) $aplicacionId;

            $agendaId = $this->submodulo($aplicacionId, 'agenda', 'agenda');
            if ($agendaId) {
                $equipoAgenda = DB::table('submodule_permission_types')
                    ->where('submodule_id', $agendaId)
                    ->where('slug', 'equipo')
                    ->value('id');

                if (! $equipoAgenda) {
                    $equipoAgenda = DB::table('submodule_permission_types')->insertGetId([
                        'submodule_id' => $agendaId,
                        'slug' => 'equipo',
                        'name' => 'Ver la agenda de otros vendedores',
                        'order' => 5,
                        'is_active' => true,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                }

                $this->conceder(
                    $this->usuariosCon($aplicacionId, self::YA_VEN_LA_AGENDA_DEL_EQUIPO),
                    $agendaId,
                    (int) $equipoAgenda,
                    $ahora,
                );
            }

            // El tipo oportunidades.equipo lo crea la migración 2026_10_09_100000;
            // si esta empresa no lo tiene, no se inventa aquí.
            $oportunidadesId = $this->submodulo($aplicacionId, 'oportunidades', 'oportunidades');
            $equipoOportunidades = $oportunidadesId
                ? DB::table('submodule_permission_types')
                    ->where('submodule_id', $oportunidadesId)
                    ->where('slug', 'equipo')
                    ->value('id')
                : null;

            if ($equipoOportunidades) {
                $this->conceder(
                    $this->usuariosCon($aplicacionId, self::APRUEBAN_COTIZACIONES),
                    (int) $oportunidadesId,
                    (int) $equipoOportunidades,
                    $ahora,
                );
            }
        }
    }

    /**
     * Quita el tipo `equipo` del submódulo agenda de todos los CRM y sus
     * concesiones (también las que dieron después el seeder, un perfil o un
     * administrador). Las concesiones de `oportunidades.equipo` hechas por
     * up() se quedan: no se distinguen de las demás y son inofensivas.
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
            ->where('modules.slug', 'agenda')
            ->where('submodules.slug', 'agenda')
            ->where('submodule_permission_types.slug', 'equipo')
            ->pluck('submodule_permission_types.id');

        if ($tipoIds->isEmpty()) {
            return;
        }

        DB::table('user_submodule_permissions')->whereIn('permission_type_id', $tipoIds)->delete();
        DB::table('submodule_permission_types')->whereIn('id', $tipoIds)->delete();
    }

    private function submodulo(int $aplicacionId, string $modulo, string $submodulo): ?int
    {
        $id = DB::table('submodules')
            ->join('modules', 'modules.id', '=', 'submodules.module_id')
            ->where('modules.application_id', $aplicacionId)
            ->where('modules.slug', $modulo)
            ->where('submodules.slug', $submodulo)
            ->value('submodules.id');

        return $id ? (int) $id : null;
    }

    /** Inserta la concesión solo a quien no tiene ya una fila (concedida o revocada). */
    private function conceder(array $usuarios, int $submoduloId, int $tipoId, $ahora): void
    {
        foreach ($usuarios as $userId) {
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
    }

    /**
     * Usuarios con alguno de los permisos dados CONCEDIDO en ESTE CRM.
     *
     * @param  array<int, array{0: string, 1: string, 2: string}>  $permisos
     * @return int[]
     */
    private function usuariosCon(int $aplicacionId, array $permisos): array
    {
        return DB::table('user_submodule_permissions')
            ->join('submodule_permission_types', 'submodule_permission_types.id', '=', 'user_submodule_permissions.permission_type_id')
            ->join('submodules', 'submodules.id', '=', 'user_submodule_permissions.submodule_id')
            ->join('modules', 'modules.id', '=', 'submodules.module_id')
            ->whereColumn('submodule_permission_types.submodule_id', 'user_submodule_permissions.submodule_id')
            ->where('modules.application_id', $aplicacionId)
            ->where('user_submodule_permissions.is_granted', true)
            ->where(function ($q) use ($permisos) {
                foreach ($permisos as [$modulo, $submodulo, $permiso]) {
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
