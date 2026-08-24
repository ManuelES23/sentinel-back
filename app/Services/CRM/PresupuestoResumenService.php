<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmPresupuesto;
use Carbon\Carbon;

/**
 * Calcula el lado "real" de un presupuesto cruzando datos que ya
 * existen (Oportunidades, Cotizaciones, Clientes, Actividades) --
 * no hay una tabla de "ventas reales" separada, a propósito.
 *
 * Monto esperado y monto cotizado se devuelven SIEMPRE por separado
 * (decisión explícita del spec): son conceptos distintos -- expectativa
 * del pipeline vs. lo formalmente cotizado y aprobado -- y fusionarlos
 * escondería cuál de los dos no está cumpliendo la meta.
 */
class PresupuestoResumenService
{
    public function resumenMensual(int $empresaId, int $vendedorId, int $mes, int $anio): array
    {
        $inicio = Carbon::create($anio, $mes, 1)->startOfMonth();
        $fin = (clone $inicio)->endOfMonth();

        $montoEsperado = (float) CrmOportunidad::where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedorId)
            ->where('etapa', 'cerrado_ganado')
            ->whereBetween('fecha_cierre_real', [$inicio, $fin])
            ->sum('monto_esperado');

        $montoCotizado = (float) CrmCotizacion::where('empresa_id', $empresaId)
            ->where('estado', 'aprobado')
            ->whereHas('oportunidad', fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->whereBetween('fecha_emision', [$inicio->toDateString(), $fin->toDateString()])
            ->sum('total');

        $clientesReales = CrmCliente::where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedorId)
            ->whereBetween('created_at', [$inicio, $fin])
            ->count();

        $actividadesReales = CrmActividad::where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedorId)
            ->whereBetween('fecha_actividad', [$inicio, $fin])
            ->count();

        return [
            'montoEsperado' => $montoEsperado,
            'montoCotizado' => $montoCotizado,
            'clientesReales' => $clientesReales,
            'actividadesReales' => $actividadesReales,
        ];
    }

    public function comparativoAnual(int $empresaId, int $vendedorId, int $anio): array
    {
        $presupuestosDelAnio = CrmPresupuesto::where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedorId)
            ->where('anio', $anio)
            ->get()
            ->keyBy('mes');

        $meses = [];
        for ($mes = 1; $mes <= 12; $mes++) {
            $presupuesto = $presupuestosDelAnio->get($mes);
            $resumen = $this->resumenMensual($empresaId, $vendedorId, $mes, $anio);

            $meses[] = array_merge(
                [
                    'mes' => $mes,
                    'metaMonto' => (float) ($presupuesto->meta_monto ?? 0),
                    'metaClientes' => (int) ($presupuesto->meta_clientes ?? 0),
                    'metaActividades' => (int) ($presupuesto->meta_actividades ?? 0),
                ],
                $resumen,
            );
        }

        return $meses;
    }
}
