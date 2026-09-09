<?php

namespace App\Services\CRM;

use Carbon\Carbon;

/**
 * Agrega métricas ejecutivas del CRM (pipeline, cotizaciones, funnel de
 * conversión, actividad, cumplimiento de metas, ranking de vendedores) para
 * el módulo Dashboard. Sin estado, sin efectos secundarios -- cada método
 * recibe el contexto de empresa/vendedor/periodo ya resuelto por el
 * controller y devuelve un array serializable.
 *
 * No importa PresupuestoResumenService a propósito -- aunque
 * cumplimientoMetas() calcula algo conceptualmente parecido a
 * resumenMensual(), cada service queda independiente por módulo para no
 * acoplar Dashboard a cambios futuros en Presupuestos (spec, "Fuera de
 * alcance").
 */
class DashboardResumenService
{
    /**
     * Resuelve un periodo predefinido al rango [inicio, fin] correspondiente,
     * anclado a "ahora" (Carbon::now(), respeta Carbon::setTestNow() en tests).
     *
     * @return array{inicio: Carbon, fin: Carbon}
     */
    public function resolverRangoFechas(string $periodo): array
    {
        $hoy = Carbon::now();

        return match ($periodo) {
            'mes_actual' => [
                'inicio' => $hoy->copy()->startOfMonth(),
                'fin' => $hoy->copy()->endOfMonth(),
            ],
            'mes_anterior' => [
                'inicio' => $hoy->copy()->subMonthNoOverflow()->startOfMonth(),
                'fin' => $hoy->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'trimestre' => [
                'inicio' => $hoy->copy()->startOfQuarter(),
                'fin' => $hoy->copy()->endOfQuarter(),
            ],
            'anio' => [
                'inicio' => $hoy->copy()->startOfYear(),
                'fin' => $hoy->copy()->endOfYear(),
            ],
            default => throw new \InvalidArgumentException("Periodo inválido: {$periodo}"),
        };
    }
}
