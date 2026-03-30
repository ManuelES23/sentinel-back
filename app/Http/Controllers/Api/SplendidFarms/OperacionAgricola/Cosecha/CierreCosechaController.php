<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Cosecha;

use App\Http\Controllers\Controller;
use App\Models\CierreCosecha;
use App\Models\SalidaCampoCosecha;
use App\Models\Etapa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CierreCosechaController extends Controller
{
    private array $eagerLoad = [
        'etapa:id,nombre,lote_id,superficie,variedad_id,tipo_variedad_id',
        'etapa.variedad:id,nombre',
        'etapa.tipoVariedad:id,nombre',
        'lote:id,nombre,numero_lote,productor_id,zona_cultivo_id',
        'lote.zonaCultivo:id,nombre',
        'productor:id,nombre,apellido',
        'zonaCultivo:id,nombre',
        'cerradoPorUsuario:id,name',
        'creador:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = CierreCosecha::with($this->eagerLoad);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('lote_id')) {
            $query->where('lote_id', $request->lote_id);
        }
        if ($request->filled('etapa_id')) {
            $query->where('etapa_id', $request->etapa_id);
        }
        if ($request->filled('productor_id')) {
            $query->where('productor_id', $request->productor_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('fecha_desde')) {
            $query->where('fecha_inicio', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->where('fecha_inicio', '<=', $request->fecha_hasta);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('observaciones', 'like', "%{$search}%")
                  ->orWhereHas('lote', fn($q2) => $q2->where('nombre', 'like', "%{$search}%"))
                  ->orWhereHas('etapa', fn($q2) => $q2->where('nombre', 'like', "%{$search}%"))
                  ->orWhereHas('productor', fn($q2) => $q2->where('nombre', 'like', "%{$search}%")
                      ->orWhere('apellido', 'like', "%{$search}%"));
            });
        }

        $cierres = $query->orderByDesc('fecha_inicio')->orderByDesc('id')->get();

        // Enrich each cierre with salidas breakdown by tipo_carga
        $cierres->each(function ($cierre) {
            $cierre->setAttribute('salidas_detalle', $this->getSalidasDetalle($cierre));
            $cierre->setAttribute('porcentaje_avance', $this->calcularAvance($cierre));
        });

        return response()->json(['success' => true, 'data' => $cierres]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id'         => 'required|exists:temporadas,id',
            'etapa_id'             => 'required|exists:etapas,id',
            'lote_id'              => 'required|exists:lotes,id',
            'productor_id'         => 'required|exists:productores,id',
            'zona_cultivo_id'      => 'nullable|exists:zonas_cultivo,id',
            'fecha_inicio'         => 'required|date',
            'superficie_cosechada' => 'required|numeric|min:0',
            'observaciones'        => 'nullable|string',
        ]);

        $validated['status'] = 'abierto';
        $validated['created_by'] = $request->user()->id;

        // Auto-fill totals from salidas de campo
        $this->fillFromSalidas($validated);

        $cierre = CierreCosecha::create($validated);
        $cierre->load($this->eagerLoad);
        $cierre->setAttribute('salidas_detalle', $this->getSalidasDetalle($cierre));
        $cierre->setAttribute('porcentaje_avance', $this->calcularAvance($cierre));

        return response()->json([
            'success' => true,
            'message' => 'Cierre de cosecha creado exitosamente',
            'data'    => $cierre,
        ], 201);
    }

    public function show(CierreCosecha $cierre): JsonResponse
    {
        $cierre->load([...$this->eagerLoad, 'ventas']);
        $cierre->setAttribute('salidas_detalle', $this->getSalidasDetalle($cierre));
        $cierre->setAttribute('porcentaje_avance', $this->calcularAvance($cierre));

        return response()->json(['success' => true, 'data' => $cierre]);
    }

    public function update(Request $request, CierreCosecha $cierre): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id'         => 'sometimes|exists:temporadas,id',
            'etapa_id'             => 'sometimes|exists:etapas,id',
            'lote_id'              => 'sometimes|exists:lotes,id',
            'productor_id'         => 'sometimes|exists:productores,id',
            'zona_cultivo_id'      => 'nullable|exists:zonas_cultivo,id',
            'fecha_inicio'         => 'sometimes|date',
            'superficie_cosechada' => 'sometimes|numeric|min:0',
            'observaciones'        => 'nullable|string',
            'status'               => 'nullable|in:abierto,cerrado',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'cerrado' && $cierre->status !== 'cerrado') {
            $validated['cerrado_por'] = $request->user()->id;
            $validated['fecha_cierre'] = now('America/Mexico_City')->toDateString();
        }

        $cierre->update($validated);

        // Recalculate salidas totals
        $this->syncSalidasTotals($cierre);

        $cierre->load($this->eagerLoad);
        $cierre->setAttribute('salidas_detalle', $this->getSalidasDetalle($cierre));
        $cierre->setAttribute('porcentaje_avance', $this->calcularAvance($cierre));

        return response()->json([
            'success' => true,
            'message' => 'Cierre de cosecha actualizado',
            'data'    => $cierre,
        ]);
    }

    public function destroy(CierreCosecha $cierre): JsonResponse
    {
        $cierre->delete();
        return response()->json(['success' => true, 'message' => 'Cierre de cosecha eliminado']);
    }

    /**
     * Genera cierres automáticos a partir de las salidas de campo de un día.
     * Agrupa por productor + lote + etapa.
     */
    public function generarCierre(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'fecha'        => 'required|date',
        ]);

        $fecha = $request->fecha;
        $temporadaId = $request->temporada_id;

        $aggregados = SalidaCampoCosecha::where('temporada_id', $temporadaId)
            ->where('fecha', $fecha)
            ->where('eliminado', false)
            ->select('productor_id', 'lote_id', 'etapa_id', 'zona_cultivo_id')
            ->groupBy('productor_id', 'lote_id', 'etapa_id', 'zona_cultivo_id')
            ->get();

        if ($aggregados->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron salidas de campo para esa fecha',
            ], 422);
        }

        $creados = 0;
        $actualizados = 0;

        foreach ($aggregados as $agg) {
            $existing = CierreCosecha::where('temporada_id', $temporadaId)
                ->where('fecha_inicio', $fecha)
                ->where('productor_id', $agg->productor_id)
                ->where('lote_id', $agg->lote_id)
                ->where('etapa_id', $agg->etapa_id)
                ->first();

            $etapa = Etapa::find($agg->etapa_id);
            $superficie = $etapa?->superficie ?? 0;

            if ($existing) {
                if ($existing->status === 'abierto') {
                    $this->syncSalidasTotals($existing);
                    $actualizados++;
                }
            } else {
                $data = [
                    'temporada_id'         => $temporadaId,
                    'fecha_inicio'         => $fecha,
                    'productor_id'         => $agg->productor_id,
                    'lote_id'              => $agg->lote_id,
                    'etapa_id'             => $agg->etapa_id,
                    'zona_cultivo_id'      => $agg->zona_cultivo_id,
                    'superficie_cosechada' => $superficie,
                    'status'               => 'abierto',
                    'created_by'           => $request->user()->id,
                ];
                $cierre = CierreCosecha::create($data);
                $this->syncSalidasTotals($cierre);
                $creados++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Cierre generado: {$creados} nuevos, {$actualizados} actualizados",
            'data'    => ['creados' => $creados, 'actualizados' => $actualizados],
        ]);
    }

    /**
     * Resumen/dashboard de cierres para la temporada.
     * Includes tipo_carga breakdown from salidas.
     */
    public function resumen(Request $request): JsonResponse
    {
        $request->validate(['temporada_id' => 'required|exists:temporadas,id']);

        $temporadaId = $request->temporada_id;

        $cierres = CierreCosecha::with(['etapa:id,superficie', 'productor:id,nombre,apellido'])
            ->where('temporada_id', $temporadaId)
            ->get();

        $totalSuperficie = $cierres->sum('superficie_cosechada');
        $totalSalidas = $cierres->sum('total_salidas');
        $totalPeso = $cierres->sum('total_peso_kg');
        $diasCosecha = $cierres->pluck('fecha_inicio')->unique()->count();

        // Superficie total de todas las etapas involucradas
        $etapaIds = $cierres->pluck('etapa_id')->unique()->filter();
        $superficieTotal = Etapa::whereIn('id', $etapaIds)->sum('superficie');
        $porcentajeAvance = $superficieTotal > 0
            ? round(($totalSuperficie / $superficieTotal) * 100, 1)
            : 0;

        // Breakdown by tipo_carga across all salidas of the temporada
        $salidasCierreIds = $cierres->pluck('id')->toArray();
        $porTipoCarga = $this->getTipoCargaBreakdown($temporadaId, $cierres);

        // By productor
        $porProductor = $cierres->groupBy('productor_id')->map(function ($group) {
            $prod = $group->first()->productor;
            return [
                'nombre'     => $prod ? "{$prod->nombre} {$prod->apellido}" : '—',
                'superficie' => $group->sum('superficie_cosechada'),
                'salidas'    => $group->sum('total_salidas'),
                'peso_kg'    => $group->sum('total_peso_kg'),
                'dias'       => $group->pluck('fecha_inicio')->unique()->count(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'total_superficie'     => $totalSuperficie,
                'superficie_total'     => $superficieTotal,
                'porcentaje_avance'    => $porcentajeAvance,
                'total_salidas'        => $totalSalidas,
                'total_peso_kg'        => $totalPeso,
                'dias_cosecha'         => $diasCosecha,
                'total_cierres'        => $cierres->count(),
                'abiertos'             => $cierres->where('status', 'abierto')->count(),
                'cerrados'             => $cierres->where('status', 'cerrado')->count(),
                'por_tipo_carga'       => $porTipoCarga,
                'por_productor'        => $porProductor,
            ],
        ]);
    }

    /* ── Private helpers ────────────────────────────────── */

    /**
     * Get salidas breakdown by tipo_carga for a single cierre.
     */
    private function getSalidasDetalle(CierreCosecha $cierre): array
    {
        return SalidaCampoCosecha::where('temporada_id', $cierre->temporada_id)
            ->where('fecha', $cierre->fecha_inicio)
            ->where('productor_id', $cierre->productor_id)
            ->where('lote_id', $cierre->lote_id)
            ->where('etapa_id', $cierre->etapa_id)
            ->where('eliminado', false)
            ->join('tipos_carga', 'salidas_campo_cosecha.tipo_carga_id', '=', 'tipos_carga.id')
            ->select(
                'tipos_carga.id as tipo_carga_id',
                'tipos_carga.nombre as tipo_carga_nombre',
                'tipos_carga.peso_estimado_kg',
                DB::raw('COUNT(*) as total_salidas'),
                DB::raw('SUM(salidas_campo_cosecha.cantidad) as total_cantidad'),
                DB::raw('SUM(salidas_campo_cosecha.peso_neto_kg) as total_peso_kg'),
            )
            ->groupBy('tipos_carga.id', 'tipos_carga.nombre', 'tipos_carga.peso_estimado_kg')
            ->get()
            ->map(function ($row) use ($cierre) {
                $superficie = floatval($cierre->superficie_cosechada);
                return [
                    'tipo_carga_id'     => $row->tipo_carga_id,
                    'tipo_carga_nombre' => $row->tipo_carga_nombre,
                    'peso_estimado_kg'  => $row->peso_estimado_kg,
                    'total_salidas'     => $row->total_salidas,
                    'total_cantidad'    => $row->total_cantidad,
                    'total_peso_kg'     => $row->total_peso_kg,
                    'cantidad_por_ha'   => $superficie > 0
                        ? round($row->total_cantidad / $superficie, 2)
                        : 0,
                ];
            })
            ->toArray();
    }

    /**
     * Calculate % avance: superficie_cosechada vs etapa.superficie
     */
    private function calcularAvance(CierreCosecha $cierre): float
    {
        $supEtapa = floatval($cierre->etapa?->superficie ?? 0);
        if ($supEtapa <= 0) return 0;
        return round((floatval($cierre->superficie_cosechada) / $supEtapa) * 100, 1);
    }

    /**
     * Sync cierre totals from its matching salidas de campo.
     */
    private function syncSalidasTotals(CierreCosecha $cierre): void
    {
        $agg = SalidaCampoCosecha::where('temporada_id', $cierre->temporada_id)
            ->where('fecha', $cierre->fecha_inicio)
            ->where('productor_id', $cierre->productor_id)
            ->where('lote_id', $cierre->lote_id)
            ->where('etapa_id', $cierre->etapa_id)
            ->where('eliminado', false)
            ->select(
                DB::raw('COUNT(*) as total_salidas'),
                DB::raw('SUM(cantidad) as total_bultos'),
                DB::raw('SUM(peso_neto_kg) as total_peso_kg'),
            )
            ->first();

        $superficie = floatval($cierre->superficie_cosechada);

        $cierre->update([
            'total_salidas'  => $agg->total_salidas ?? 0,
            'total_bultos'   => $agg->total_bultos ?? 0,
            'total_peso_kg'  => $agg->total_peso_kg ?? 0,
            'rendimiento_kg_ha' => $superficie > 0 && ($agg->total_peso_kg ?? 0) > 0
                ? round(($agg->total_peso_kg) / $superficie, 2)
                : null,
        ]);
    }

    /**
     * Fill salidas totals into validated data for a new cierre.
     */
    private function fillFromSalidas(array &$data): void
    {
        $agg = SalidaCampoCosecha::where('temporada_id', $data['temporada_id'])
            ->where('fecha', $data['fecha_inicio'])
            ->where('productor_id', $data['productor_id'])
            ->where('lote_id', $data['lote_id'])
            ->where('etapa_id', $data['etapa_id'])
            ->where('eliminado', false)
            ->select(
                DB::raw('COUNT(*) as total_salidas'),
                DB::raw('SUM(cantidad) as total_bultos'),
                DB::raw('SUM(peso_neto_kg) as total_peso_kg'),
            )
            ->first();

        $data['total_salidas'] = $agg->total_salidas ?? 0;
        $data['total_bultos'] = $agg->total_bultos ?? 0;
        $data['total_peso_kg'] = $agg->total_peso_kg ?? 0;

        $superficie = floatval($data['superficie_cosechada'] ?? 0);
        if ($superficie > 0 && ($data['total_peso_kg'] ?? 0) > 0) {
            $data['rendimiento_kg_ha'] = round($data['total_peso_kg'] / $superficie, 2);
        }
    }

    /**
     * Get tipo_carga breakdown from salidas matching all cierres of a temporada.
     */
    private function getTipoCargaBreakdown(int $temporadaId, $cierres): array
    {
        // Get all unique date+productor+lote+etapa combos from cierres
        $conditions = $cierres->map(fn($c) => [
            'fecha' => $c->fecha_inicio instanceof \DateTimeInterface
                ? $c->fecha_inicio->format('Y-m-d')
                : $c->fecha_inicio,
            'productor_id' => $c->productor_id,
            'lote_id' => $c->lote_id,
            'etapa_id' => $c->etapa_id,
        ])->unique()->values();

        if ($conditions->isEmpty()) return [];

        $query = SalidaCampoCosecha::where('temporada_id', $temporadaId)
            ->where('eliminado', false)
            ->where(function ($q) use ($conditions) {
                foreach ($conditions as $cond) {
                    $q->orWhere(function ($q2) use ($cond) {
                        $q2->where('fecha', $cond['fecha'])
                           ->where('productor_id', $cond['productor_id'])
                           ->where('lote_id', $cond['lote_id'])
                           ->where('etapa_id', $cond['etapa_id']);
                    });
                }
            });

        return $query
            ->join('tipos_carga', 'salidas_campo_cosecha.tipo_carga_id', '=', 'tipos_carga.id')
            ->select(
                'tipos_carga.id as tipo_carga_id',
                'tipos_carga.nombre as tipo_carga_nombre',
                DB::raw('COUNT(*) as total_salidas'),
                DB::raw('SUM(salidas_campo_cosecha.cantidad) as total_cantidad'),
                DB::raw('SUM(salidas_campo_cosecha.peso_neto_kg) as total_peso_kg'),
            )
            ->groupBy('tipos_carga.id', 'tipos_carga.nombre')
            ->get()
            ->toArray();
    }
}
