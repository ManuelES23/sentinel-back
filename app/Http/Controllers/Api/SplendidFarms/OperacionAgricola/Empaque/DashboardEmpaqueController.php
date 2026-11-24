<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\CalidadEmpaque;
use App\Models\EmbarqueEmpaque;
use App\Models\ProcesoEmpaque;
use App\Models\ProduccionEmpaque;
use App\Models\RecepcionEmpaque;
use App\Models\RezagaEmpaque;
use App\Models\SalidaRezagaEmpaque;
use App\Models\Temporada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardEmpaqueController extends Controller
{
    public function kpis(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|integer|exists:temporadas,id',
            'entity_id'    => 'nullable|integer|exists:entities,id',
            'from'         => 'nullable|date',
            'to'           => 'nullable|date|after_or_equal:from',
        ]);

        $temporadaId = $validated['temporada_id'];
        $entityId    = $validated['entity_id'] ?? null;
        $from        = $validated['from'] ?? null;
        $to          = $validated['to'] ?? null;
        $hoy         = Carbon::today('America/Mexico_City')->toDateString();
        // Una sola consulta por petición: solo una temporada en bins recibe las claves de bins.
        $enBins      = Temporada::esDeBins((int) $temporadaId);

        // ── helpers de filtro ──────────────────────────────────────────────

        $applyBase = function ($query) use ($temporadaId, $entityId) {
            $query->where('temporada_id', $temporadaId);
            if ($entityId) {
                $query->where('entity_id', $entityId);
            }
            return $query;
        };

        $applyRange = function ($query, string $col) use ($from, $to) {
            if ($from) $query->whereDate($col, '>=', $from);
            if ($to)   $query->whereDate($col, '<=', $to);
            return $query;
        };

        // ── Recepciones ────────────────────────────────────────────────────

        $recBase = RecepcionEmpaque::query();
        $applyBase($recBase);

        // En bins, la recepción también suma los bins recibidos (mismo filtro que los kg).
        $binsRecepcion = $enBins ? ', COALESCE(SUM(cantidad_recibida),0) as bins' : '';

        $recHoy = (clone $recBase)
            ->whereDate('fecha_recepcion', $hoy)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(peso_recibido_kg),0) as kg'.$binsRecepcion)
            ->first();

        $recPeriodo = (clone $recBase);
        $applyRange($recPeriodo, 'fecha_recepcion');
        $recPeriodo = $recPeriodo
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(peso_recibido_kg),0) as kg, COUNT(DISTINCT productor_id) as productores'.$binsRecepcion)
            ->first();

        // ── Producción ─────────────────────────────────────────────────────

        $prodBase = ProduccionEmpaque::query();
        $applyBase($prodBase);

        // Hoy - pallets (is_cola = 0)
        $prodHoyPallets = (clone $prodBase)
            ->whereDate('fecha_produccion', $hoy)
            ->where('is_cola', false)
            ->selectRaw('COUNT(DISTINCT numero_pallet) as pallets, COALESCE(SUM(total_cajas),0) as cajas, COALESCE(SUM(COALESCE(peso_bascula_kg, peso_neto_kg)),0) as kg')
            ->first();

        // Período
        $prodPeriodo = (clone $prodBase);
        $applyRange($prodPeriodo, 'fecha_produccion');
        $prodPeriodoPallets = (clone $prodPeriodo)
            ->where('is_cola', false)
            ->selectRaw('COUNT(DISTINCT numero_pallet) as pallets, COALESCE(SUM(total_cajas),0) as cajas, COALESCE(SUM(COALESCE(peso_bascula_kg, peso_neto_kg)),0) as kg')
            ->first();

        // Cuarto frío (snapshot actual, independiente de rango)
        $cuartoFrioQ = ProduccionEmpaque::query();
        $applyBase($cuartoFrioQ);
        $cuartoFrio = $cuartoFrioQ
            ->whereNotIn('status', ['embarcado', 'eliminado'])
            ->where('en_cuarto_frio', true)
            ->where('is_cola', false)
            ->selectRaw('COUNT(DISTINCT numero_pallet) as pallets, COALESCE(SUM(total_cajas),0) as cajas, COALESCE(SUM(COALESCE(peso_bascula_kg, peso_neto_kg)),0) as kg')
            ->first();

        // Disponible sin embarcar (snapshot)
        $disponibleQ = ProduccionEmpaque::query();
        $applyBase($disponibleQ);
        $disponible = $disponibleQ
            ->whereNotIn('status', ['embarcado', 'eliminado'])
            ->where('is_cola', false)
            ->selectRaw('COUNT(DISTINCT numero_pallet) as pallets, COALESCE(SUM(total_cajas),0) as cajas')
            ->first();

        // ── Embarques ──────────────────────────────────────────────────────

        $embBase = EmbarqueEmpaque::query();
        $applyBase($embBase);

        $embHoy = (clone $embBase)
            ->whereDate('fecha_embarque', $hoy)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(total_pallets),0) as pallets, COALESCE(SUM(COALESCE(peso_bascula_total_kg,peso_total_kg)),0) as kg')
            ->first();

        $embPeriodo = (clone $embBase);
        $applyRange($embPeriodo, 'fecha_embarque');
        $embPeriodo = $embPeriodo
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(total_pallets),0) as pallets, COALESCE(SUM(COALESCE(peso_bascula_total_kg,peso_total_kg)),0) as kg, COUNT(DISTINCT CASE WHEN genera_manifiesto THEN id END) as manifiestos')
            ->first();

        // ── Rezaga ─────────────────────────────────────────────────────────

        $rezBase = RezagaEmpaque::query();
        $applyBase($rezBase);

        $rezPeriodo = (clone $rezBase);
        $applyRange($rezPeriodo, 'fecha');
        $rezPeriodo = $rezPeriodo
            ->selectRaw('COALESCE(SUM(cantidad_kg),0) as generada_kg')
            ->first();

        $rezDisponible = (clone $rezBase)
            ->where('status', 'pendiente')
            ->selectRaw('COALESCE(SUM(cantidad_kg),0) as disponible_kg')
            ->first();

        // En bins: disponible = bins que quedan en rezaga pendiente; generada = los que
        // quedan (de cualquier status) más los ya vendidos de las rezagas del período.
        $rezBinsDisponible = 0;
        $rezBinsGenerada = 0;
        if ($enBins) {
            $rezBinsDisponible = (int) (clone $rezBase)
                ->where('status', 'pendiente')
                ->sum('cantidad_bins');

            $rezBinsPeriodo = (clone $rezBase);
            $applyRange($rezBinsPeriodo, 'fecha');
            $rezBinsRestantes = (int) $rezBinsPeriodo->sum('cantidad_bins');

            $rezBinsVendidos = DB::table('venta_rezaga_empaque_detalles as d')
                ->join('rezaga_empaque as r', 'r.id', '=', 'd.rezaga_id')
                ->whereNull('r.deleted_at')
                ->where('r.temporada_id', $temporadaId)
                ->when($entityId, fn ($q) => $q->where('r.entity_id', $entityId))
                ->when($from, fn ($q) => $q->whereDate('r.fecha', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('r.fecha', '<=', $to))
                ->sum('d.bins');

            $rezBinsGenerada = $rezBinsRestantes + (int) $rezBinsVendidos;
        }

        // ── Salida Rezaga ──────────────────────────────────────────────────

        $salidaBase = SalidaRezagaEmpaque::query();
        $applyBase($salidaBase);

        $salidaHoy = (clone $salidaBase)
            ->whereDate('fecha_venta', $hoy)
            ->selectRaw('COALESCE(SUM(total_peso_kg),0) as kg, COALESCE(SUM(monto_total),0) as monto')
            ->first();

        $salidaPeriodo = (clone $salidaBase);
        $applyRange($salidaPeriodo, 'fecha_venta');
        $salidaPeriodo = $salidaPeriodo
            ->selectRaw('COALESCE(SUM(total_peso_kg),0) as kg, COALESCE(SUM(monto_total),0) as monto')
            ->first();

        // ── Calidad ────────────────────────────────────────────────────────

        $calBase = CalidadEmpaque::query();
        $applyBase($calBase);

        $calPeriodo = (clone $calBase);
        $applyRange($calPeriodo, 'fecha_evaluacion');
        $calPeriodo = $calPeriodo
            ->selectRaw('COUNT(*) as muestreos, COALESCE(AVG(porcentaje_cumple),0) as promedio_cumple, COALESCE(AVG(porcentaje_defectos),0) as promedio_defectos')
            ->first();

        // ── KPIs derivados ─────────────────────────────────────────────────

        $kgRecibido  = floatval($recPeriodo->kg ?? 0);
        $kgProducido = floatval($prodPeriodoPallets->kg ?? 0);
        $kgRezaga    = floatval($rezPeriodo->generada_kg ?? 0);

        $yieldPct  = $kgRecibido > 0 ? round($kgProducido / $kgRecibido * 100, 2) : null;
        $rezagaPct = $kgRecibido > 0 ? round($kgRezaga    / $kgRecibido * 100, 2) : null;

        // ── Proceso por status ─────────────────────────────────────────────

        $procesoQ = ProcesoEmpaque::query();
        $applyBase($procesoQ);
        $applyRange($procesoQ, 'fecha_entrada');
        $procesoStatus = $procesoQ
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        // ── Serie diaria (últimos 45 días del período) ──────────────────────

        $serieFrom = $from ?? Carbon::today('America/Mexico_City')->subDays(44)->toDateString();
        $serieTo   = $to   ?? $hoy;

        // Recepciones diarias
        $recDiarias = RecepcionEmpaque::query()
            ->where('temporada_id', $temporadaId)
            ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
            ->whereDate('fecha_recepcion', '>=', $serieFrom)
            ->whereDate('fecha_recepcion', '<=', $serieTo)
            ->selectRaw('DATE(fecha_recepcion) as fecha, COUNT(*) as recepciones, COALESCE(SUM(peso_recibido_kg),0) as rec_kg')
            ->groupBy(DB::raw('DATE(fecha_recepcion)'))
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha');

        // Producción diaria
        $prodDiarias = ProduccionEmpaque::query()
            ->where('temporada_id', $temporadaId)
            ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
            ->where('is_cola', false)
            ->whereDate('fecha_produccion', '>=', $serieFrom)
            ->whereDate('fecha_produccion', '<=', $serieTo)
            ->selectRaw('DATE(fecha_produccion) as fecha, COUNT(DISTINCT numero_pallet) as pallets, COALESCE(SUM(total_cajas),0) as cajas, COALESCE(SUM(COALESCE(peso_bascula_kg, peso_neto_kg)),0) as prod_kg')
            ->groupBy(DB::raw('DATE(fecha_produccion)'))
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha');

        // Rezaga diaria
        $rezDiarias = RezagaEmpaque::query()
            ->where('temporada_id', $temporadaId)
            ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
            ->whereDate('fecha', '>=', $serieFrom)
            ->whereDate('fecha', '<=', $serieTo)
            ->selectRaw('DATE(fecha) as fecha, COALESCE(SUM(cantidad_kg),0) as rezaga_kg')
            ->groupBy(DB::raw('DATE(fecha)'))
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha');

        // Embarques diarios
        $embDiarios = EmbarqueEmpaque::query()
            ->where('temporada_id', $temporadaId)
            ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
            ->whereDate('fecha_embarque', '>=', $serieFrom)
            ->whereDate('fecha_embarque', '<=', $serieTo)
            ->selectRaw('DATE(fecha_embarque) as fecha, COALESCE(SUM(total_pallets),0) as embarque_pallets, COALESCE(SUM(COALESCE(peso_bascula_total_kg,peso_total_kg)),0) as embarque_kg')
            ->groupBy(DB::raw('DATE(fecha_embarque)'))
            ->orderBy('fecha')
            ->get()
            ->keyBy('fecha');

        // Unificar serie diaria
        $allDates = collect(array_unique(array_merge(
            $recDiarias->keys()->toArray(),
            $prodDiarias->keys()->toArray(),
            $rezDiarias->keys()->toArray(),
            $embDiarios->keys()->toArray(),
        )))->sort()->values();

        $serieDiaria = $allDates->map(function ($fecha) use ($recDiarias, $prodDiarias, $rezDiarias, $embDiarios) {
            return [
                'fecha'            => $fecha,
                'recepciones'      => intval($recDiarias[$fecha]->recepciones ?? 0),
                'rec_kg'           => round(floatval($recDiarias[$fecha]->rec_kg ?? 0), 1),
                'pallets'          => intval($prodDiarias[$fecha]->pallets ?? 0),
                'cajas'            => intval($prodDiarias[$fecha]->cajas ?? 0),
                'prod_kg'          => round(floatval($prodDiarias[$fecha]->prod_kg ?? 0), 1),
                'rezaga_kg'        => round(floatval($rezDiarias[$fecha]->rezaga_kg ?? 0), 1),
                'embarque_pallets' => intval($embDiarios[$fecha]->embarque_pallets ?? 0),
                'embarque_kg'      => round(floatval($embDiarios[$fecha]->embarque_kg ?? 0), 1),
            ];
        })->values()->all();

        // ── Top Productores ────────────────────────────────────────────────

        $topProductores = RecepcionEmpaque::query()
            ->where('temporada_id', $temporadaId)
            ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
            ->when($from, fn($q) => $q->whereDate('fecha_recepcion', '>=', $from))
            ->when($to,   fn($q) => $q->whereDate('fecha_recepcion', '<=', $to))
            ->whereNotNull('productor_id')
            ->join('productores', 'productores.id', '=', 'recepciones_empaque.productor_id')
            ->selectRaw('productores.id, CONCAT(productores.nombre," ",COALESCE(productores.apellido,"")) as nombre, COUNT(*) as recepciones, COALESCE(SUM(recepciones_empaque.peso_recibido_kg),0) as kg')
            ->groupBy('productores.id', 'productores.nombre', 'productores.apellido')
            ->orderByDesc('kg')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'id'          => $r->id,
                'nombre'      => trim($r->nombre),
                'recepciones' => intval($r->recepciones),
                'kg'          => round(floatval($r->kg), 1),
            ]);

        // ── Distribución por Variedad (producción) ─────────────────────────

        $topVariedades = ProduccionEmpaque::query()
            ->where('temporada_id', $temporadaId)
            ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
            ->when($from, fn($q) => $q->whereDate('fecha_produccion', '>=', $from))
            ->when($to,   fn($q) => $q->whereDate('fecha_produccion', '<=', $to))
            ->where('is_cola', false)
            ->join('variedades', 'variedades.id', '=', 'produccion_empaque.variedad_id')
            ->selectRaw('variedades.id, variedades.nombre, COUNT(DISTINCT numero_pallet) as pallets, COALESCE(SUM(total_cajas),0) as cajas, COALESCE(SUM(COALESCE(peso_bascula_kg, peso_neto_kg)),0) as kg')
            ->groupBy('variedades.id', 'variedades.nombre')
            ->orderByDesc('pallets')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'id'      => $r->id,
                'nombre'  => $r->nombre,
                'pallets' => intval($r->pallets),
                'cajas'   => intval($r->cajas),
                'kg'      => round(floatval($r->kg), 1),
            ]);

        // ── Construcción de respuesta ──────────────────────────────────────

        return response()->json([
            'success' => true,
            'data'    => [
                'periodo' => [
                    'from'         => $from,
                    'to'           => $to,
                    'temporada_id' => $temporadaId,
                    'entity_id'    => $entityId,
                    'hoy'          => $hoy,
                ],

                ...($enBins ? ['opera_en_bins' => true] : []),

                'hoy' => [
                    'recepciones' => [
                        'count' => intval($recHoy->total ?? 0),
                        'kg'    => round(floatval($recHoy->kg ?? 0), 1),
                        ...($enBins ? ['bins' => intval($recHoy->bins ?? 0)] : []),
                    ],
                    'produccion' => [
                        'pallets' => intval($prodHoyPallets->pallets ?? 0),
                        'cajas'   => intval($prodHoyPallets->cajas ?? 0),
                        'kg'      => round(floatval($prodHoyPallets->kg ?? 0), 1),
                    ],
                    'embarques' => [
                        'count'   => intval($embHoy->total ?? 0),
                        'pallets' => intval($embHoy->pallets ?? 0),
                        'kg'      => round(floatval($embHoy->kg ?? 0), 1),
                    ],
                    'salida_rezaga' => [
                        'kg'    => round(floatval($salidaHoy->kg ?? 0), 1),
                        'monto' => round(floatval($salidaHoy->monto ?? 0), 2),
                    ],
                ],

                'acumulado' => [
                    'recepciones' => [
                        'count'       => intval($recPeriodo->total ?? 0),
                        'kg'          => round(floatval($recPeriodo->kg ?? 0), 1),
                        'productores' => intval($recPeriodo->productores ?? 0),
                        ...($enBins ? ['bins' => intval($recPeriodo->bins ?? 0)] : []),
                    ],
                    'produccion' => [
                        'pallets' => intval($prodPeriodoPallets->pallets ?? 0),
                        'cajas'   => intval($prodPeriodoPallets->cajas ?? 0),
                        'kg'      => round(floatval($prodPeriodoPallets->kg ?? 0), 1),
                    ],
                    'embarques' => [
                        'count'       => intval($embPeriodo->total ?? 0),
                        'pallets'     => intval($embPeriodo->pallets ?? 0),
                        'kg'          => round(floatval($embPeriodo->kg ?? 0), 1),
                        'manifiestos' => intval($embPeriodo->manifiestos ?? 0),
                    ],
                    'rezaga' => [
                        'generada_kg'   => round(floatval($rezPeriodo->generada_kg ?? 0), 1),
                        'disponible_kg' => round(floatval($rezDisponible->disponible_kg ?? 0), 1),
                        ...($enBins ? ['generada_bins' => $rezBinsGenerada, 'disponible_bins' => $rezBinsDisponible] : []),
                    ],
                    'salida_rezaga' => [
                        'kg'    => round(floatval($salidaPeriodo->kg ?? 0), 1),
                        'monto' => round(floatval($salidaPeriodo->monto ?? 0), 2),
                    ],
                    'calidad' => [
                        'muestreos'       => intval($calPeriodo->muestreos ?? 0),
                        'promedio_cumple' => round(floatval($calPeriodo->promedio_cumple ?? 0), 1),
                        'promedio_defectos' => round(floatval($calPeriodo->promedio_defectos ?? 0), 1),
                    ],
                ],

                'kpis' => [
                    'yield_pct'  => $yieldPct,
                    'rezaga_pct' => $rezagaPct,
                ],

                'cuarto_frio' => [
                    'pallets' => intval($cuartoFrio->pallets ?? 0),
                    'cajas'   => intval($cuartoFrio->cajas ?? 0),
                    'kg'      => round(floatval($cuartoFrio->kg ?? 0), 1),
                ],

                'disponible' => [
                    'pallets' => intval($disponible->pallets ?? 0),
                    'cajas'   => intval($disponible->cajas ?? 0),
                ],

                'proceso_status' => $procesoStatus,

                'serie_diaria' => $serieDiaria,

                'top_productores' => $topProductores,

                'top_variedades' => $topVariedades,
            ],
        ]);
    }
}
