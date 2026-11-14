<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\ConfiguracionPlaneacion;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Temporada;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Resumen de temporada: una fila por semana, del lunes de la fecha de inicio al
 * lunes de la fecha de fin (hoja RESUMEN del Excel), con el disponible y el
 * inventario final también por grupo de balance.
 *
 * Es solo lectura. Las semanas sin programa salen en cero y dejan pasar el
 * inventario del programa existente más cercano hacia atrás; el capturado a
 * mano en un programa manda sobre el arrastre (misma regla que el Tablero).
 *
 * El número de consultas no depende del número de semanas: los programas y sus
 * renglones se cargan juntos con PlanSemana::cargarVarios, los embarques en una
 * sola consulta y la cadena de inventario se recorre en memoria.
 */
class ResumenTemporada
{
    private const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    /** Columnas que se suman en los totales (las de inventario final toman la última semana). */
    private const SUMABLES = ['ha_por_variedad', 'ha_total', 'cajas_por_grupo', 'cajas_total', 'necesidad_por_bodega', 'necesidad_total', 'disponible', 'disponible_por_grupo', 'cargas_eq', 'cargas_por_bodega'];

    public function __construct(private readonly CatalogoPlaneacion $catalogo) {}

    /**
     * @param  int|null  $cultivoId  Cultivo a resumir; por defecto el de la temporada.
     */
    public function de(Temporada $temporada, ?int $cultivoId = null): array
    {
        $cultivoId ??= (int) $temporada->cultivo_id;
        $cajasPorCarga = max(1, (int) ConfiguracionPlaneacion::valor('cajas_por_carga', 1040));

        $primero = $temporada->fecha_inicio->copy()->startOfWeek(Carbon::MONDAY);
        $ultimo = $temporada->fecha_fin->copy()->startOfWeek(Carbon::MONDAY);

        $grupos = $this->catalogo->gruposDeCultivos([$cultivoId]);
        $gruposBalance = [];
        foreach ($grupos as $g) {
            $gruposBalance[$g['clave']] = ['cultivo_id' => $cultivoId];
        }
        $variedades = $this->catalogo->variedades()
            ->filter(fn ($v) => $v->deleted_at === null && (int) $v->cultivo_id === $cultivoId)
            ->values();
        $bodegas = BodegaDestino::orderBy('orden')->orderBy('id')->get(['id', 'nombre', 'is_active']);

        // Programas hasta el último lunes: los anteriores a la temporada aportan el inventario con el que arranca.
        $programas = ProgramaSemanal::whereDate('lunes', '<=', $ultimo->toDateString())->orderBy('lunes')->get();
        $planes = PlanSemana::cargarVarios($programas, $this->catalogo);
        $embarques = $this->cargasPorBodega($programas->pluck('id')->all(), $cultivoId);

        // Cadena de inventario, de programa en programa y en orden de lunes.
        $final = [];
        $alEmpezar = [];
        $filas = [];
        foreach ($programas as $programa) {
            $plan = $planes[$programa->id];

            $inicial = $final;
            foreach ($plan->inventariosManuales as $clave => $cajas) {
                if (isset($gruposBalance[(string) $clave])) {
                    $inicial[(string) $clave] = $cajas;
                }
            }

            $produccion = array_values(array_filter(
                $plan->produccion(),
                fn ($r) => isset($gruposBalance[(string) $r['grupo']]),
            ));
            $necesidades = array_values(array_filter(
                $plan->necesidades(),
                fn ($n) => (int) $n['necesidad']->cultivo_id === $cultivoId,
            ));
            $renglonesNecesidad = array_values(array_filter(
                PlanSemana::renglonesNecesidad($necesidades),
                fn ($r) => isset($gruposBalance[(string) $r['grupo']]),
            ));

            $balance = CalculadoraBalance::calcular($gruposBalance, $produccion, $renglonesNecesidad, $inicial);

            $final = [];
            foreach ($balance as $clave => $g) {
                $final[(string) $clave] = $g['final'];
            }

            if ($plan->lunes < $primero->toDateString()) {
                $alEmpezar = $final;

                continue;
            }

            $filas[$plan->lunes] = $this->fila(
                Carbon::parse($plan->lunes), $plan, $produccion, $necesidades, $balance, $final,
                $grupos, $variedades, $bodegas, $embarques[$programa->id] ?? [], $cajasPorCarga,
            );
        }

        $semanas = [];
        $arrastre = $alEmpezar;
        for ($lunes = $primero->copy(); $lunes->lte($ultimo); $lunes->addWeek()) {
            $fila = $filas[$lunes->toDateString()] ?? null;
            if ($fila !== null) {
                $arrastre = $fila['_final'];
            } else {
                // Sin programa esa semana: ceros y el inventario que venía.
                $fila = $this->fila($lunes->copy(), null, [], [], [], $arrastre, $grupos, $variedades, $bodegas, [], $cajasPorCarga);
            }
            $semanas[] = $fila;
        }

        $semanas = array_map(fn ($s) => array_diff_key($s, ['_final' => true]), $semanas);

        return [
            'temporada' => ['id' => $temporada->id, 'nombre' => $temporada->nombre],
            'grupos' => array_map(fn ($g) => ['id' => $g['id'], 'nombre' => $g['nombre']], $grupos),
            'bodegas' => $bodegas
                ->filter(fn ($b) => $b->is_active || $this->bodegaConDatos($b->id, $semanas))
                ->map(fn ($b) => ['id' => $b->id, 'nombre' => $b->nombre])->values()->all(),
            'variedades' => $variedades->map(fn ($v) => ['id' => $v->id, 'nombre' => $v->nombre])->all(),
            'semanas' => $semanas,
            'totales' => $this->totales($semanas),
        ];
    }

