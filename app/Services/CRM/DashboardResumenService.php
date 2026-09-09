<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
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

    /**
     * @return array<int, array{etapa: string, total: int, monto: float}>
     */
    public function pipeline(int $empresaId, ?int $vendedorId, string $periodo): array
    {
        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        $query = CrmOportunidad::where('empresa_id', $empresaId)
            ->whereBetween('fecha_cierre_esperada', [$inicio->toDateString(), $fin->toDateString()])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId));

        $filas = $query->selectRaw('etapa, COUNT(*) as total, SUM(monto_esperado) as monto')
            ->groupBy('etapa')
            ->get()
            ->keyBy('etapa');

        return collect(array_keys(CrmOportunidad::ORDEN_ETAPAS))
            ->map(fn ($etapa) => [
                'etapa' => $etapa,
                'total' => (int) ($filas[$etapa]->total ?? 0),
                'monto' => (float) ($filas[$etapa]->monto ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{estado: string, total: int, monto: float}>
     */
    public function cotizaciones(int $empresaId, ?int $vendedorId, string $periodo): array
    {
        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        $query = CrmCotizacion::where('empresa_id', $empresaId)
            ->whereBetween('fecha_emision', [$inicio->toDateString(), $fin->toDateString()])
            ->when(
                $vendedorId !== null,
                fn ($q) => $q->whereHas('oportunidad', fn ($qq) => $qq->where('vendedor_id', $vendedorId)),
            );

        $filas = $query->selectRaw('estado, COUNT(*) as cantidad, SUM(total) as monto')
            ->groupBy('estado')
            ->get()
            ->keyBy('estado');

        $estados = ['borrador', 'enviado', 'aprobado', 'rechazado', 'superado'];

        return collect($estados)
            ->map(fn ($estado) => [
                'estado' => $estado,
                'total' => (int) ($filas[$estado]->cantidad ?? 0),
                'monto' => (float) ($filas[$estado]->monto ?? 0),
            ])
            ->values()
            ->all();
    }
}
