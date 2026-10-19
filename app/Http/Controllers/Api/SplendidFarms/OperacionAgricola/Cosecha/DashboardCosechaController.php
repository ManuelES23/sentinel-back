<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Cosecha;

use App\Http\Controllers\Controller;
use App\Models\Etapa;
use App\Models\Temporada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $avisos = [];
        $cortes = $this->cortesPorEtapa($temporadaId, $avisos);
        $empaque = $this->empaquePorEtapa($temporadaId);

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
     * @param  string[]  $avisos  Se llena con las ventas cuya unidad no se pudo pasar a kilos.
     * @return array{porEtapa: array<int, array>, sinEtapaKg: float}
     */
    private function cortesPorEtapa(int $temporadaId, array &$avisos): array
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

        // Salidas de campo por día; las que no traen etapa van aparte.
        $sinEtapaKg = 0.0;
        $salidas = DB::table('salidas_campo_cosecha')
            ->where('temporada_id', $temporadaId)
            ->where('eliminado', false)
            ->selectRaw('etapa_id, fecha, COALESCE(SUM(peso_neto_kg), 0) AS kg')
            ->groupBy('etapa_id', 'fecha')
            ->get();
        foreach ($salidas as $s) {
            if ($s->etapa_id === null) {
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
     * Empaque por etapa (la del proceso que dio origen a la producción) y
     * cajas embarcadas por destino. La producción sin etapa se cuenta aparte.
     *
     * @return array{porEtapa: array<int, array>, sinEtapaCajas: int}
     */
    private function empaquePorEtapa(int $temporadaId): array
    {
        $porEtapa = [];
        $sinEtapaCajas = 0;

        $produccion = DB::table('produccion_empaque as p')
            ->leftJoin('proceso_empaque as pr', 'pr.id', '=', 'p.proceso_id')
            ->where('p.temporada_id', $temporadaId)
            ->whereNull('p.deleted_at')
            ->selectRaw('pr.etapa_id, COALESCE(SUM(p.total_cajas), 0) AS cajas, COALESCE(SUM(p.peso_neto_kg), 0) AS kg')
            ->groupBy('pr.etapa_id')
            ->get();
        foreach ($produccion as $p) {
            if ($p->etapa_id === null) {
                $sinEtapaCajas += (int) $p->cajas;

                continue;
            }
            $porEtapa[(int) $p->etapa_id] = [
                'cajas' => (int) $p->cajas,
                'kg' => (float) $p->kg,
                'cajas_exportacion' => 0,
                'cajas_nacional' => 0,
            ];
        }

        $embarcadas = DB::table('embarque_empaque_detalles as d')
            ->join('embarques_empaque as e', 'e.id', '=', 'd.embarque_id')
            ->join('produccion_empaque as p', 'p.id', '=', 'd.produccion_id')
            ->join('proceso_empaque as pr', 'pr.id', '=', 'p.proceso_id')
            ->where('p.temporada_id', $temporadaId)
            ->whereNull('e.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereNotNull('pr.etapa_id')
            ->selectRaw('pr.etapa_id, e.tipo_venta, COALESCE(SUM(d.cajas), 0) AS cajas')
            ->groupBy('pr.etapa_id', 'e.tipo_venta')
            ->get();
        foreach ($embarcadas as $e) {
            $etapaId = (int) $e->etapa_id;
            $porEtapa[$etapaId] ??= ['cajas' => 0, 'kg' => 0.0, 'cajas_exportacion' => 0, 'cajas_nacional' => 0];
            $campo = $e->tipo_venta === 'exportacion' ? 'cajas_exportacion' : 'cajas_nacional';
            $porEtapa[$etapaId][$campo] += (int) $e->cajas;
        }

        return ['porEtapa' => $porEtapa, 'sinEtapaCajas' => $sinEtapaCajas];
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
