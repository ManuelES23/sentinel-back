<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\RezagaEmpaque;
use App\Models\ProcesoEmpaque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RezagaEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'proceso:id,folio_proceso,productor_id,lote_id,etapa_id,recepcion_id',
        'proceso.productor:id,nombre,apellido',
        'proceso.lote:id,nombre,numero_lote',
        'proceso.etapa:id,nombre,variedad_id',
        'proceso.etapa.variedad:id,nombre,cultivo_id',
        'proceso.etapa.variedad.cultivo:id,nombre',
        'proceso.recepcion:id,salida_campo_id,folio_recepcion,cantidad_recibida',
        'proceso.recepcion.salidaCampo:id,variedad_id,folio_salida,cantidad',
        'proceso.recepcion.salidaCampo.variedad:id,nombre',
        'ventaDetalles:id,rezaga_id,peso_kg',
        'creador:id,name',
    ];

    /**
     * GET /rezaga/procesos-del-dia — Procesos con rezaga, filtro de fecha opcional
     */
    public function procesosDelDia(Request $request): JsonResponse
    {
        $request->validate([
            'fecha' => 'nullable|date',
            'temporada_id' => 'required|exists:temporadas,id',
        ]);

        $fecha = $request->fecha;
        $temporadaId = $request->temporada_id;
        $entityId = $request->entity_id;

        $eagerProceso = [
            'productor:id,nombre,apellido',
            'lote:id,nombre,numero_lote',
            'etapa:id,nombre,variedad_id',
            'etapa.variedad:id,nombre,cultivo_id',
            'etapa.variedad.cultivo:id,nombre',
            'recepcion:id,salida_campo_id,folio_recepcion,cantidad_recibida',
            'recepcion.salidaCampo:id,variedad_id,folio_salida,cantidad',
            'recepcion.salidaCampo.variedad:id,nombre',
            'tipoCarga:id,nombre',
        ];

        if ($fecha) {
            // Procesos processed on this date
            $procesosPorFecha = ProcesoEmpaque::with($eagerProceso)
                ->where('temporada_id', $temporadaId)
                ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
                ->whereDate('fecha_proceso', $fecha)
                ->orderByDesc('id')
                ->get();

            // Procesos that have rezagas registered on this date (may have been processed another day)
            $idsConRezagaEnFecha = RezagaEmpaque::whereDate('fecha', $fecha)
                ->whereHas('proceso', function ($q) use ($temporadaId, $entityId) {
                    $q->where('temporada_id', $temporadaId);
                    if ($entityId) $q->where('entity_id', $entityId);
                })
                ->pluck('proceso_id')
                ->unique();

            $procesosConRezaga = ProcesoEmpaque::with($eagerProceso)
                ->whereIn('id', $idsConRezagaEnFecha)
                ->whereNotIn('id', $procesosPorFecha->pluck('id'))
                ->orderByDesc('id')
                ->get();

            $procesos = $procesosPorFecha->merge($procesosConRezaga);

            // Attach rezagas filtered by date
            $procesoIds = $procesos->pluck('id');
            $rezagas = RezagaEmpaque::with($this->eagerLoad)
                ->whereIn('proceso_id', $procesoIds)
                ->whereDate('fecha', $fecha)
                ->orderByDesc('id')
                ->get()
                ->map(fn(RezagaEmpaque $r) => $this->appendCantidadHistorica($r))
                ->groupBy('proceso_id');
        } else {
            // No date filter: all procesos that have rezagas in this temporada
            $idsConRezaga = RezagaEmpaque::whereHas('proceso', function ($q) use ($temporadaId, $entityId) {
                    $q->where('temporada_id', $temporadaId);
                    if ($entityId) $q->where('entity_id', $entityId);
                })
                ->pluck('proceso_id')
                ->unique();

            $procesos = ProcesoEmpaque::with($eagerProceso)
                ->whereIn('id', $idsConRezaga)
                ->orderByDesc('fecha_proceso')
                ->orderByDesc('id')
                ->get();

            // Attach ALL rezagas for each proceso
            $procesoIds = $procesos->pluck('id');
            $rezagas = RezagaEmpaque::with($this->eagerLoad)
                ->whereIn('proceso_id', $procesoIds)
                ->orderByDesc('id')
                ->get()
                ->map(fn(RezagaEmpaque $r) => $this->appendCantidadHistorica($r))
                ->groupBy('proceso_id');
        }

        $result = $procesos->map(function (ProcesoEmpaque $p) use ($rezagas) {
            $data = $p->toArray();
            $data['rezagas_del_dia'] = $rezagas->get($p->id, collect())->values();
            return $data;
        });

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = RezagaEmpaque::with($this->eagerLoad);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }
        if ($request->filled('tipo_rezaga')) {
            $query->where('tipo_rezaga', $request->tipo_rezaga);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('folio_rezaga', 'like', "%{$search}%")
                  ->orWhere('motivo', 'like', "%{$search}%");
            });
        }

        $rezagas = $query->orderByDesc('fecha')->orderByDesc('id')->get()
            ->map(fn(RezagaEmpaque $r) => $this->appendCantidadHistorica($r));

        return response()->json(['success' => true, 'data' => $rezagas]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'proceso_id' => 'required|exists:proceso_empaque,id',
            'tipo_rezaga' => 'required|in:produccion,cuarto_frio,lavado,hidrotermico',
            'subtipo_rezaga' => 'required|in:hoja,producto',
            'fecha' => 'required|date',
            'cantidad_kg' => 'required|numeric|min:0.01',
            'cantidad_unidades_pequenas' => 'nullable|integer|min:0',
            'motivo' => 'nullable|string',
            'status' => 'nullable|in:pendiente,vendida,destruida',
            'observaciones' => 'nullable|string',
        ]);

        $validated['status'] = $validated['status'] ?? 'pendiente';
        $validated['created_by'] = $request->user()->id;
        $validated['folio_rezaga'] = $this->generarFolio($validated);

        $rezaga = RezagaEmpaque::create($validated);
        $rezaga->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Rezaga registrada exitosamente',
            'data' => $rezaga,
        ], 201);
    }

    public function show(RezagaEmpaque $rezaga): JsonResponse
    {
        $rezaga->load([...$this->eagerLoad, 'ventaDetalles']);

        return response()->json(['success' => true, 'data' => $rezaga]);
    }

    public function update(Request $request, RezagaEmpaque $rezaga): JsonResponse
    {
        $validated = $request->validate([
            'entity_id' => 'sometimes|exists:entities,id',
            'proceso_id' => 'nullable|exists:proceso_empaque,id',
            'tipo_rezaga' => 'sometimes|in:produccion,cuarto_frio,lavado,hidrotermico',
            'subtipo_rezaga' => 'sometimes|in:hoja,producto',
            'fecha' => 'sometimes|date',
            'cantidad_kg' => 'sometimes|numeric|min:0.01',
            'cantidad_unidades_pequenas' => 'nullable|integer|min:0',
            'motivo' => 'nullable|string',
            'status' => 'nullable|in:pendiente,vendida,destruida',
            'observaciones' => 'nullable|string',
        ]);

        $rezaga->update($validated);
        $rezaga->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Rezaga actualizada',
            'data' => $rezaga,
        ]);
    }

    public function destroy(RezagaEmpaque $rezaga): JsonResponse
    {
        $rezaga->delete();

        return response()->json(['success' => true, 'message' => 'Rezaga eliminada']);
    }

    private function appendCantidadHistorica(RezagaEmpaque $rezaga): RezagaEmpaque
    {
        $consumidoEnSalidas = (float) $rezaga->ventaDetalles->sum('peso_kg');
        $cantidadActual = (float) $rezaga->cantidad_kg;

        // Histórico independiente de salidas: cantidad antes de descontar salidas.
        $rezaga->setAttribute('cantidad_historica_kg', round($cantidadActual + $consumidoEnSalidas, 2));

        return $rezaga;
    }

    private function generarFolio(array $data): string
    {
        $entityId = (int) $data['entity_id'];
        $entityPad = str_pad((string) $entityId, 2, '0', STR_PAD_LEFT);
        $prefix = "REZ-{$entityPad}-";

        // Constraint unique global: el contador es por entity_id (no por temporada).
        $lastFolio = RezagaEmpaque::withTrashed()
            ->where('entity_id', $entityId)
            ->where('folio_rezaga', 'like', "{$prefix}%")
            ->orderByDesc('folio_rezaga')
            ->value('folio_rezaga');

        $nextNum = 1;
        if ($lastFolio) {
            $nextNum = (int) str_replace($prefix, '', $lastFolio) + 1;
        }

        // Retry hasta 5 veces ante race conditions
        for ($i = 0; $i < 5; $i++) {
            $candidate = $prefix . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
            $exists = RezagaEmpaque::withTrashed()->where('folio_rezaga', $candidate)->exists();
            if (!$exists) {
                return $candidate;
            }
            $nextNum++;
        }

        return $prefix . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
    }

    /**
     * GET /rezaga/pendientes — Folios que completaron etapas sin registrar rezaga
        * Returns procesos that went through lavado/produccion but have no rezaga for that stage
     */
    public function pendientesRezaga(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'nullable|exists:entities,id',
        ]);

        $temporadaId = $request->temporada_id;
        $entityId = $request->entity_id;

        $eagerLoad = [
            'productor:id,nombre,apellido',
            'lote:id,nombre,numero_lote',
            'etapa:id,nombre,variedad_id',
            'etapa.variedad:id,nombre,cultivo_id',
            'etapa.variedad.cultivo:id,nombre',
            'recepcion:id,salida_campo_id,folio_recepcion,cantidad_recibida',
            'recepcion.salidaCampo:id,variedad_id,folio_salida,cantidad',
            'recepcion.salidaCampo.variedad:id,nombre',
            'tipoCarga:id,nombre',
            'rezagas',
        ];

        // Procesos that passed through lavado (have fecha_lavado) but no rezaga tipo=lavado
        $sinRezagaLavado = ProcesoEmpaque::with($eagerLoad)
            ->where('temporada_id', $temporadaId)
            ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
            ->whereNotNull('fecha_lavado')
            ->whereDoesntHave('rezagas', fn($q) => $q->where('tipo_rezaga', 'lavado'))
            ->get()
            ->map(fn(ProcesoEmpaque $p) => array_merge($p->toArray(), ['rezaga_pendiente_tipo' => 'lavado']));

        // Procesos cerrados (procesado) without rezaga tipo=produccion
        $sinRezagaProduccion = ProcesoEmpaque::with($eagerLoad)
            ->where('temporada_id', $temporadaId)
            ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
            ->where('status', 'procesado')
            ->whereDoesntHave('rezagas', fn($q) => $q->where('tipo_rezaga', 'produccion'))
            ->get()
            ->map(fn(ProcesoEmpaque $p) => array_merge($p->toArray(), ['rezaga_pendiente_tipo' => 'produccion']));

        $pendientes = $sinRezagaLavado->merge($sinRezagaProduccion)
            ->unique(fn($item) => $item['id'] . '-' . $item['rezaga_pendiente_tipo'])
            ->values();

        return response()->json(['success' => true, 'data' => $pendientes]);
    }
}
