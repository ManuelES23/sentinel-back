<?php

namespace App\Services\Planeacion;

use App\Models\CierreCosecha;
use App\Models\Etapa;
use App\Models\Planeacion\ProgramaCorte;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\SalidaCampoCosecha;
use App\Models\Temporada;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Avance de cosecha de una semana (lunes a domingo): el plan de corte
 * (hectáreas por etapa y día) contra la cosecha real, por etapa.
 *
 * Plan: los `programa_cortes` del programa de la semana, sumando renglones por
 * etapa y día; los cortes sin etapa quedan aparte, por variedad
 * (`sin_etapa.plan`). Sin programa de la semana el plan es cero y no se crea el
 * programa.
 *
 * Real: los cierres de cosecha (abiertos y cerrados, sin borrados) por
 * `fecha_inicio`, sumando `superficie_cosechada`, sin tope por superficie ni
 * separación de venta nacional. Los kilos de campo (`peso_neto_kg` de las
 * salidas no eliminadas, por fecha) se muestran aparte y nunca se convierten a
 * hectáreas. Lo real sin etapa va a `sin_etapa`; una etapa con real o kilos pero
 * sin plan, a `sin_plan` (con el plan en ceros).
 *
 * `temporada_id`/`cultivo_id` filtran por la temporada de cada dato (el cultivo
 * es el de su temporada).
 *
 * `trabaja_domingo` lee `domingo_corte` (verdadero sin programa). Sin domingo
 * el real de ese día se cuenta y se muestra igual.
 *
 * Atraso por renglón: ver `AtrasoSemana::deuda` (con compensación, a 2
 * decimales). `para_hoy` = plan de hoy + atraso, solo si hoy cae en la semana;
 * en una semana futura no hay atraso y en una pasada es el de toda la semana.
 * Hectáreas y kilos van redondeados a 2 decimales.
 */
final class AvanceCosecha
{
    public function __construct(private CatalogoPlaneacion $catalogo) {}

