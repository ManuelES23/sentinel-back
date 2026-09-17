<?php

namespace App\Support\Inventario;

use Illuminate\Support\Facades\DB;

/**
 * Datos iniciales de la fase 1 de inventario agrícola:
 * - Registra el permiso `ver_todos_almacenes` en inventario/reportes/stock
 *   de cada empresa.
 * - Asigna a cada usuario con acceso a Inventario u Operación agrícola todos
 *   los almacenes que su empresa ve hoy, para que nadie pierda acceso al
 *   desplegar. Los administradores recortan después.
 * Consultas directas (sin modelos) para que la migración no dependa de
 * cambios futuros en los modelos. Ambos métodos son idempotentes.
 */
class AsignacionInicialAlmacenes
{
    public const APPS = ['inventario', 'operacion-agricola'];

    public function registrarPermiso(): int
    {
        $submodulos = DB::table('submodules as s')
            ->join('modules as m', 'm.id', '=', 's.module_id')
            ->join('applications as a', 'a.id', '=', 'm.application_id')
            ->where('a.slug', 'inventario')
            ->where('m.slug', 'reportes')
            ->where('s.slug', 'stock')
            ->pluck('s.id');

        $creados = 0;
        foreach ($submodulos as $submoduleId) {
            $creados += DB::table('submodule_permission_types')->insertOrIgnore([
                'submodule_id' => $submoduleId,
                'slug' => 'ver_todos_almacenes',
                'name' => 'Ver todos los almacenes',
                'description' => 'Ve inventario, movimientos y reportes de todos los almacenes de la empresa',
                'color' => 'blue',
                'order' => 90,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $creados;
    }

    public function asignar(): int
    {
        $porModulo = DB::table('user_module_access as uma')
            ->join('modules as m', 'm.id', '=', 'uma.module_id')
            ->join('applications as a', 'a.id', '=', 'm.application_id')
            ->where('uma.is_active', true)
            ->whereIn('a.slug', self::APPS)
            ->select('uma.user_id', 'a.enterprise_id');

        $pares = DB::table('user_submodule_access as usa')
            ->join('submodules as s', 's.id', '=', 'usa.submodule_id')
            ->join('modules as m', 'm.id', '=', 's.module_id')
            ->join('applications as a', 'a.id', '=', 'm.application_id')
            ->where('usa.is_active', true)
            ->whereIn('a.slug', self::APPS)
            ->select('usa.user_id', 'a.enterprise_id')
            ->union($porModulo)
            ->get();

        $insertadas = 0;
        foreach ($pares->groupBy('enterprise_id') as $empresaId => $filas) {
            $entidades = $this->entidadesDeEmpresa((int) $empresaId);
            foreach ($filas->pluck('user_id')->unique() as $userId) {
                foreach ($entidades as $entityId) {
                    $insertadas += DB::table('user_entity_access')->insertOrIgnore([
                        'user_id' => $userId,
                        'entity_id' => $entityId,
                        'enterprise_id' => $empresaId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        return $insertadas;
    }

    /**
     * @return array<int>
     */
    private function entidadesDeEmpresa(int $empresaId): array
    {
        $propias = DB::table('entities as e')
            ->join('branches as b', 'b.id', '=', 'e.branch_id')
            ->where('b.enterprise_id', $empresaId)
            ->whereNull('e.deleted_at')
            ->pluck('e.id');

        $vinculadas = DB::table('enterprise_entity as ee')
            ->join('entities as e', 'e.id', '=', 'ee.entity_id')
            ->where('ee.enterprise_id', $empresaId)
            ->whereNull('e.deleted_at')
            ->pluck('ee.entity_id');

        return $propias->merge($vinculadas)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
