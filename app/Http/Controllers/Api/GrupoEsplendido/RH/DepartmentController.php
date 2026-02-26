<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Area;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    /**
     * Listar departamentos (todos los del corporativo)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Department::with(['enterprise', 'parent', 'manager', 'areas']);

        // Filtrar por empresa
        if ($request->has('enterprise_id')) {
            $query->where('enterprise_id', $request->enterprise_id);
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

        // Solo raíces (sin padre)
        if ($request->boolean('roots_only', false)) {
            $query->roots();
        }

        $query->orderBy('enterprise_id')->orderBy('name');

        // Paginación o todos
        if ($request->has('per_page')) {
            $departments = $query->paginate($request->per_page);
        } else {
            $departments = $query->get();
        }

        return response()->json([
            'success' => true,
            'data' => $departments,
        ]);
    }

    /**
     * Crear departamento
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:departments,id',
            'manager_id' => 'nullable|exists:employees,id',
            'is_active' => 'boolean',
        ]);

        // Generar código
        $validated['code'] = Department::generateCode($validated['enterprise_id']);

        $department = Department::create($validated);
        $department->load(['enterprise', 'parent', 'manager']);

        return response()->json([
            'success' => true,
            'message' => 'Departamento creado exitosamente',
            'data' => $department,
        ], 201);
    }

    /**
     * Mostrar departamento
     */
    public function show(Department $department): JsonResponse
    {
        $department->load(['enterprise', 'parent', 'manager', 'children', 'positions', 'employees']);

        return response()->json([
            'success' => true,
            'data' => $department,
        ]);
    }

    /**
     * Actualizar departamento
     */
    public function update(Request $request, Department $department): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:departments,id',
            'manager_id' => 'nullable|exists:employees,id',
            'is_active' => 'boolean',
        ]);

        // No permitir que sea su propio padre
        if (isset($validated['parent_id']) && $validated['parent_id'] == $department->id) {
            return response()->json([
                'success' => false,
                'message' => 'Un departamento no puede ser su propio padre',
            ], 422);
        }

        $department->update($validated);
        $department->load(['enterprise', 'parent', 'manager', 'areas']);

        return response()->json([
            'success' => true,
            'message' => 'Departamento actualizado exitosamente',
            'data' => $department,
        ]);
    }

    /**
     * Eliminar departamento
     */
    public function destroy(Department $department): JsonResponse
    {
        // Verificar que no tenga empleados
        if ($department->employees()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar un departamento con empleados asignados',
            ], 422);
        }

        // Verificar que no tenga subdepartamentos
        if ($department->children()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar un departamento con subdepartamentos',
            ], 422);
        }

        $department->delete();

        return response()->json([
            'success' => true,
            'message' => 'Departamento eliminado exitosamente',
        ]);
    }

    /**
     * Obtener árbol de departamentos
     */
    public function tree(Request $request): JsonResponse
    {
        $query = Department::with(['children.children', 'manager'])
            ->roots()
            ->active();

        if ($request->has('enterprise_id')) {
            $query->where('enterprise_id', $request->enterprise_id);
        }

        $departments = $query->get();

        return response()->json([
            'success' => true,
            'data' => $departments,
        ]);
    }

    // ===== GESTIÓN DE ÁREAS =====

    /**
     * Obtener áreas vinculadas a un departamento
     */
    public function getAreas(Department $department): JsonResponse
    {
        $areas = $department->areas()->get();

        return response()->json([
            'success' => true,
            'data' => $areas,
        ]);
    }

    /**
     * Obtener áreas disponibles (no vinculadas al departamento)
     */
    public function availableAreas(Department $department): JsonResponse
    {
        $assignedIds = $department->areas()->pluck('areas.id')->toArray();

        $available = Area::whereNotIn('id', $assignedIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'description']);

        return response()->json([
            'success' => true,
            'data' => $available,
        ]);
    }

    /**
     * Vincular un área a un departamento
     */
    public function assignArea(Request $request, Department $department): JsonResponse
    {
        $validated = $request->validate([
            'area_id' => 'required|exists:areas,id',
            'relationship_type' => 'required|in:manages,operates_in,supports',
            'is_primary' => 'boolean',
            'notes' => 'nullable|string|max:500',
        ]);

        // Verificar que no exista ya la relación
        if ($department->areas()->where('area_id', $validated['area_id'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Esta área ya está vinculada al departamento',
            ], 422);
        }

        $department->areas()->attach($validated['area_id'], [
            'relationship_type' => $validated['relationship_type'],
            'is_primary' => $validated['is_primary'] ?? false,
            'notes' => $validated['notes'] ?? null,
        ]);

        $department->load('areas');

        return response()->json([
            'success' => true,
            'message' => 'Área vinculada exitosamente',
            'data' => $department,
        ]);
    }

    /**
     * Actualizar la relación entre departamento y área
     */
    public function updateArea(Request $request, Department $department, $area): JsonResponse
    {
        $validated = $request->validate([
            'relationship_type' => 'sometimes|in:manages,operates_in,supports',
            'is_primary' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:500',
        ]);

        if (!$department->areas()->where('area_id', $area)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'El área no está vinculada a este departamento',
            ], 404);
        }

        $department->areas()->updateExistingPivot($area, $validated);
        $department->load('areas');

        return response()->json([
            'success' => true,
            'message' => 'Relación actualizada exitosamente',
            'data' => $department,
        ]);
    }

    /**
     * Desvincular un área de un departamento
     */
    public function unassignArea(Department $department, $area): JsonResponse
    {
        if (!$department->areas()->where('area_id', $area)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'El área no está vinculada a este departamento',
            ], 404);
        }

        $department->areas()->detach($area);

        return response()->json([
            'success' => true,
            'message' => 'Área desvinculada exitosamente',
        ]);
    }
}