    /**
     * Una semana. Sin $plan sale en cero (con el inventario $final arrastrado).
     *
     * @param  array<string, int>  $final  grupo_clave => cajas al cierre de la semana.
     * @param  array<int, int>  $cargas  bodega_id => cargas embarcadas.
     */
    private function fila(Carbon $lunes, ?PlanSemana $plan, array $produccion, array $necesidades, array $balance, array $final, array $grupos, Collection $variedades, Collection $bodegas, array $cargas, int $cajasPorCarga): array
    {
        $haPorVariedad = [];
        foreach ($variedades as $v) {
            $haPorVariedad[$v->id] = 0.0;
        }
        foreach ($plan?->cortes ?? [] as $corte) {
            if (isset($haPorVariedad[$corte->variedad_id])) {
                $haPorVariedad[$corte->variedad_id] += (float) $corte->hectareas;
            }
        }
        $haPorVariedad = array_map(fn ($ha) => round($ha, 2), $haPorVariedad);

        $cajasPorGrupo = $disponiblePorGrupo = $inventarioPorGrupo = [];
        foreach ($grupos as $g) {
            $cajasPorGrupo[$g['id']] = 0;
            $disponiblePorGrupo[$g['id']] = 0;
            $inventarioPorGrupo[$g['id']] = (int) ($final[$g['clave']] ?? 0);
        }
        foreach ($produccion as $r) {
            $cajasPorGrupo[CatalogoPlaneacion::idPublico((string) $r['grupo'])] += $r['cajas'];
        }
        foreach ($balance as $clave => $g) {
            $disponiblePorGrupo[CatalogoPlaneacion::idPublico((string) $clave)] = array_sum($g['del_dia']);
        }

        $necesidadPorBodega = $cargasPorBodega = [];
        foreach ($bodegas as $b) {
            $necesidadPorBodega[$b->id] = 0;
            $cargasPorBodega[$b->id] = (int) ($cargas[$b->id] ?? 0);
        }
        foreach ($necesidades as $n) {
            $bodegaId = (int) $n['necesidad']->bodega_id;
            $necesidadPorBodega[$bodegaId] = ($necesidadPorBodega[$bodegaId] ?? 0) + array_sum($n['cajas']);
        }

        $cajasTotal = array_sum($cajasPorGrupo);
        $necesidadTotal = array_sum($necesidadPorBodega);
        $disponible = $cajasTotal - $necesidadTotal;

        return [
            'lunes' => $lunes->toDateString(),
            'semana_iso' => (int) $lunes->isoWeek(),
            'mes' => self::MESES[$lunes->month - 1],
            'ha_por_variedad' => $haPorVariedad,
            'ha_total' => round(array_sum($haPorVariedad), 2),
            'cajas_por_grupo' => $cajasPorGrupo,
            'cajas_total' => $cajasTotal,
            'necesidad_por_bodega' => $necesidadPorBodega,
            'necesidad_total' => $necesidadTotal,
            'disponible' => $disponible,
            'disponible_por_grupo' => $disponiblePorGrupo,
            'inventario_final' => array_sum($inventarioPorGrupo),
            'inventario_final_por_grupo' => $inventarioPorGrupo,
            'cargas_eq' => round($disponible / $cajasPorCarga, 2),
            'cargas_por_bodega' => $cargasPorBodega,
            '_final' => $final,
        ];
    }

    /**
     * Cargas embarcadas del cultivo por programa y bodega (full y sencillo
     * juntos), en una sola consulta.
     *
     * @param  int[]  $programaIds
     * @return array<int, array<int, int>> programa_id => [bodega_id => cargas]
     */
    private function cargasPorBodega(array $programaIds, int $cultivoId): array
    {
        if ($programaIds === []) {
            return [];
        }

        $salida = [];
        $filas = ProgramaEmbarque::query()->toBase()
            ->whereIn('programa_id', $programaIds)
            ->where('cultivo_id', $cultivoId)
            ->selectRaw('programa_id, bodega_id, SUM(cargas) as cargas')
            ->groupBy('programa_id', 'bodega_id')
            ->get();
        foreach ($filas as $f) {
            $salida[(int) $f->programa_id][(int) $f->bodega_id] = (int) $f->cargas;
        }

        return $salida;
    }

    /** @param  list<array>  $semanas */
    private function bodegaConDatos(int $bodegaId, array $semanas): bool
    {
        foreach ($semanas as $s) {
            if (($s['necesidad_por_bodega'][$bodegaId] ?? 0) !== 0 || ($s['cargas_por_bodega'][$bodegaId] ?? 0) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Suma de las columnas numéricas; el inventario final es el de la última semana.
     *
     * @param  list<array>  $semanas
     */
    private function totales(array $semanas): array
    {
        $totales = [];
        foreach (self::SUMABLES as $columna) {
            $total = null;
            foreach ($semanas as $s) {
                $valor = $s[$columna];
                if (is_array($valor)) {
                    $total ??= [];
                    foreach ($valor as $clave => $n) {
                        $total[$clave] = ($total[$clave] ?? 0) + $n;
                    }
                } else {
                    $total = ($total ?? 0) + $valor;
                }
            }
            $total ??= 0;
            $totales[$columna] = is_array($total)
                ? array_map(fn ($n) => is_float($n) ? round($n, 2) : $n, $total)
                : (is_float($total) ? round($total, 2) : $total);
        }

        $ultima = $semanas ? end($semanas) : null;
        $totales['inventario_final'] = $ultima['inventario_final'] ?? 0;
        $totales['inventario_final_por_grupo'] = $ultima['inventario_final_por_grupo'] ?? [];

        return $totales;
    }
}
