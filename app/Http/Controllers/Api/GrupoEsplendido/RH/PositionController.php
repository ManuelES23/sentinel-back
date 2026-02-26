<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    /**
     * Obtener niveles jerárquicos disponibles
     */
    public function hierarchyLevels(): JsonResponse
    {
        $levels = collect(Position::HIERARCHY_LABELS)->map(function ($label, $level) {
            return ['value' => $level, 'label' => $label];
        })->values();

        return response()->json(['success' => true, 'data' => $levels]);
    }
    /**
     * Listar puestos
     */
    public function index(Request $request): JsonResponse
    {
        $query = Position::with(['enterprise', 'department']);

        // Filtrar por empresa
        if ($request->has('enterprise_id')) {
            $query->where('enterprise_id', $request->enterprise_id);
        }

        // Filtrar por departamento
        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        // Búsqueda
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // Solo activos
        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $query->orderBy('enterprise_id')->orderBy('name');

        // Paginación o todos
        if ($request->has('per_page')) {
            $positions = $query->paginate($request->per_page);
        } else {
            $positions = $query->get();
        }

        return response()->json([
            'success' => true,
            'data' => $positions,
        ]);
    }

    /**
     * Crear puesto
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'department_id' => 'nullable|exists:departments,id',
            'hierarchy_level' => 'sometimes|integer|min:1|max:7',
            'can_approve' => 'sometimes|boolean',
            'approval_scope' => 'sometimes|string|in:own_department,child_departments,enterprise',
            'min_salary' => 'nullable|numeric|min:0',
            'max_salary' => 'nullable|numeric|min:0|gte:min_salary',
            'is_active' => 'boolean',
        ]);

        // Generar código
        $validated['code'] = Position::generateCode($validated['enterprise_id']);

        $position = Position::create($validated);
        $position->load(['enterprise', 'department']);

        return response()->json([
            'success' => true,
            'message' => 'Puesto creado exitosamente',
            'data' => $position,
        ], 201);
    }

    /**
     * Mostrar puesto
     */
    public function show(Position $position): JsonResponse
    {
        $position->load(['enterprise', 'department', 'employees']);

        return response()->json([
            'success' => true,
            'data' => $position,
        ]);
    }

    /**
     * Actualizar puesto
     */
    public function update(Request $request, Position $position): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string',
            'department_id' => 'nullable|exists:departments,id',
            'hierarchy_level' => 'sometimes|integer|min:1|max:7',
            'can_approve' => 'sometimes|boolean',
            'approval_scope' => 'sometimes|string|in:own_department,child_departments,enterprise',
            'min_salary' => 'nullable|numeric|min:0',
            'max_salary' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $position->update($validated);
        $position->load(['enterprise', 'department']);

        return response()->json([
            'success' => true,
            'message' => 'Puesto actualizado exitosamente',
            'data' => $position,
        ]);
    }

    /**
     * Eliminar puesto
     */
    public function destroy(Position $position): JsonResponse
    {
        // Verificar que no tenga empleados
        if ($position->employees()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar un puesto con empleados asignados',
            ], 422);
        }

        $position->delete();

        return response()->json([
            'success' => true,
            'message' => 'Puesto eliminado exitosamente',
        ]);
    }
}
