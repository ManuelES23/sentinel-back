<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\MovementType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MovementTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = MovementType::query();

        // Filtrar solo activos
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Filtrar por dirección
        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }

        // Filtrar por efecto
        if ($request->filled('effect')) {
            $query->where('effect', $request->effect);
        }

        // Filtrar entradas
        if ($request->boolean('entries_only')) {
            $query->entries();
        }

        // Filtrar salidas
        if ($request->boolean('exits_only')) {
            $query->exits();
        }

        // Filtrar transferencias
        if ($request->boolean('transfers_only')) {
            $query->transfers();
        }

        // Filtrar ajustes
        if ($request->boolean('adjustments_only')) {
            $query->adjustments();
        }

        $query->orderBy('direction')->orderBy('order')->orderBy('name');

        $types = $query->get();

        // Agrupar por dirección si se solicita
        if ($request->boolean('grouped')) {
            $types = $types->groupBy('direction');
        }

        return response()->json([
            'success' => true,
            'data' => $types
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:50|unique:movement_types,code',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'direction' => 'required|in:in,out,transfer,adjustment',
            'effect' => 'required|in:increase,decrease,neutral',
            'requires_source_entity' => 'boolean',
            'requires_destination_entity' => 'boolean',
            'is_system' => 'boolean',
            'color' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50',
            'order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        // Generar código automático si no se proporciona
        if (empty($validated['code'])) {
            $prefixes = [
                'in' => 'ENT',
                'out' => 'SAL',
                'transfer' => 'TRF',
                'adjustment' => 'AJU',
            ];
            $prefix = $prefixes[$validated['direction']] ?? 'MOV';
            
            $lastType = MovementType::where('code', 'like', $prefix . '-%')
                ->orderByRaw('CAST(SUBSTRING(code, ' . (strlen($prefix) + 2) . ') AS UNSIGNED) DESC')
                ->first();
            
            if ($lastType) {
                $lastNumber = (int) substr($lastType->code, strlen($prefix) + 1);
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }
            
            $validated['code'] = $prefix . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }

        // Asignar icono por defecto según dirección
        if (empty($validated['icon'])) {
            $icons = [
                'in' => 'ArrowDownToLine',
                'out' => 'ArrowUpFromLine',
                'transfer' => 'ArrowLeftRight',
                'adjustment' => 'RefreshCw',
            ];
            $validated['icon'] = $icons[$validated['direction']] ?? 'Package';
        }

        // Asignar color por defecto según dirección
        if (empty($validated['color'])) {
            $colors = [
                'in' => 'green',
                'out' => 'red',
                'transfer' => 'blue',
                'adjustment' => 'amber',
            ];
            $validated['color'] = $colors[$validated['direction']] ?? 'gray';
        }

        $type = MovementType::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tipo de movimiento creado exitosamente',
            'data' => $type
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(MovementType $type): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $type
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, MovementType $type): JsonResponse
    {
        // No permitir editar tipos de sistema
        if ($type->is_system) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pueden modificar los tipos de movimiento del sistema'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'direction' => 'sometimes|in:in,out,transfer,adjustment',
            'effect' => 'sometimes|in:increase,decrease,neutral',
            'requires_source_entity' => 'boolean',
            'requires_destination_entity' => 'boolean',
            'color' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50',
            'order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        $type->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tipo de movimiento actualizado exitosamente',
            'data' => $type
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MovementType $type): JsonResponse
    {
        // No permitir eliminar tipos de sistema
        if ($type->is_system) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pueden eliminar los tipos de movimiento del sistema'
            ], 403);
        }

        // Verificar si tiene movimientos
        if ($type->movements()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el tipo porque tiene movimientos registrados'
            ], 422);
        }

        $type->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tipo de movimiento eliminado exitosamente'
        ]);
    }
}
