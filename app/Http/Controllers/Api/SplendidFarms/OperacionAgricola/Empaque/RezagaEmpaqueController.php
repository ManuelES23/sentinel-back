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
        'proceso:id,folio_proceso,productor_id,lote_id,etapa_id',
        'proceso.productor:id,nombre,apellido',
        'proceso.lote:id,nombre,numero_lote',
        'proceso.etapa:id,nombre,variedad_id',
        'proceso.etapa.variedad:id,nombre,cultivo_id',
        'proceso.etapa.variedad.cultivo:id,nombre',
        'creador:id,name',
    ];

    /**
     * GET /rezaga/procesos-del-dia — Procesos que fueron procesados en una fecha dada
     */
    public function procesosDelDia(Request $request): JsonResponse
    {
        $request->validate([
            'fecha' => 'required|date',
            'temporada_id' => 'required|exists:temporadas,id',
        ]);

        $query = ProcesoEmpaque::with([
            'productor:id,nombre,apellido',
            'lote:id,nombre,numero_lote',
            'etapa:id,nombre,variedad_id',
            'etapa.variedad:id,nombre,cultivo_id',
            'etapa.variedad.cultivo:id,nombre',
        ])
        ->where('temporada_id', $request->temporada_id)
        ->whereDate('fecha_proceso', $request->fecha);

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        $procesos = $query->orderByDesc('id')->get();

        // Attach existing rezagas for each proceso on this date
        $procesoIds = $procesos->pluck('id');
        $rezagas = RezagaEmpaque::with($this->eagerLoad)
            ->whereIn('proceso_id', $procesoIds)
            ->whereDate('fecha', $request->fecha)
            ->orderByDesc('id')
            ->get()
            ->groupBy('proceso_id');

        $result = $procesos->map(function ($p) use ($rezagas) {
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

        $rezagas = $query->orderByDesc('fecha')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $rezagas]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'proceso_id' => 'required|exists:proceso_empaque,id',
            'tipo_rezaga' => 'required|in:produccion,cuarto_frio',
            'subtipo_rezaga' => 'required|in:hoja,producto',
            'fecha' => 'required|date',
            'cantidad_kg' => 'required|numeric|min:0.01',
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
            'tipo_rezaga' => 'sometimes|in:produccion,cuarto_frio',
            'subtipo_rezaga' => 'sometimes|in:hoja,producto',
            'fecha' => 'sometimes|date',
            'cantidad_kg' => 'sometimes|numeric|min:0.01',
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

    private function generarFolio(array $data): string
    {
        $count = RezagaEmpaque::where('temporada_id', $data['temporada_id'])
            ->where('entity_id', $data['entity_id'])
            ->count() + 1;
        $entityId = str_pad($data['entity_id'], 2, '0', STR_PAD_LEFT);
        return "REZ-{$entityId}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}
