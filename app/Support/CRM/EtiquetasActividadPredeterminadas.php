<?php

namespace App\Support\CRM;

use Illuminate\Support\Facades\DB;

/**
 * Las seis etiquetas de actividad con las que arranca cada empresa (spec
 * 2026-10-30 §6). Las usa CrmEtiquetasActividadSeeder (empresas nuevas); la
 * migración de datos lleva su propia copia de la lista. Son editables:
 * después de sembrarlas nadie las vuelve a tocar.
 */
final class EtiquetasActividadPredeterminadas
{
    public const LISTA = [
        ['nombre' => 'Primer contacto', 'orden' => 1],
        ['nombre' => 'Seguimiento', 'orden' => 2],
        ['nombre' => 'Cotización', 'orden' => 3],
        ['nombre' => 'Negociación', 'orden' => 4],
        ['nombre' => 'Cobranza', 'orden' => 5],
        ['nombre' => 'Rechazo', 'orden' => 6],
    ];

    /**
     * Siembra las etiquetas si la empresa no tiene ninguna (un catálogo ya
     * editado no se rehace ni se completa). Idempotente.
     */
    public static function sembrar(int $empresaId): void
    {
        if (DB::table('crm_etiquetas_actividad')->where('empresa_id', $empresaId)->exists()) {
            return;
        }

        $ahora = now();

        DB::table('crm_etiquetas_actividad')->insert(array_map(fn (array $etiqueta) => [
            'empresa_id' => $empresaId,
            'nombre' => $etiqueta['nombre'],
            'orden' => $etiqueta['orden'],
            'activo' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], self::LISTA));
    }
}
