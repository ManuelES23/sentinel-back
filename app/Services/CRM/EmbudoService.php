<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadEtapa;
use App\Support\CRM\RangoDashboard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Embudos de oportunidades del CRM (spec 2026-10-08 §5.2, §5.3 y §6.3).
 * Lo usan el Dashboard, la cabecera de Oportunidades y Mi día.
 */
class EmbudoService
{
    public const ETAPAS_ABIERTAS = ['prospecto', 'calificado', 'propuesta', 'negociacion'];

    /** Una etapa necesita al menos esta cantidad de llegadas para marcarse como mayor fuga. */
    public const MINIMO_MAYOR_FUGA = 5;

    public const LIMITE_DETALLE = 50;

    /** @return array<int, array{etapa: string, cantidad: int, monto: float, ponderado: float, probabilidadPromedio: float}> */
    public function pipeline(int $empresaId, ?int $vendedorId): array
    {
        $filas = CrmOportunidad::where('empresa_id', $empresaId)
            ->whereIn('etapa', self::ETAPAS_ABIERTAS)
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->selectRaw('etapa, COUNT(*) as cantidad, COALESCE(SUM(monto_esperado), 0) as monto, '
                .'COALESCE(SUM(monto_esperado * probabilidad / 100.0), 0) as ponderado, '
                .'COALESCE(AVG(probabilidad), 0) as probabilidad_promedio')
            ->groupBy('etapa')
            ->get()
            ->keyBy('etapa');

        return array_map(fn (string $etapa) => [
            'etapa' => $etapa,
            'cantidad' => (int) ($filas[$etapa]->cantidad ?? 0),
            'monto' => round((float) ($filas[$etapa]->monto ?? 0), 2),
            'ponderado' => round((float) ($filas[$etapa]->ponderado ?? 0), 2),
            'probabilidadPromedio' => round((float) ($filas[$etapa]->probabilidad_promedio ?? 0), 1),
        ], self::ETAPAS_ABIERTAS);
    }

    public function conversion(int $empresaId, ?int $vendedorId, RangoDashboard $rango): array
    {
        $cohorte = $this->cohorte($empresaId, $vendedorId, $rango);
        $oportunidades = (clone $cohorte)->get(['id', 'etapa', 'monto_esperado']);
        $historial = CrmOportunidadEtapa::whereIn('oportunidad_id', (clone $cohorte)->select('id'))
            ->orderBy('id')
            ->get(['oportunidad_id', 'etapa_desde', 'etapa_hasta', 'cambiado_en', 'inferido'])
            ->groupBy('oportunidad_id');

        $llegaron = array_fill_keys(self::ETAPAS_ABIERTAS, 0);
        $siguenAbiertas = array_fill_keys(self::ETAPAS_ABIERTAS, 0);
        $perdidas = array_fill_keys(self::ETAPAS_ABIERTAS, 0);
        $dias = array_fill_keys(self::ETAPAS_ABIERTAS, []);
        $ganadas = 0;
        $montoGanado = 0.0;
        $sinRegistro = 0;

        foreach ($oportunidades as $oportunidad) {
            $filas = $historial->get($oportunidad->id, collect());
            $maxima = $this->etapaMaxima($oportunidad, $filas);

            foreach (self::ETAPAS_ABIERTAS as $orden => $etapa) {
                if ($maxima >= $orden) {
                    $llegaron[$etapa]++;
                }
            }

            if (isset($siguenAbiertas[$oportunidad->etapa])) {
                $siguenAbiertas[$oportunidad->etapa]++;
            } elseif ($oportunidad->etapa === 'cerrado_ganado') {
                $ganadas++;
                $montoGanado += (float) $oportunidad->monto_esperado;
            } elseif ($oportunidad->etapa === 'cerrado_perdido') {
                $desde = $filas->firstWhere('etapa_hasta', 'cerrado_perdido')?->etapa_desde;
                if ($desde !== null && isset($perdidas[$desde])) {
                    $perdidas[$desde]++;
                } else {
                    $sinRegistro++;
                }
            }

            $this->acumularDias($filas, $dias);
        }

        $etapas = [];
        foreach (self::ETAPAS_ABIERTAS as $orden => $etapa) {
            $siguiente = $orden < 3 ? $llegaron[self::ETAPAS_ABIERTAS[$orden + 1]] : $ganadas;
            $etapas[] = [
                'etapa' => $etapa,
                'llegaron' => $llegaron[$etapa],
                'siguenAbiertas' => $siguenAbiertas[$etapa],
                'perdidas' => $perdidas[$etapa],
                'pasa' => $llegaron[$etapa] > 0 ? round($siguiente / $llegaron[$etapa] * 100, 1) : null,
                'diasPromedio' => $dias[$etapa] ? round(array_sum($dias[$etapa]) / count($dias[$etapa]), 1) : null,
            ];
        }

        return [
            'etapas' => $etapas,
            'ganadas' => $ganadas,
            'montoGanado' => round($montoGanado, 2),
            'perdidasSinRegistro' => $sinRegistro,
            'total' => $oportunidades->count(),
            'mayorFuga' => $this->mayorFuga($etapas),
        ];
    }

