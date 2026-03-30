<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\ProduccionEmpaque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProduccionEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'proceso:id,folio_proceso,productor_id,lote_id',
        'proceso.productor:id,nombre,apellido',
        'proceso.lote:id,nombre,numero_lote',
        'variedad:id,nombre',
        'recipe:id,name,code,recipe_type',
        'creador:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = ProduccionEmpaque::with($this->eagerLoad);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('folio_produccion', 'like', "%{$search}%")
                  ->orWhere('numero_pallet', 'like', "%{$search}%")
                  ->orWhere('tipo_empaque', 'like', "%{$search}%")
                  ->orWhere('etiqueta', 'like', "%{$search}%");
            });
        }

        $producciones = $query->orderByDesc('fecha_produccion')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $producciones]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'proceso_id' => 'required|exists:proceso_empaque,id',
            'recipe_id' => 'nullable|exists:recipes,id',
            'fecha_produccion' => 'required|date',
            'turno' => 'nullable|string|max:50',
            'variedad_id' => 'nullable|exists:variedades,id',
            'linea_empaque' => 'nullable|string|max:100',
            'numero_pallet' => 'nullable|string|max:100',
            'total_cajas' => 'required|integer|min:1',
            'peso_neto_kg' => 'nullable|numeric|min:0',
            'tipo_empaque' => 'nullable|string|max:100',
            'etiqueta' => 'nullable|string|max:100',
            'calibre' => 'nullable|string|max:50',
            'categoria' => 'nullable|string|max:50',
            'status' => 'nullable|in:empacado,en_almacen,embarcado',
            'observaciones' => 'nullable|string',
        ]);

        $validated['status'] = $validated['status'] ?? 'empacado';
        $validated['created_by'] = $request->user()->id;
        $validated['folio_produccion'] = $this->generarFolio($validated);

        $produccion = ProduccionEmpaque::create($validated);
        $produccion->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Producción registrada exitosamente',
            'data' => $produccion,
        ], 201);
    }

    public function show(ProduccionEmpaque $produccion): JsonResponse
    {
        $produccion->load([...$this->eagerLoad, 'embarqueDetalles.embarque']);

        return response()->json(['success' => true, 'data' => $produccion]);
    }

    public function update(Request $request, ProduccionEmpaque $produccion): JsonResponse
    {
        $validated = $request->validate([
            'entity_id' => 'sometimes|exists:entities,id',
            'proceso_id' => 'nullable|exists:proceso_empaque,id',
            'recipe_id' => 'nullable|exists:recipes,id',
            'fecha_produccion' => 'sometimes|date',
            'turno' => 'nullable|string|max:50',
            'variedad_id' => 'nullable|exists:variedades,id',
            'linea_empaque' => 'nullable|string|max:100',
            'numero_pallet' => 'nullable|string|max:100',
            'total_cajas' => 'sometimes|integer|min:1',
            'peso_neto_kg' => 'nullable|numeric|min:0',
            'tipo_empaque' => 'nullable|string|max:100',
            'etiqueta' => 'nullable|string|max:100',
            'calibre' => 'nullable|string|max:50',
            'categoria' => 'nullable|string|max:50',
            'status' => 'nullable|in:empacado,en_almacen,embarcado',
            'observaciones' => 'nullable|string',
        ]);

        $produccion->update($validated);
        $produccion->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Producción actualizada',
            'data' => $produccion,
        ]);
    }

    public function destroy(ProduccionEmpaque $produccion): JsonResponse
    {
        $produccion->delete();

        return response()->json(['success' => true, 'message' => 'Producción eliminada']);
    }

    private function generarFolio(array $data): string
    {
        $count = ProduccionEmpaque::where('temporada_id', $data['temporada_id'])
            ->where('entity_id', $data['entity_id'])
            ->count() + 1;
        $entityId = str_pad($data['entity_id'], 2, '0', STR_PAD_LEFT);
        return "PROD-{$entityId}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}
