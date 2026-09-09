<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmPresupuesto;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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

    /**
     * @return array{prospectosCreados: int, clientesConvertidos: int, tasaConversion: float}
     */
    public function funnelConversion(int $empresaId, ?int $vendedorId, string $periodo): array
    {
        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        $prospectosCreados = CrmProspecto::where('empresa_id', $empresaId)
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->count();

        $clientesConvertidos = CrmCliente::where('empresa_id', $empresaId)
            ->whereNotNull('prospecto_id')
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->count();

        $tasaConversion = $prospectosCreados > 0
            ? round(($clientesConvertidos / $prospectosCreados) * 100, 1)
            : 0.0;

        return [
            'prospectosCreados' => $prospectosCreados,
            'clientesConvertidos' => $clientesConvertidos,
            'tasaConversion' => $tasaConversion,
        ];
    }

    /**
     * @return array{porTipo: array<int, array{tipo: string, total: int}>, porDia: array<int, array{fecha: string, total: int}>}
     */
    public function actividad(int $empresaId, ?int $vendedorId, string $periodo): array
    {
        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        $base = CrmActividad::where('empresa_id', $empresaId)
            ->whereBetween('fecha_actividad', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId));

        $porTipo = (clone $base)
            ->selectRaw('tipo, COUNT(*) as total')
            ->groupBy('tipo')
            ->get()
            ->map(fn ($fila) => ['tipo' => $fila->tipo, 'total' => (int) $fila->total])
            ->values()
            ->all();

        $porDia = (clone $base)
            ->selectRaw('DATE(fecha_actividad) as fecha, COUNT(*) as total')
            ->groupBy(DB::raw('DATE(fecha_actividad)'))
            ->orderBy('fecha')
            ->get()
            ->map(fn ($fila) => ['fecha' => $fila->fecha, 'total' => (int) $fila->total])
            ->values()
            ->all();

        return [
            'porTipo' => $porTipo,
            'porDia' => $porDia,
        ];
    }

    /**
     * @return array{metaMonto: float, metaClientes: int, metaActividades: int, montoReal: float, clientesReales: int, actividadesReales: int}
     */
    public function cumplimientoMetas(int $empresaId, ?int $vendedorId, string $periodo): array
    {
        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        $metas = CrmPresupuesto::where('empresa_id', $empresaId)
            ->where(function ($query) use ($inicio, $fin) {
                $cursor = $inicio->copy()->startOfMonth();
                while ($cursor->lte($fin)) {
                    $query->orWhere(fn ($q) => $q->where('mes', $cursor->month)->where('anio', $cursor->year));
                    $cursor->addMonth();
                }
            })
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->selectRaw('SUM(meta_monto) as meta_monto, SUM(meta_clientes) as meta_clientes, SUM(meta_actividades) as meta_actividades')
            ->first();

        $montoReal = (float) CrmOportunidad::where('empresa_id', $empresaId)
            ->where('etapa', 'cerrado_ganado')
            ->whereBetween('fecha_cierre_real', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->sum('monto_esperado');

        $clientesReales = CrmCliente::where('empresa_id', $empresaId)
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->count();

        $actividadesReales = CrmActividad::where('empresa_id', $empresaId)
            ->whereBetween('fecha_actividad', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->count();

        return [
            'metaMonto' => (float) ($metas->meta_monto ?? 0),
            'metaClientes' => (int) ($metas->meta_clientes ?? 0),
            'metaActividades' => (int) ($metas->meta_actividades ?? 0),
            'montoReal' => $montoReal,
            'clientesReales' => $clientesReales,
            'actividadesReales' => $actividadesReales,
        ];
    }

    /**
     * @return array{oportunidadesAbiertas: int, montoOportunidadesAbiertas: float, cotizacionesPendientes: int, montoCotizacionesPendientes: float, clientesNuevos: int, porcentajeCumplimientoMeta: float}
     */
    public function kpis(int $empresaId, ?int $vendedorId, string $periodo): array
    {
        $oportunidadesAbiertas = CrmOportunidad::where('empresa_id', $empresaId)
            ->activas()
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->selectRaw('COUNT(*) as total, SUM(monto_esperado) as monto')
            ->first();

        $cotizacionesPendientes = CrmCotizacion::where('empresa_id', $empresaId)
            ->whereIn('estado', ['borrador', 'enviado'])
            ->when(
                $vendedorId !== null,
                fn ($q) => $q->whereHas('oportunidad', fn ($qq) => $qq->where('vendedor_id', $vendedorId)),
            )
            ->selectRaw('COUNT(*) as cantidad, SUM(total) as monto')
            ->first();

        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        $clientesNuevos = CrmCliente::where('empresa_id', $empresaId)
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->count();

        $metas = $this->cumplimientoMetas($empresaId, $vendedorId, $periodo);
        $porcentajeCumplimientoMeta = $metas['metaMonto'] > 0
            ? round(($metas['montoReal'] / $metas['metaMonto']) * 100, 1)
            : 0.0;

        return [
            'oportunidadesAbiertas' => (int) ($oportunidadesAbiertas->total ?? 0),
            'montoOportunidadesAbiertas' => (float) ($oportunidadesAbiertas->monto ?? 0),
            'cotizacionesPendientes' => (int) ($cotizacionesPendientes->cantidad ?? 0),
            'montoCotizacionesPendientes' => (float) ($cotizacionesPendientes->monto ?? 0),
            'clientesNuevos' => $clientesNuevos,
            'porcentajeCumplimientoMeta' => $porcentajeCumplimientoMeta,
        ];
    }

    /**
     * @return array<int, array{vendedorId: int, nombre: string, montoCerrado: float}>
     */
    public function rankingVendedores(int $empresaId, string $periodo): array
    {
        ['inicio' => $inicio, 'fin' => $fin] = $this->resolverRangoFechas($periodo);

        return CrmVendedor::where('empresa_id', $empresaId)
            ->activo()
            ->withSum(['oportunidades as monto_cerrado' => function ($query) use ($inicio, $fin) {
                $query->where('etapa', 'cerrado_ganado')
                    ->whereBetween('fecha_cierre_real', [$inicio, $fin]);
            }], 'monto_esperado')
            ->orderByDesc('monto_cerrado')
            ->get()
            ->map(fn ($vendedor) => [
                'vendedorId' => $vendedor->id,
                'nombre' => $vendedor->nombre,
                'montoCerrado' => (float) ($vendedor->monto_cerrado ?? 0),
            ])
            ->values()
            ->all();
    }
}
