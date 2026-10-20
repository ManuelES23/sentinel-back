<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Cosecha;

use App\Http\Controllers\Controller;
use App\Models\Etapa;
use App\Models\Temporada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard de cosecha, pestaña Ejecutivo: una fila por etapa con su plan,
 * los cortes por día (cierres y salidas), la venta nacional y el empaque.
 * Solo lectura; el tope por superficie y los porcentajes los calcula el front.
 */
class DashboardCosechaController extends Controller
{
    /** Unidades de venta que se pueden pasar a kilos, con su factor. */
    private const KG_POR_UNIDAD = [
        'kg' => 1, 'kgs' => 1, 'kilo' => 1, 'kilos' => 1, 'kilogramo' => 1, 'kilogramos' => 1,
        't' => 1000, 'ton' => 1000, 'tons' => 1000, 'tonelada' => 1000, 'toneladas' => 1000,
    ];

    /** Una venta cancelada no cuenta como venta nacional. */
    private const VENTA_VIVA = "ventas_cosecha.deleted_at IS NULL AND ventas_cosecha.status <> 'cancelada'";

    public function ejecutivo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'temporada_id' => 'required|integer|exists:temporadas,id',
        ]);

        $temporada = Temporada::findOrFail($datos['temporada_id']);
        $temporadaId = $temporada->id;

        $etapas = Etapa::with([
            'lote:id,nombre,codigo,superficie,numero_lote,zona_cultivo_id,productor_id',
            'lote.productor:id,nombre,apellido',
            'lote.zonaCultivo:id,nombre',
            'variedad:id,nombre,cultivo_id',
            'tipoVariedad:id,nombre,variedad_id',
        ])
            ->whereIn('lote_id', $temporada->lotesActivos()->pluck('lotes.id'))
            ->orderBy('lote_id')
            ->orderBy('orden')
            ->get();

        // Lo cosechado o empacado en una etapa fuera de esta lista (lote inactivo en la
        // temporada o etapa borrada) se cuenta en `sin_etapa` para que no se pierda.
        $enLista = $etapas->pluck('id')->flip()->all();

        $avisos = [];
        $cortes = $this->cortesPorEtapa($temporadaId, $enLista, $avisos);
        $empaque = $this->empaquePorEtapa($temporadaId, $enLista);

        $filas = [];
        foreach ($etapas as $etapa) {
            $cortesEtapa = $cortes['porEtapa'][$etapa->id] ?? [];
            $empaqueEtapa = $empaque['porEtapa'][$etapa->id] ?? null;

            // Una etapa inactiva solo aparece si ya tiene cosecha o empaque.
            if (! $etapa->is_active && ! $cortesEtapa && ! $empaqueEtapa) {
                continue;
            }

            $filas[] = $this->fila($etapa, $cortesEtapa, $empaqueEtapa);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'temporada' => ['id' => $temporada->id, 'nombre' => $temporada->nombre],
                'etapas' => $filas,
                'sin_etapa' => [
                    'kg' => number_format($cortes['sinEtapaKg'], 2, '.', ''),
                    'cajas' => $empaque['sinEtapaCajas'],
                ],
                'avisos' => $avisos,
            ],
        ]);
    }

    /**
     * Cortes por día de cada etapa: hectáreas de los cierres, kilos de las
     * salidas y, en los cierres con venta nacional, los kilos vendidos.
     *
     * @param  array<int, int>  $enLista  Ids de las etapas de la respuesta (como llaves).
     * @param  string[]  $avisos  Se llena con las ventas cuya unidad no se pudo pasar a kilos.
     * @return array{porEtapa: array<int, array>, sinEtapaKg: float}
     */
    private function cortesPorEtapa(int $temporadaId, array $enLista, array &$avisos): array
    {
        // Un corte se identifica por (fecha, venta nacional): [fecha, ha, kg, venta_nacional]
        $porEtapa = [];
        $sumar = function (int $etapaId, string $fecha, bool $nacional, float $ha, float $kg) use (&$porEtapa) {
            $fecha = substr($fecha, 0, 10);
            $clave = $fecha.'|'.(int) $nacional;
            $corte = $porEtapa[$etapaId][$clave] ?? ['fecha' => $fecha, 'ha' => 0.0, 'kg' => 0.0, 'venta_nacional' => $nacional];
            $corte['ha'] += $ha;
            $corte['kg'] += $kg;
            $porEtapa[$etapaId][$clave] = $corte;
        };

        // Cierres por día, separando los que tienen alguna venta no cancelada.
        // La bandera se calcula en una subconsulta (cada cierre una sola vez, aunque tenga
        // varias ventas) y se agrupa por columnas simples: válido con only_full_group_by.
        $cierresConVenta = DB::table('ventas_cosecha')
            ->whereRaw(self::VENTA_VIVA)
            ->select('cierre_cosecha_id')
            ->distinct();
        $cierresMarcados = DB::table('cierres_cosecha')
            ->leftJoinSub($cierresConVenta, 'cv', 'cv.cierre_cosecha_id', '=', 'cierres_cosecha.id')
            ->where('cierres_cosecha.temporada_id', $temporadaId)
            ->whereNull('cierres_cosecha.deleted_at')
            ->whereNotNull('cierres_cosecha.etapa_id')
            ->selectRaw('cierres_cosecha.etapa_id AS etapa_id, cierres_cosecha.fecha_inicio AS fecha_inicio, '
                .'cierres_cosecha.superficie_cosechada AS ha, '
                .'CASE WHEN cv.cierre_cosecha_id IS NULL THEN 0 ELSE 1 END AS con_venta');
        $cierres = DB::query()
            ->fromSub($cierresMarcados, 'c')
            ->selectRaw('c.etapa_id, c.fecha_inicio, c.con_venta, COALESCE(SUM(c.ha), 0) AS ha')
            ->groupBy('c.etapa_id', 'c.fecha_inicio', 'c.con_venta')
            ->get();
        foreach ($cierres as $c) {
            $sumar((int) $c->etapa_id, $c->fecha_inicio, (bool) $c->con_venta, (float) $c->ha, 0.0);
        }

        // Salidas de campo por día; las que no traen etapa, o traen una fuera de la lista, van aparte.
        $sinEtapaKg = 0.0;
        $salidas = DB::table('salidas_campo_cosecha')
            ->where('temporada_id', $temporadaId)
            ->where('eliminado', false)
            ->selectRaw('etapa_id, fecha, COALESCE(SUM(peso_neto_kg), 0) AS kg')
            ->groupBy('etapa_id', 'fecha')
            ->get();
        foreach ($salidas as $s) {
            if ($s->etapa_id === null || ! isset($enLista[(int) $s->etapa_id])) {
                $sinEtapaKg += (float) $s->kg;
            } else {
                $sumar((int) $s->etapa_id, $s->fecha, false, 0.0, (float) $s->kg);
            }
        }

        // Ventas no canceladas, en kilos, en el día del cierre al que pertenecen.
        $ventas = DB::table('ventas_cosecha')
            ->join('cierres_cosecha', 'cierres_cosecha.id', '=', 'ventas_cosecha.cierre_cosecha_id')
            ->where('cierres_cosecha.temporada_id', $temporadaId)
            ->whereNull('cierres_cosecha.deleted_at')
            ->whereNotNull('cierres_cosecha.etapa_id')
            ->whereRaw(self::VENTA_VIVA)
            ->orderBy('ventas_cosecha.id')
            ->get([
                'ventas_cosecha.id', 'ventas_cosecha.cantidad', 'ventas_cosecha.unidad_medida',
                'cierres_cosecha.etapa_id', 'cierres_cosecha.fecha_inicio',
            ]);
        foreach ($ventas as $v) {
            $factor = self::KG_POR_UNIDAD[mb_strtolower(trim((string) $v->unidad_medida))] ?? null;
            if ($factor === null) {
                $avisos[] = "La venta {$v->id} está en «{$v->unidad_medida}» y no se sumó a la venta nacional";

                continue;
            }
            $sumar((int) $v->etapa_id, $v->fecha_inicio, true, 0.0, (float) $v->cantidad * $factor);
        }

        // Orden por fecha y, dentro del día, primero el corte normal.
        foreach ($porEtapa as $etapaId => $lista) {
            ksort($lista);
            $porEtapa[$etapaId] = array_values($lista);
        }

        return ['porEtapa' => $porEtapa, 'sinEtapaKg' => $sinEtapaKg];
    }

    /**
     * Empaque por etapa y cajas embarcadas por destino. Mismo criterio que los
     * reportes de empaque: un pallet sin desglose va a la etapa de su proceso y un
     * pallet con desglose (mixto) se reparte por sus filas de
     * `produccion_empaque_detalles`, cada una con su proceso. La producción de un
     * proceso borrado no cuenta. Las cajas sin etapa, o de una etapa fuera de la
     * lista, se cuentan aparte.
     *
     * @param  array<int, int>  $enLista  Ids de las etapas de la respuesta (como llaves).
     * @return array{porEtapa: array<int, array>, sinEtapaCajas: int}
     */
    private function empaquePorEtapa(int $temporadaId, array $enLista): array
    {
        $porEtapa = [];
        $sinEtapaCajas = 0;
        $etapaValida = fn ($etapaId) => $etapaId !== null && isset($enLista[(int) $etapaId]);
        $fila = function (int $etapaId) use (&$porEtapa) {
            $porEtapa[$etapaId] ??= ['cajas' => 0, 'kg' => 0.0, 'cajas_exportacion' => 0, 'cajas_nacional' => 0];
        };

        // Partes de cada pallet: [produccion_id => ['total' => cajas del pallet, 'partes' => [[etapa_id, cajas], …]]]
        $pallets = [];
        foreach ($this->partesDeProduccion($temporadaId) as $parte) {
            $cajas = (int) $parte->cajas;
            $pallets[$parte->produccion_id]['total'] = (int) $parte->total_pallet;
            $pallets[$parte->produccion_id]['partes'][] = [$parte->etapa_id, $cajas];

            if (! $etapaValida($parte->etapa_id)) {
                $sinEtapaCajas += $cajas;

                continue;
            }
            $etapaId = (int) $parte->etapa_id;
            $fila($etapaId);
            $porEtapa[$etapaId]['cajas'] += $cajas;
            $porEtapa[$etapaId]['kg'] += (float) $parte->kg;
        }

        // Cajas embarcadas por pallet y destino, repartidas entre las partes del pallet.
        $embarcadas = DB::table('embarque_empaque_detalles as d')
            ->join('embarques_empaque as e', 'e.id', '=', 'd.embarque_id')
            ->join('produccion_empaque as p', 'p.id', '=', 'd.produccion_id')
            ->where('p.temporada_id', $temporadaId)
            ->whereNull('e.deleted_at')
            ->whereNull('p.deleted_at')
            ->selectRaw('d.produccion_id, e.tipo_venta, COALESCE(SUM(d.cajas), 0) AS cajas')
            ->groupBy('d.produccion_id', 'e.tipo_venta')
            ->get();
        foreach ($embarcadas as $e) {
            $pallet = $pallets[$e->produccion_id] ?? null;
            if ($pallet === null) {
                continue; // pallet de un proceso borrado
            }
            $campo = $e->tipo_venta === 'exportacion' ? 'cajas_exportacion' : 'cajas_nacional';
            $reparto = $this->repartir((int) $e->cajas, $pallet['total'], array_column($pallet['partes'], 1));
            foreach ($pallet['partes'] as $i => [$etapaId]) {
                if ($reparto[$i] > 0 && $etapaValida($etapaId)) {
                    $fila((int) $etapaId);
                    $porEtapa[(int) $etapaId][$campo] += $reparto[$i];
                }
            }
        }

        return ['porEtapa' => $porEtapa, 'sinEtapaCajas' => $sinEtapaCajas];
    }

    /**
     * Una fila por pallet sin desglose y una por renglón de desglose de un pallet
     * mixto, con la etapa de su proceso (`null` si no hay proceso o no trae etapa).
     * Los procesos borrados se excluyen.
     */
    private function partesDeProduccion(int $temporadaId): Collection
    {
        $simples = DB::table('produccion_empaque as p')
            ->leftJoin('proceso_empaque as pr', 'pr.id', '=', 'p.proceso_id')
            ->where('p.temporada_id', $temporadaId)
            ->whereNull('p.deleted_at')
            ->whereNull('pr.deleted_at')
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('produccion_empaque_detalles as dd')
                    ->whereColumn('dd.produccion_id', 'p.id');
            })
            ->select([
                'p.id as produccion_id', 'pr.etapa_id', 'p.total_cajas as cajas',
                'p.peso_neto_kg as kg', 'p.total_cajas as total_pallet',
            ]);

        $mixtos = DB::table('produccion_empaque as p')
            ->join('produccion_empaque_detalles as d', 'd.produccion_id', '=', 'p.id')
            ->leftJoin('proceso_empaque as pr', 'pr.id', '=', 'd.proceso_id')
            ->where('p.temporada_id', $temporadaId)
            ->whereNull('p.deleted_at')
            ->whereNull('pr.deleted_at')
            ->select([
                'p.id as produccion_id', 'pr.etapa_id', 'd.total_cajas as cajas',
                'd.peso_neto_kg as kg', 'p.total_cajas as total_pallet',
            ]);

        return $simples->unionAll($mixtos)->get();
    }

    /**
     * Reparte $cantidad cajas en enteros proporcionales a $partes (resto mayor), sobre
     * una base de $total cajas: si las partes suman menos que el pallet (un renglón de
     * un proceso borrado), lo que le tocaba a ese renglón no se asigna a nadie.
     *
     * @param  int[]  $partes
     * @return int[] con las mismas llaves que $partes
     */
    private function repartir(int $cantidad, int $total, array $partes): array
    {
        $suma = array_sum($partes);
        $base = max($total, $suma);
        if ($base <= 0) {
            return array_map(fn () => 0, $partes);
        }

        $asignable = (int) round($cantidad * $suma / $base);
        $enteros = [];
        $restos = [];
        foreach ($partes as $i => $parte) {
            $exacto = $suma > 0 ? $asignable * $parte / $suma : 0;
            $enteros[$i] = (int) floor($exacto);
            $restos[$i] = $exacto - $enteros[$i];
        }
        arsort($restos);
        $falta = $asignable - array_sum($enteros);
        foreach (array_keys($restos) as $i) {
            if ($falta <= 0) {
                break;
            }
            $enteros[$i]++;
            $falta--;
        }

        return $enteros;
    }

    /** Arma la fila de una etapa con el formato de la respuesta. */
    private function fila(Etapa $etapa, array $cortes, ?array $empaque): array
    {
        $lote = $etapa->lote;
        $productor = $lote?->productor;

        $nacionalHa = 0.0;
        $nacionalKg = 0.0;
        foreach ($cortes as $corte) {
            if ($corte['venta_nacional']) {
                $nacionalHa += $corte['ha'];
                $nacionalKg += $corte['kg'];
            }
        }

        $empaque ??= ['cajas' => 0, 'kg' => 0.0, 'cajas_exportacion' => 0, 'cajas_nacional' => 0];

        return [
            'id' => $etapa->id,
            'codigo' => $etapa->codigo,
            'nombre' => $etapa->nombre,
            'is_active' => (bool) $etapa->is_active,
            'superficie' => number_format((float) $etapa->superficie, 4, '.', ''),
            'lote' => $lote ? ['id' => $lote->id, 'nombre' => $lote->nombre] : null,
            'productor' => $productor
                ? ['id' => $productor->id, 'nombre' => trim($productor->nombre.' '.$productor->apellido)]
                : null,
            'zona' => $lote?->zonaCultivo
                ? ['id' => $lote->zonaCultivo->id, 'nombre' => $lote->zonaCultivo->nombre]
                : null,
            'variedad' => $etapa->variedad
                ? ['id' => $etapa->variedad->id, 'nombre' => $etapa->variedad->nombre]
                : null,
            'tipo_variedad' => $etapa->tipoVariedad
                ? ['id' => $etapa->tipoVariedad->id, 'nombre' => $etapa->tipoVariedad->nombre]
                : null,
            'fecha_siembra_estimada' => $etapa->fecha_siembra_estimada?->format('Y-m-d'),
            'fecha_siembra_real' => $etapa->fecha_siembra_real?->format('Y-m-d'),
            'fecha_cosecha_estimada' => $etapa->fecha_cosecha_estimada?->format('Y-m-d'),
            'fecha_cosecha_proyectada' => $etapa->fecha_cosecha_proyectada?->format('Y-m-d'),
            'cortes' => array_map(fn ($c) => [
                'fecha' => $c['fecha'],
                'ha' => number_format($c['ha'], 4, '.', ''),
                'kg' => number_format($c['kg'], 2, '.', ''),
                'venta_nacional' => $c['venta_nacional'],
            ], $cortes),
            'venta_nacional' => [
                'ha' => number_format($nacionalHa, 4, '.', ''),
                'kg' => number_format($nacionalKg, 2, '.', ''),
            ],
            'empaque' => [
                'cajas' => $empaque['cajas'],
                'kg' => number_format($empaque['kg'], 2, '.', ''),
                'cajas_exportacion' => $empaque['cajas_exportacion'],
                'cajas_nacional' => $empaque['cajas_nacional'],
            ],
        ];
    }
}
