<?php

namespace App\Support\CRM;

use Illuminate\Support\Facades\DB;

/**
 * Las 4 plantillas que se siembran por empresa (spec 2026-10-12 §4.3). Las
 * usan la migración de datos (empresas existentes) y CrmPlantillasWhatsappSeeder
 * (empresas nuevas). Son editables: después de sembrarlas nadie las vuelve a tocar.
 */
final class PlantillasWhatsappPredeterminadas
{
    public const PLANTILLAS = [
        [
            'nombre' => 'Seguimiento',
            'orden' => 1,
            'cuerpo' => 'Hola {{nombre}}, soy {{vendedor}} de {{empresa}}. ¿Pudiste revisar lo que platicamos? Quedo atento a tus comentarios.',
        ],
        [
            'nombre' => 'Envío de cotización',
            'orden' => 2,
            'cuerpo' => 'Hola {{nombre}}, te comparto la cotización {{folio}} por {{total}}. Cualquier duda con gusto te apoyo. — {{vendedor}}, {{empresa}}',
        ],
        [
            'nombre' => 'Primer contacto',
            'orden' => 3,
            'cuerpo' => 'Hola {{nombre}}, soy {{vendedor}} de {{empresa}}. Gracias por tu interés, ¿cuándo podríamos platicar con calma?',
        ],
        [
            'nombre' => 'Recordatorio de cita',
            'orden' => 4,
            'cuerpo' => 'Hola {{nombre}}, te recuerdo nuestra cita. Si necesitas reagendar, avísame. — {{vendedor}}',
        ],
    ];

    /** Inserta las 4 plantillas si la empresa no tiene ninguna. Devuelve cuántas insertó. */
    public static function sembrar(int $empresaId): int
    {
        if (DB::table('crm_plantillas_whatsapp')->where('empresa_id', $empresaId)->exists()) {
            return 0;
        }

        $ahora = now();
        DB::table('crm_plantillas_whatsapp')->insert(array_map(fn (array $plantilla) => [
            'empresa_id' => $empresaId,
            'nombre' => $plantilla['nombre'],
            'cuerpo' => $plantilla['cuerpo'],
            'activo' => true,
            'orden' => $plantilla['orden'],
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], self::PLANTILLAS));

        return count(self::PLANTILLAS);
    }
}
