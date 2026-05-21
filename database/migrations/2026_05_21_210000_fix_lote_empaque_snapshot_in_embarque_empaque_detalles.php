<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sanea snapshots historicos:
        // - lote_producto_terminado desde produccion_empaque.lote_producto_terminado
        // - lote (lote empaque) desde subentradas de recepcion o, en su defecto, lote del proceso
        DB::statement(<<<'SQL'
            UPDATE embarque_empaque_detalles ed
            INNER JOIN produccion_empaque pe ON pe.id = ed.produccion_id
            LEFT JOIN proceso_empaque pr ON pr.id = pe.proceso_id
            LEFT JOIN lotes l ON l.id = pr.lote_id
            LEFT JOIN (
                SELECT
                    pd.produccion_id,
                    GROUP_CONCAT(DISTINCT r.lote_producto_terminado ORDER BY r.lote_producto_terminado SEPARATOR ',') AS lote_empaque_sub
                FROM produccion_empaque_detalles pd
                INNER JOIN proceso_empaque p2 ON p2.id = pd.proceso_id
                INNER JOIN recepciones_empaque r ON r.id = p2.recepcion_id
                WHERE r.lote_producto_terminado IS NOT NULL
                  AND r.lote_producto_terminado <> ''
                GROUP BY pd.produccion_id
            ) subs ON subs.produccion_id = pe.id
            SET
                ed.lote_producto_terminado = COALESCE(NULLIF(pe.lote_producto_terminado, ''), ed.lote_producto_terminado),
                ed.lote = COALESCE(
                    NULLIF(subs.lote_empaque_sub, ''),
                    NULLIF(CAST(l.numero_lote AS CHAR), ''),
                    NULLIF(l.nombre, ''),
                    ed.lote
                )
        SQL);
    }

    public function down(): void
    {
        // Migracion de saneamiento de datos: no reversible de forma segura.
    }
};
