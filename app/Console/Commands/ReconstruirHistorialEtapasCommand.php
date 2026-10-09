<?php

namespace App\Console\Commands;

use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadEtapa;
use Illuminate\Console\Command;

/**
 * Infiere el historial de etapas de las oportunidades creadas antes de que
 * existiera crm_oportunidad_etapas (spec 2026-10-08 §4.3). Idempotente:
 * solo procesa oportunidades sin ninguna fila de historial.
 */
class ReconstruirHistorialEtapasCommand extends Command
{
    protected $signature = 'crm:reconstruir-historial-etapas';

    protected $description = 'Infiere el historial de etapas de las oportunidades del CRM que no lo tienen';

    private const ABIERTAS = ['prospecto', 'calificado', 'propuesta', 'negociacion'];

    public function handle(): int
    {
        $reconstruidas = 0;

        CrmOportunidad::withTrashed()
            ->whereDoesntHave('historialEtapas')
            ->chunkById(500, function ($oportunidades) use (&$reconstruidas) {
                foreach ($oportunidades as $oportunidad) {
                    CrmOportunidadEtapa::insert($this->filasPara($oportunidad));
                    $reconstruidas++;
                }
            });

        $this->info("Oportunidades reconstruidas: {$reconstruidas}");

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>> */
    private function filasPara(CrmOportunidad $oportunidad): array
    {
        $ahora = now();
        $fila = fn (?string $desde, string $hasta, $cuando) => [
            'empresa_id' => $oportunidad->empresa_id,
            'oportunidad_id' => $oportunidad->id,
            'etapa_desde' => $desde,
            'etapa_hasta' => $hasta,
            'cambiado_en' => $cuando,
            'user_id' => null,
            'inferido' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];

        if ($oportunidad->etapa === 'cerrado_perdido') {
            // No se sabe en qué etapa se perdió: etapa_desde queda en null ("sin registro").
            return [
                $fila(null, 'prospecto', $oportunidad->created_at),
                $fila(null, 'cerrado_perdido', $oportunidad->fecha_cierre_real),
            ];
        }

        $ultima = $oportunidad->etapa === 'cerrado_ganado'
            ? count(self::ABIERTAS) - 1
            : CrmOportunidad::ORDEN_ETAPAS[$oportunidad->etapa];

        $filas = [];
        for ($i = 0; $i <= $ultima; $i++) {
            $filas[] = $fila(
                $i === 0 ? null : self::ABIERTAS[$i - 1],
                self::ABIERTAS[$i],
                $i === 0 ? $oportunidad->created_at : null,
            );
        }

        if ($oportunidad->etapa === 'cerrado_ganado') {
            $filas[] = $fila('negociacion', 'cerrado_ganado', $oportunidad->fecha_cierre_real);
        }

        return $filas;
    }
}
