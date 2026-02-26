<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalProcess;
use App\Models\ApprovalFlowStep;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalConfigController extends Controller
{
    /**
     * Listar todos los procesos de aprobación
     */
    public function index(Request $request): JsonResponse
    {
        $query = ApprovalProcess::with(['steps.enterprise', 'steps.position']);

        if ($request->has('module')) {
            $query->byModule($request->module);
        }

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $processes = $query->orderBy('module')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $processes,
        ]);
    }

    /**
     * Mostrar un proceso con sus steps
     */
    public function show(ApprovalProcess $process): JsonResponse
    {
        $process->load(['steps.enterprise', 'steps.position']);

        return response()->json([
            'success' => true,
            'data' => $process,
        ]);
    }

    /**
     * Crear un nuevo proceso de aprobación
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:approval_processes,code',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'module' => 'required|string|max:100',
            'entity_type' => 'nullable|string|max:150',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $process = ApprovalProcess::create($validated);
        $process->load('steps');

        return response()->json([
            'success' => true,
            'message' => 'Proceso de aprobación creado exitosamente',
            'data' => $process,
        ], 201);
    }

    /**
     * Actualizar proceso de aprobación
     */
    public function update(Request $request, ApprovalProcess $process): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string',
            'module' => 'sometimes|string|max:100',
            'entity_type' => 'nullable|string|max:150',
            'requires_approval' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $process->update($validated);
        $process->load(['steps.enterprise', 'steps.position']);

        return response()->json([
            'success' => true,
            'message' => 'Proceso actualizado exitosamente',
            'data' => $process,
        ]);
    }

    /**
     * Eliminar proceso (solo si no tiene steps activos)
     */
    public function destroy(ApprovalProcess $process): JsonResponse
    {
        if ($process->steps()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar un proceso con reglas configuradas. Elimine las reglas primero.',
            ], 422);
        }

        $process->delete();

        return response()->json([
            'success' => true,
            'message' => 'Proceso eliminado exitosamente',
        ]);
    }

    /**
     * Toggle requires_approval de un proceso
     */
    public function toggleApproval(ApprovalProcess $process): JsonResponse
    {
        $process->update([
            'requires_approval' => !$process->requires_approval,
        ]);

        $status = $process->requires_approval ? 'activada' : 'desactivada';

        return response()->json([
            'success' => true,
            'message' => "Aprobación {$status} para {$process->name}",
            'data' => $process,
        ]);
    }

    // ===== STEPS (REGLAS DE APROBACIÓN) =====

    /**
     * Listar reglas de un proceso
     */
    public function getSteps(ApprovalProcess $process): JsonResponse
    {
        $steps = $process->steps()
            ->with(['enterprise', 'position'])
            ->orderBy('step_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $steps,
        ]);
    }

    /**
     * Agregar una regla de aprobación a un proceso
     */
    public function addStep(Request $request, ApprovalProcess $process): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'nullable|exists:enterprises,id',
            'step_order' => 'sometimes|integer|min:1',
            'approver_type' => 'required|in:hierarchy_level,position',
            'min_hierarchy_level' => 'required_if:approver_type,hierarchy_level|nullable|integer|min:1|max:7',
            'position_id' => 'required_if:approver_type,position|nullable|exists:positions,id',
            'approval_scope' => 'sometimes|in:own_department,child_departments,enterprise',
            'can_approve' => 'sometimes|boolean',
            'can_reject' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $validated['approval_process_id'] = $process->id;

        // Auto-asignar step_order si no se proporciona
        if (!isset($validated['step_order'])) {
            $validated['step_order'] = ($process->steps()->max('step_order') ?? 0) + 1;
        }

        $step = ApprovalFlowStep::create($validated);
        $step->load(['enterprise', 'position']);

        return response()->json([
            'success' => true,
            'message' => 'Regla de aprobación agregada',
            'data' => $step,
        ], 201);
    }

    /**
     * Actualizar una regla de aprobación
     */
    public function updateStep(Request $request, ApprovalProcess $process, ApprovalFlowStep $step): JsonResponse
    {
        if ($step->approval_process_id !== $process->id) {
            return response()->json([
                'success' => false,
                'message' => 'La regla no pertenece a este proceso',
            ], 404);
        }

        $validated = $request->validate([
            'enterprise_id' => 'nullable|exists:enterprises,id',
            'step_order' => 'sometimes|integer|min:1',
            'approver_type' => 'sometimes|in:hierarchy_level,position',
            'min_hierarchy_level' => 'nullable|integer|min:1|max:7',
            'position_id' => 'nullable|exists:positions,id',
            'approval_scope' => 'sometimes|in:own_department,child_departments,enterprise',
            'can_approve' => 'sometimes|boolean',
            'can_reject' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $step->update($validated);
        $step->load(['enterprise', 'position']);

        return response()->json([
            'success' => true,
            'message' => 'Regla actualizada exitosamente',
            'data' => $step,
        ]);
    }

    /**
     * Eliminar una regla de aprobación
     */
    public function deleteStep(ApprovalProcess $process, ApprovalFlowStep $step): JsonResponse
    {
        if ($step->approval_process_id !== $process->id) {
            return response()->json([
                'success' => false,
                'message' => 'La regla no pertenece a este proceso',
            ], 404);
        }

        $step->delete();

        return response()->json([
            'success' => true,
            'message' => 'Regla eliminada exitosamente',
        ]);
    }

    // ===== DATOS AUXILIARES =====

    /**
     * Obtener datos auxiliares para el formulario de configuración
     */
    public function formData(): JsonResponse
    {
        $hierarchyLevels = Position::HIERARCHY_LABELS;

        $positions = Position::where('can_approve', true)
            ->with('enterprise')
            ->orderBy('hierarchy_level')
            ->get(['id', 'name', 'hierarchy_level', 'enterprise_id', 'approval_scope']);

        $modules = ApprovalProcess::select('module')
            ->distinct()
            ->pluck('module');

        return response()->json([
            'success' => true,
            'data' => [
                'hierarchy_levels' => $hierarchyLevels,
                'approver_positions' => $positions,
                'modules' => $modules,
                'approval_scopes' => [
                    'own_department' => 'Solo su departamento',
                    'child_departments' => 'Departamento e hijos',
                    'enterprise' => 'Toda la empresa',
                ],
            ],
        ]);
    }
}
