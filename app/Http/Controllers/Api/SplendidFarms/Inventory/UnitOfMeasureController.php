<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\UnitOfMeasure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UnitOfMeasureController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = UnitOfMeasure::with(['baseUnit:id,name,abbreviation']);

        // Filtrar solo activas
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Filtrar solo base
        if ($request->boolean('base_only')) {
            $query->base();
        }

        // Filtrar por tipo
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Búsqueda por texto
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('abbreviation', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $query->orderBy('type')->orderBy('name');

        $units = $query->get();

        // Agrupar por tipo si se solicita
        if ($request->boolean('grouped')) {
            $units = $units->groupBy('type');
        }

        return response()->json([
            'success' => true,
            'data' => $units
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:20|unique:units_of_measure,code',
            'name' => 'required|string|max:100',
            'abbreviation' => 'required|string|max:20',
            'type' => 'required|in:length,weight,volume,area,unit,time,other',
            'conversion_factor' => 'nullable|numeric|min:0',
            'base_unit_id' => 'nullable|exists:units_of_measure,id',
            'precision' => 'nullable|integer|min:0|max:6',
            'is_active' => 'boolean',
        ]);

        // Generar código automático si no se proporciona
        if (empty($validated['code'])) {
            $prefix = strtoupper(substr($validated['type'], 0, 3));
            
            $lastUnit = UnitOfMeasure::where('code', 'like', $prefix . '-%')
                ->orderByRaw('CAST(SUBSTRING(code, ' . (strlen($prefix) + 2) . ') AS UNSIGNED) DESC')
                ->first();
            
            if ($lastUnit) {
                $lastNumber = (int) substr($lastUnit->code, strlen($prefix) + 1);
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }
            
            $validated['code'] = $prefix . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }

        // Si no tiene unidad base, el factor de conversión es 1
        if (empty($validated['base_unit_id'])) {
            $validated['conversion_factor'] = 1;
        }

        $unit = UnitOfMeasure::create($validated);
        $unit->load('baseUnit:id,name,abbreviation');

        return response()->json([
            'success' => true,
            'message' => 'Unidad de medida creada exitosamente',
            'data' => $unit
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(UnitOfMeasure $unit): JsonResponse
    {
        $unit->load(['baseUnit', 'derivedUnits']);

        return response()->json([
            'success' => true,
            'data' => $unit
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, UnitOfMeasure $unit): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'abbreviation' => 'sometimes|string|max:20',
            'type' => 'sometimes|in:length,weight,volume,area,unit,time,other',
            'conversion_factor' => 'nullable|numeric|min:0',
            'base_unit_id' => 'nullable|exists:units_of_measure,id',
            'precision' => 'nullable|integer|min:0|max:6',
            'is_active' => 'boolean',
        ]);

        // Validar que no se haga referencia a sí mismo
        if (isset($validated['base_unit_id']) && $validated['base_unit_id'] == $unit->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Una unidad no puede ser su propia unidad base'
            ], 422);
        }

        $unit->update($validated);
        $unit = $unit->fresh(['baseUnit:id,name,abbreviation']);

        return response()->json([
            'success' => true,
            'message' => 'Unidad de medida actualizada exitosamente',
            'data' => $unit
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(UnitOfMeasure $unit): JsonResponse
    {
        // Verificar si tiene unidades derivadas
        if ($unit->derivedUnits()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar la unidad porque tiene unidades derivadas'
            ], 422);
        }

        // Verificar si tiene productos
        if ($unit->products()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar la unidad porque tiene productos asociados'
            ], 422);
        }

        $unit->delete();

        return response()->json([
            'success' => true,
            'message' => 'Unidad de medida eliminada exitosamente'
        ]);
    }

    /**
     * Get conversion between units.
     */
    public function convert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_unit_id' => 'required|exists:units_of_measure,id',
            'to_unit_id' => 'required|exists:units_of_measure,id',
            'quantity' => 'required|numeric',
        ]);

        $fromUnit = UnitOfMeasure::find($validated['from_unit_id']);
        $toUnit = UnitOfMeasure::find($validated['to_unit_id']);

        // Verificar que sean del mismo tipo
        if ($fromUnit->type !== $toUnit->type) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pueden convertir unidades de diferentes tipos'
            ], 422);
        }

        // Convertir a unidad base primero
        $baseQuantity = $fromUnit->toBaseUnit($validated['quantity']);
        
        // Convertir de unidad base a destino
        $result = $toUnit->fromBaseUnit($baseQuantity);

        return response()->json([
            'success' => true,
            'data' => [
                'from' => [
                    'unit' => $fromUnit->only(['id', 'name', 'abbreviation']),
                    'quantity' => $validated['quantity']
                ],
                'to' => [
                    'unit' => $toUnit->only(['id', 'name', 'abbreviation']),
                    'quantity' => $result
                ]
            ]
        ]);
    }
}
