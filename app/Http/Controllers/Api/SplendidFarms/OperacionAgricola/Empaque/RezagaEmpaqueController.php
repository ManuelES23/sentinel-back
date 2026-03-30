<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\RezagaEmpaque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RezagaEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'proceso:id,folio_proceso,productor_id,lote_id',
        'proceso.productor:id,nombre,apellido',
        'proceso.lote:id,nombre,numero_lote',
        'creador:id,name',
    ];

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
            'tipo_rezaga' => 'required|in:descarte,merma,segunda,basura',
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
            'tipo_rezaga' => 'sometimes|in:descarte,merma,segunda,basura',
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