    public function detalle(int $empresaId, ?int $vendedorId, RangoDashboard $rango, string $tipo, ?string $etapa): array
    {
        $query = match ($tipo) {
            'abiertas' => CrmOportunidad::where('empresa_id', $empresaId)
                ->where('etapa', $etapa)
                ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId)),
            'ganadas' => $this->cohorte($empresaId, $vendedorId, $rango)->where('etapa', 'cerrado_ganado'),
            'perdidas' => $this->perdidasEn($this->cohorte($empresaId, $vendedorId, $rango), $etapa),
            'llegaron' => $this->llegaronA($this->cohorte($empresaId, $vendedorId, $rango), $etapa),
            default => throw new \InvalidArgumentException("Tipo de detalle inválido: {$tipo}"),
        };

        $total = (clone $query)->count();
        $items = $query->with(['cliente:id,nombre', 'prospecto:id,nombre', 'vendedor:id,nombre'])
            ->orderByDesc('monto_esperado')
            ->orderBy('id')
            ->limit(self::LIMITE_DETALLE)
            ->get()
            ->map(fn (CrmOportunidad $o) => [
                'id' => $o->id,
                'nombre' => $o->nombre,
                'cuenta' => $o->cliente
                    ? ['tipo' => 'cliente', 'nombre' => $o->cliente->nombre]
                    : ($o->prospecto ? ['tipo' => 'prospecto', 'nombre' => $o->prospecto->nombre] : null),
                'monto' => (float) $o->monto_esperado,
                'probabilidad' => $o->probabilidad,
                'etapa' => $o->etapa,
                'vendedor' => $o->vendedor ? ['id' => $o->vendedor->id, 'nombre' => $o->vendedor->nombre] : null,
                'fechaCierreEsperada' => $o->fecha_cierre_esperada?->toDateString(),
                'fechaCierreReal' => $o->fecha_cierre_real?->toIso8601String(),
                'motivoPerdida' => $o->motivo_perdida,
            ])
            ->all();

        return ['total' => $total, 'items' => $items];
    }

    private function perdidasEn(Builder $cohorte, ?string $etapa): Builder
    {
        $cohorte->where('etapa', 'cerrado_perdido');

        if ($etapa === 'sin_registro') {
            return $cohorte->whereDoesntHave('historialEtapas', fn ($h) => $h
                ->where('etapa_hasta', 'cerrado_perdido')
                ->whereNotNull('etapa_desde'));
        }

        return $cohorte->whereHas('historialEtapas', fn ($h) => $h
            ->where('etapa_hasta', 'cerrado_perdido')
            ->where('etapa_desde', $etapa));
    }

    /** Llegó a $etapa: su etapa actual o alguna de su historial (destino u origen) es $etapa o posterior (las ganadas, siempre). */
    private function llegaronA(Builder $cohorte, ?string $etapa): Builder
    {
        $orden = array_search($etapa, self::ETAPAS_ABIERTAS, true);
        if ($orden === false || $orden === 0) {
            return $cohorte;
        }

        $alcanzadas = array_slice(self::ETAPAS_ABIERTAS, $orden);

        return $cohorte->where(fn ($q) => $q
            ->whereIn('etapa', [...$alcanzadas, 'cerrado_ganado'])
            ->orWhereHas('historialEtapas', fn ($h) => $h->whereIn('etapa_hasta', $alcanzadas))
            ->orWhereHas('historialEtapas', fn ($h) => $h->whereIn('etapa_desde', $alcanzadas)));
    }

    /** Oportunidades creadas en el rango (las borradas no cuentan). */
    private function cohorte(int $empresaId, ?int $vendedorId, RangoDashboard $rango): Builder
    {
        return CrmOportunidad::where('empresa_id', $empresaId)
            ->whereBetween('created_at', [$rango->inicio, $rango->fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId));
    }

    /** Orden (0-3) de la etapa abierta más alta a la que llegó; las ganadas llegaron a todas. */
    private function etapaMaxima(CrmOportunidad $oportunidad, Collection $filas): int
    {
        if ($oportunidad->etapa === 'cerrado_ganado') {
            return count(self::ETAPAS_ABIERTAS) - 1;
        }

        $ordenes = $filas->pluck('etapa_hasta')
            ->merge($filas->pluck('etapa_desde'))
            ->filter()
            ->push($oportunidad->etapa)
            ->map(fn (string $etapa) => CrmOportunidad::ORDEN_ETAPAS[$etapa] ?? -1)
            ->filter(fn (int $orden) => $orden >= 0 && $orden <= 3);

        return $ordenes->isEmpty() ? 0 : $ordenes->max();
    }

    /** Días entre entrar y salir de cada etapa, solo con filas reales (no inferidas) con fecha. */
    private function acumularDias(Collection $filas, array &$dias): void
    {
        $reales = $filas->filter(fn ($fila) => ! $fila->inferido && $fila->cambiado_en !== null);

        foreach ($reales as $entrada) {
            if (! isset($dias[$entrada->etapa_hasta])) {
                continue;
            }
            $salida = $reales->first(fn ($fila) => $fila->etapa_desde === $entrada->etapa_hasta);
            if ($salida) {
                $dias[$entrada->etapa_hasta][] = $entrada->cambiado_en->diffInDays($salida->cambiado_en, true);
            }
        }
    }

    private function mayorFuga(array $etapas): ?string
    {
        $candidata = null;
        foreach ($etapas as $etapa) {
            if ($etapa['llegaron'] < self::MINIMO_MAYOR_FUGA || $etapa['pasa'] === null) {
                continue;
            }
            if ($candidata === null || $etapa['pasa'] < $candidata['pasa']) {
                $candidata = $etapa;
            }
        }

        return $candidata['etapa'] ?? null;
    }
}