    public function de(string $lunes, ?int $temporadaId, ?int $cultivoId, CarbonInterface $hoy): array
    {
        $dias = CalculadoraBalance::DIAS;
        $ceros = array_fill(0, $dias, 0.0);

        $inicio = CarbonImmutable::parse($lunes)->startOfDay();
        $finExclusivo = $inicio->addDays($dias)->toDateString();
        $semana = AtrasoSemana::dias($lunes, $hoy);
        $hoyEnSemana = $semana['hoy_en_semana'];

        $programa = ProgramaSemanal::whereDate('lunes', $lunes)->first(['id', 'domingo_corte']);
        $trabajaDomingo = $programa ? $programa->trabajaDomingo('corte') : true;

        $temporadaIds = $this->temporadasDelFiltro($temporadaId, $cultivoId);
        $filtrar = fn ($consulta) => $temporadaIds === null ? $consulta : $consulta->whereIn('temporada_id', $temporadaIds);
        $indiceDe = fn (string $fecha): int => (int) $inicio->diffInDays(CarbonImmutable::parse($fecha)->startOfDay(), false);

        // Plan: por etapa y día; sin etapa, por variedad y día.
        $plan = [];
        $variedadDeCorte = [];
        $planSinEtapa = [];
        $cortes = $programa
            ? $filtrar(ProgramaCorte::where('programa_id', $programa->id))->orderBy('id')->get(['id', 'etapa_id', 'variedad_id', 'dia', 'hectareas'])
            : collect();
        foreach ($cortes as $c) {
            $dia = (int) $c->dia;
            if ($dia < 0 || $dia >= $dias) {
                continue;
            }
            if ($c->etapa_id === null) {
                $planSinEtapa[(int) $c->variedad_id] ??= $ceros;
                $planSinEtapa[(int) $c->variedad_id][$dia] += (float) $c->hectareas;

                continue;
            }
            $plan[(int) $c->etapa_id] ??= $ceros;
            $plan[(int) $c->etapa_id][$dia] += (float) $c->hectareas;
            $variedadDeCorte[(int) $c->etapa_id] ??= $c->variedad_id === null ? null : (int) $c->variedad_id;
        }

        // Real: hectáreas de los cierres y kilos de las salidas, por etapa (0 = sin etapa) y día.
        $real = [];
        $cierres = $filtrar(CierreCosecha::query()
            ->where('fecha_inicio', '>=', $lunes)
            ->where('fecha_inicio', '<', $finExclusivo)
            ->selectRaw('etapa_id, DATE(fecha_inicio) as fecha, SUM(superficie_cosechada) as total')
            ->groupBy('etapa_id', DB::raw('DATE(fecha_inicio)')))
            ->get();
        foreach ($cierres as $c) {
            $dia = $indiceDe((string) $c->fecha);
            if ($dia >= 0 && $dia < $dias) {
                $real[(int) $c->etapa_id] ??= $ceros;
                $real[(int) $c->etapa_id][$dia] += (float) $c->total;
            }
        }

        $kilos = [];
        $salidas = $filtrar(SalidaCampoCosecha::query()
            ->where('eliminado', false)
            ->where('fecha', '>=', $lunes)
            ->where('fecha', '<', $finExclusivo)
            ->selectRaw('etapa_id, DATE(fecha) as dia, SUM(peso_neto_kg) as total')
            ->groupBy('etapa_id', DB::raw('DATE(fecha)')))
            ->get();
        foreach ($salidas as $s) {
            $dia = $indiceDe((string) $s->dia);
            if ($dia >= 0 && $dia < $dias) {
                $kilos[(int) $s->etapa_id] ??= $ceros;
                $kilos[(int) $s->etapa_id][$dia] += (float) $s->total;
            }
        }

        // Etapas citadas por el plan o por lo real (incluidas las borradas), con su lote.
        $etapaIds = array_values(array_unique(array_filter(
            array_merge(array_keys($plan), array_keys($real), array_keys($kilos)),
        )));
        $etapas = $etapaIds
            ? Etapa::withTrashed()
                ->with(['lote' => fn ($q) => $q->withTrashed()->select('id', 'nombre')])
                ->whereIn('id', $etapaIds)
                ->get(['id', 'lote_id', 'nombre', 'codigo', 'variedad_id', 'orden'])
                ->keyBy('id')
            : collect();
        $variedades = $this->catalogo->variedades();

        $redondear = fn (array $serie): array => array_map(fn ($v) => round($v, 2), $serie);
        $fila = function (int $etapaId, bool $conPlan) use ($etapas, $variedades, $plan, $real, $kilos, $variedadDeCorte, $ceros, $redondear, $semana): array {
            $etapa = $etapas->get($etapaId);
            $variedadId = $etapa?->variedad_id ?? ($variedadDeCorte[$etapaId] ?? null);
            $planFila = $conPlan ? $redondear($plan[$etapaId] ?? $ceros) : $ceros;
            $realFila = $redondear($real[$etapaId] ?? $ceros);
            $atraso = $conPlan ? (float) AtrasoSemana::deuda($planFila, $realFila, $semana['dias_pasados'], 2) : 0.0;

            return [
                'etapa_id' => $etapaId,
                'lote' => $etapa?->lote?->nombre,
                'etapa' => $etapa?->nombre,
                'codigo' => $etapa?->codigo,
                'variedad_id' => $variedadId === null ? null : (int) $variedadId,
                'variedad' => $variedadId === null ? null : $variedades->get((int) $variedadId)?->nombre,
                'plan' => $planFila,
                'real' => $realFila,
                'kilos' => $redondear($kilos[$etapaId] ?? $ceros),
                'atraso' => $atraso,
                'para_hoy' => $conPlan && $semana['hoy_en_semana'] ? round($planFila[$semana['indice_hoy']] + $atraso, 2) : null,
            ];
        };

        $renglones = [];
        $sinPlan = [];
        foreach ($etapaIds as $etapaId) {
            if (isset($plan[$etapaId])) {
                $renglones[] = $fila($etapaId, true);
            } else {
                $sinPlan[] = $fila($etapaId, false);
            }
        }
        $orden = function (array $f) use ($etapas): array {
            $etapa = $etapas->get($f['etapa_id']);

            return [
                mb_strtolower((string) $f['lote']), (int) $etapa?->lote_id,
                (int) ($etapa?->orden ?? PHP_INT_MAX), mb_strtolower((string) $f['etapa']), $f['etapa_id'],
            ];
        };
        $comparar = fn (array $a, array $b) => $orden($a) <=> $orden($b);
        usort($renglones, $comparar);
        usort($sinPlan, $comparar);

        // Plan sin etapa, por variedad (en orden de nombre).
        $planSinEtapaLista = [];
        foreach ($planSinEtapa as $variedadId => $serie) {
            $planSinEtapaLista[] = [
                'variedad_id' => $variedadId,
                'variedad' => $variedades->get($variedadId)?->nombre,
                'plan' => $redondear($serie),
            ];
        }
        usort($planSinEtapaLista, fn ($a, $b) => [mb_strtolower((string) $a['variedad']), $a['variedad_id']] <=> [mb_strtolower((string) $b['variedad']), $b['variedad_id']]);

        $sinEtapaReal = $redondear($real[0] ?? $ceros);
        $sinEtapaKilos = $redondear($kilos[0] ?? $ceros);

        $suma = fn (array $lista, string $campo): float => array_sum(array_map(fn ($f) => array_sum($f[$campo]), $lista));
        $todas = array_merge($renglones, $sinPlan);

        return [
            'lunes' => $inicio->toDateString(),
            'hoy' => $semana['hoy'],
            'dias_pasados' => $semana['dias_pasados'],
            'trabaja_domingo' => $trabajaDomingo,
            'renglones' => $renglones,
            'sin_plan' => $sinPlan,
            'sin_etapa' => [
                'plan' => $planSinEtapaLista,
                'real' => $sinEtapaReal,
                'kilos' => $sinEtapaKilos,
            ],
            'totales' => [
                'plan' => round($suma($renglones, 'plan') + $suma($planSinEtapaLista, 'plan'), 2),
                'real' => round($suma($todas, 'real') + array_sum($sinEtapaReal), 2),
                'atraso' => round(array_sum(array_column($renglones, 'atraso')), 2),
                'para_hoy' => $hoyEnSemana ? round(array_sum(array_column($renglones, 'para_hoy')), 2) : null,
                'kilos' => round($suma($todas, 'kilos') + array_sum($sinEtapaKilos), 2),
            ],
        ];
    }

    /**
     * Temporadas que cumplen el filtro (por id y/o por cultivo), incluidas las
     * borradas; `null` si no hay filtro (no se acota por temporada).
     *
     * @return list<int>|null
     */
    private function temporadasDelFiltro(?int $temporadaId, ?int $cultivoId): ?array
    {
        if ($temporadaId === null && $cultivoId === null) {
            return null;
        }

        return Temporada::withTrashed()
            ->when($temporadaId !== null, fn ($q) => $q->whereKey($temporadaId))
            ->when($cultivoId !== null, fn ($q) => $q->where('cultivo_id', $cultivoId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
