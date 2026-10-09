<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmPresupuesto;
use App\Models\CRM\CrmVendedor;
use App\Support\CRM\RangoDashboard;

/**
 * Bloque "Mi mes" de Mi día (spec 2026-10-08 §6.4): meta, ganado,
 * pronóstico y embudo de conversión del vendedor en el mes en curso.
 */
class MiMesService
{
    public function __construct(
        private readonly DashboardResumenService $metricas,
        private readonly EmbudoService $embudos,
    ) {}

    public function para(int $empresaId, CrmVendedor $vendedor): array
    {
        $rango = RangoDashboard::desdePeriodo('mes_actual');
        $metas = $this->metricas->cumplimientoMetas($empresaId, $vendedor->id, $rango);
        $tieneMeta = CrmPresupuesto::where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedor->id)
            ->where('mes', $rango->inicio->month)
            ->where('anio', $rango->inicio->year)
            ->exists();

        return [
            'mes' => $rango->inicio->month,
            'anio' => $rango->inicio->year,
            'meta' => $tieneMeta ? $metas['metaMonto'] : null,
            'ganado' => $metas['montoReal'],
            'pronostico' => $metas['pronostico'],
            'embudo' => $this->embudos->conversion($empresaId, $vendedor->id, $rango),
        ];
    }
}
