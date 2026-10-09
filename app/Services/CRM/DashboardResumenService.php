<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmPresupuesto;
use App\Models\CRM\CrmVendedor;
use App\Support\CRM\RangoDashboard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Métricas del Dashboard del CRM (spec 2026-10-08 §5.4–5.6): KPIs con
 * comparación y serie, tendencia contra meta, cumplimiento con pronóstico,
 * cotizaciones, actividad y ranking. Sin estado: el controller resuelve
 * empresa, vendedor y rango. Los embudos viven en EmbudoService.
 */
class DashboardResumenService
{
    public const ESTADOS_COTIZACION = ['borrador', 'enviado', 'aprobado', 'rechazado', 'superado'];

    public const TIPOS_ACTIVIDAD = ['llamada', 'whatsapp', 'correo', 'visita', 'reunion', 'nota'];

    public const MESES_SERIE = 8;

    public const MESES_TENDENCIA = 6;

    public function kpis(int $empresaId, ?int $vendedorId, RangoDashboard $rango): array
    {
        $abiertas = CrmOportunidad::where('empresa_id', $empresaId)
            ->activas()
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(monto_esperado), 0) as monto, '
                .'COALESCE(SUM(monto_esperado * probabilidad / 100.0), 0) as ponderado')
            ->first();

        $pendientes = CrmCotizacion::where('empresa_id', $empresaId)
            ->whereIn('estado', ['borrador', 'enviado'])
            ->when($vendedorId !== null, fn ($q) => $q->whereHas('oportunidad', fn ($qq) => $qq->where('vendedor_id', $vendedorId)))
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(total), 0) as monto')
            ->first();

        return [
            'ventas' => $this->metrica($rango, fn ($i, $f) => $this->ventas($empresaId, $vendedorId, $i, $f)),
            'tasaCierre' => $this->metrica($rango, fn ($i, $f) => $this->tasaCierre($empresaId, $vendedorId, $i, $f)),
            'cicloVenta' => $this->metrica($rango, fn ($i, $f) => $this->cicloVenta($empresaId, $vendedorId, $i, $f)),
            'clientesNuevos' => $this->metrica($rango, fn ($i, $f) => $this->clientesNuevos($empresaId, $vendedorId, $i, $f)),
            'pipeline' => [
                'abiertas' => (int) $abiertas->total,
                'monto' => round((float) $abiertas->monto, 2),
                'ponderado' => round((float) $abiertas->ponderado, 2),
            ],
            'cotizacionesPendientes' => [
                'cantidad' => (int) $pendientes->cantidad,
                'monto' => round((float) $pendientes->monto, 2),
            ],
            'rango' => [
                'inicio' => $rango->inicio->toDateString(),
                'fin' => $rango->fin->toDateString(),
                'etiquetaAnterior' => $rango->etiquetaAnterior,
            ],
        ];
    }

    public function tendencia(int $empresaId, ?int $vendedorId, RangoDashboard $rango, bool $comparar): array
    {
        return array_map(function (CarbonImmutable $mes) use ($empresaId, $vendedorId, $comparar) {
            $fila = [
                'anio' => $mes->year,
                'mes' => $mes->month,
                'ganado' => $this->ventas($empresaId, $vendedorId, $mes->startOfMonth(), $mes->endOfMonth()),
                'meta' => round((float) CrmPresupuesto::where('empresa_id', $empresaId)
                    ->where('anio', $mes->year)
                    ->where('mes', $mes->month)
                    ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
                    ->sum('meta_monto'), 2),
            ];

            if ($comparar) {
                $previo = $mes->subYearNoOverflow();
                $fila['ganadoAnioAnterior'] = $this->ventas($empresaId, $vendedorId, $previo->startOfMonth(), $previo->endOfMonth());
            }

            return $fila;
        }, $rango->ultimosMeses(self::MESES_TENDENCIA));
    }

    public function cumplimientoMetas(int $empresaId, ?int $vendedorId, RangoDashboard $rango): array
    {
        $metas = $this->presupuestosDelRango($empresaId, $rango)
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->selectRaw('SUM(meta_monto) as meta_monto, SUM(meta_clientes) as meta_clientes, SUM(meta_actividades) as meta_actividades')
            ->first();

        $montoReal = $this->ventas($empresaId, $vendedorId, $rango->inicio, $rango->fin);
        $terminado = ! $rango->enCurso();

        return [
            'metaMonto' => (float) ($metas->meta_monto ?? 0),
            'metaClientes' => (int) ($metas->meta_clientes ?? 0),
            'metaActividades' => (int) ($metas->meta_actividades ?? 0),
            'montoReal' => $montoReal,
            'clientesReales' => $this->clientesNuevos($empresaId, $vendedorId, $rango->inicio, $rango->fin),
            'actividadesReales' => CrmActividad::where('empresa_id', $empresaId)
                ->whereBetween('fecha_actividad', [$rango->inicio, $rango->fin])
                ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
                ->count(),
            'pronostico' => $terminado ? $montoReal : round($montoReal + $this->ponderadoPorCerrar($empresaId, $vendedorId, $rango), 2),
            'periodoTerminado' => $terminado,
        ];
    }

    public function cotizaciones(int $empresaId, ?int $vendedorId, RangoDashboard $rango, bool $comparar): array
    {
        $actual = $this->cotizacionesPorEstado($empresaId, $vendedorId, $rango->inicio, $rango->fin);
        $anterior = $comparar ? $this->cotizacionesPorEstado($empresaId, $vendedorId, $rango->inicioAnterior, $rango->finAnterior) : null;

        return array_map(function (string $estado) use ($actual, $anterior) {
            $fila = ['estado' => $estado] + $actual[$estado];
            if ($anterior !== null) {
                $fila['anterior'] = $anterior[$estado];
            }

            return $fila;
        }, self::ESTADOS_COTIZACION);
    }

    public function actividad(int $empresaId, ?int $vendedorId, RangoDashboard $rango, bool $comparar): array
    {
        $actual = $this->actividadPorTipo($empresaId, $vendedorId, $rango->inicio, $rango->fin);
        $anterior = $comparar ? $this->actividadPorTipo($empresaId, $vendedorId, $rango->inicioAnterior, $rango->finAnterior) : null;

        return [
            'porTipo' => array_map(function (string $tipo) use ($actual, $anterior) {
                $fila = ['tipo' => $tipo, 'total' => $actual[$tipo]];
                if ($anterior !== null) {
                    $fila['anterior'] = $anterior[$tipo];
                }

                return $fila;
            }, self::TIPOS_ACTIVIDAD),
        ];
    }

    public function rankingVendedores(int $empresaId, RangoDashboard $rango): array
    {
        $metas = $this->presupuestosDelRango($empresaId, $rango)
            ->selectRaw('vendedor_id, SUM(meta_monto) as meta')
            ->groupBy('vendedor_id')
            ->pluck('meta', 'vendedor_id');

        return CrmVendedor::where('empresa_id', $empresaId)
            ->activo()
            ->withSum(['oportunidades as monto_cerrado' => fn ($q) => $q
                ->where('etapa', 'cerrado_ganado')
                ->whereBetween('fecha_cierre_real', [$rango->inicio, $rango->fin])], 'monto_esperado')
            ->orderByDesc('monto_cerrado')
            ->get()
            ->map(fn (CrmVendedor $vendedor) => [
                'vendedorId' => $vendedor->id,
                'nombre' => $vendedor->nombre,
                'montoCerrado' => (float) ($vendedor->monto_cerrado ?? 0),
                'meta' => (float) ($metas[$vendedor->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    /** @return array{total: int, ganadas: int} cierres (ganadas + perdidas) por fecha_cierre_real. */
    public function cierres(int $empresaId, ?int $vendedorId, CarbonInterface $inicio, CarbonInterface $fin): array
    {
        $fila = CrmOportunidad::where('empresa_id', $empresaId)
            ->whereIn('etapa', CrmOportunidad::ETAPAS_TERMINALES)
            ->whereBetween('fecha_cierre_real', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN etapa = 'cerrado_ganado' THEN 1 ELSE 0 END), 0) as ganadas")
            ->first();

        return ['total' => (int) $fila->total, 'ganadas' => (int) $fila->ganadas];
    }

    public function tasaCierre(int $empresaId, ?int $vendedorId, CarbonInterface $inicio, CarbonInterface $fin): ?float
    {
        $cierres = $this->cierres($empresaId, $vendedorId, $inicio, $fin);

        return $cierres['total'] > 0 ? round($cierres['ganadas'] / $cierres['total'] * 100, 1) : null;
    }

    private function metrica(RangoDashboard $rango, callable $calcular): array
    {
        return [
            'actual' => $calcular($rango->inicio, $rango->fin),
            'anterior' => $calcular($rango->inicioAnterior, $rango->finAnterior),
            'serie' => array_map(fn (CarbonImmutable $mes) => [
                'anio' => $mes->year,
                'mes' => $mes->month,
                'valor' => $calcular($mes->startOfMonth(), $mes->endOfMonth()),
            ], $rango->ultimosMeses(self::MESES_SERIE)),
        ];
    }

    private function ganadasEntre(int $empresaId, ?int $vendedorId, CarbonInterface $inicio, CarbonInterface $fin): Builder
    {
        return CrmOportunidad::where('empresa_id', $empresaId)
            ->where('etapa', 'cerrado_ganado')
            ->whereBetween('fecha_cierre_real', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId));
    }

    private function ventas(int $empresaId, ?int $vendedorId, CarbonInterface $inicio, CarbonInterface $fin): float
    {
        return round((float) $this->ganadasEntre($empresaId, $vendedorId, $inicio, $fin)->sum('monto_esperado'), 2);
    }

    private function cicloVenta(int $empresaId, ?int $vendedorId, CarbonInterface $inicio, CarbonInterface $fin): ?int
    {
        $dias = $this->ganadasEntre($empresaId, $vendedorId, $inicio, $fin)
            ->get(['created_at', 'fecha_cierre_real'])
            ->map(fn (CrmOportunidad $o) => $o->created_at->diffInDays($o->fecha_cierre_real, true));

        return $dias->isEmpty() ? null : (int) round($dias->avg());
    }

    private function clientesNuevos(int $empresaId, ?int $vendedorId, CarbonInterface $inicio, CarbonInterface $fin): int
    {
        return CrmCliente::where('empresa_id', $empresaId)
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->count();
    }

    /** Ponderado de las abiertas con cierre esperado entre hoy (o el inicio) y el fin del rango. */
    private function ponderadoPorCerrar(int $empresaId, ?int $vendedorId, RangoDashboard $rango): float
    {
        $desde = $rango->inicio->max(CarbonImmutable::now()->startOfDay());

        $fila = CrmOportunidad::where('empresa_id', $empresaId)
            ->activas()
            ->whereBetween('fecha_cierre_esperada', [$desde->toDateString(), $rango->fin->toDateString()])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->selectRaw('COALESCE(SUM(monto_esperado * probabilidad / 100.0), 0) as ponderado')
            ->first();

        return (float) $fila->ponderado;
    }

    /** Presupuestos de los meses que toca el rango. */
    private function presupuestosDelRango(int $empresaId, RangoDashboard $rango): Builder
    {
        return CrmPresupuesto::where('empresa_id', $empresaId)
            ->where(function ($query) use ($rango) {
                $cursor = $rango->inicio->startOfMonth();
                while ($cursor->lte($rango->fin)) {
                    $mes = $cursor->month;
                    $anio = $cursor->year;
                    $query->orWhere(fn ($q) => $q->where('mes', $mes)->where('anio', $anio));
                    $cursor = $cursor->addMonthNoOverflow();
                }
            });
    }

    /** @return array<string, array{total: int, monto: float}> */
    private function cotizacionesPorEstado(int $empresaId, ?int $vendedorId, CarbonInterface $inicio, CarbonInterface $fin): array
    {
        $filas = CrmCotizacion::where('empresa_id', $empresaId)
            ->whereBetween('fecha_emision', [$inicio->toDateString(), $fin->toDateString()])
            ->when($vendedorId !== null, fn ($q) => $q->whereHas('oportunidad', fn ($qq) => $qq->where('vendedor_id', $vendedorId)))
            ->selectRaw('estado, COUNT(*) as cantidad, COALESCE(SUM(total), 0) as monto')
            ->groupBy('estado')
            ->get()
            ->keyBy('estado');

        $resultado = [];
        foreach (self::ESTADOS_COTIZACION as $estado) {
            $resultado[$estado] = [
                'total' => (int) ($filas[$estado]->cantidad ?? 0),
                'monto' => round((float) ($filas[$estado]->monto ?? 0), 2),
            ];
        }

        return $resultado;
    }

    /** @return array<string, int> */
    private function actividadPorTipo(int $empresaId, ?int $vendedorId, CarbonInterface $inicio, CarbonInterface $fin): array
    {
        $conteos = CrmActividad::where('empresa_id', $empresaId)
            ->whereBetween('fecha_actividad', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->selectRaw('tipo, COUNT(*) as total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $resultado = [];
        foreach (self::TIPOS_ACTIVIDAD as $tipo) {
            $resultado[$tipo] = (int) ($conteos[$tipo] ?? 0);
        }

        return $resultado;
    }
}
