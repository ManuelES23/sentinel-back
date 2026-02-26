<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Models\WorkSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller para gestión de horarios globales a nivel administrativo.
 * Los horarios son globales y se asignan a empresas mediante una tabla pivot.
 */
class ScheduleController extends Controller
{
    /**
     * Normalizar campos de hora (acepta H:i o H:i:s, devuelve H:i)
     */
    private function normalizeTimeFields(Request $request): array
    {
        $timeFields = [
            'monday_start', 'monday_end',
            'tuesday_start', 'tuesday_end',
            'wednesday_start', 'wednesday_end',
            'thursday_start', 'thursday_end',
            'friday_start', 'friday_end',
            'saturday_start', 'saturday_end',
            'sunday_start', 'sunday_end',
        ];

        $data = $request->all();

        foreach ($timeFields as $field) {
            if (isset($data[$field]) && $data[$field]) {
                // Si tiene segundos (H:i:s), quitar los segundos
                $data[$field] = substr($data[$field], 0, 5);
            }
        }

        return $data;
    }

    /**
     * Listar todos los horarios globales
     */
    public function index(Request $request): JsonResponse
    {
        $query = WorkSchedule::with(['enterprises']);

        // Solo activos
        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        // Filtrar por empresa (horarios asignados a esa empresa)
        if ($request->has('enterprise_id')) {
            $query->forEnterprise($request->enterprise_id);
        }

        $query->orderBy('name');

        $schedules = $query->get();

        // Agregar resumen de horario al JSON
        $schedules->each(function ($schedule) {
            $schedule->append('schedule_summary');
        });

        return response()->json([
            'success' => true,
            'data' => $schedules,
        ]);
    }

    /**
     * Crear horario global
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->normalizeTimeFields($request);
        $validated = validator($data, [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'monday_start' => 'nullable|date_format:H:i',
            'monday_end' => 'nullable|date_format:H:i|after:monday_start',
            'tuesday_start' => 'nullable|date_format:H:i',
            'tuesday_end' => 'nullable|date_format:H:i|after:tuesday_start',
            'wednesday_start' => 'nullable|date_format:H:i',
            'wednesday_end' => 'nullable|date_format:H:i|after:wednesday_start',
            'thursday_start' => 'nullable|date_format:H:i',
            'thursday_end' => 'nullable|date_format:H:i|after:thursday_start',
            'friday_start' => 'nullable|date_format:H:i',
            'friday_end' => 'nullable|date_format:H:i|after:friday_start',
            'saturday_start' => 'nullable|date_format:H:i',
            'saturday_end' => 'nullable|date_format:H:i|after:saturday_start',
            'sunday_start' => 'nullable|date_format:H:i',
            'sunday_end' => 'nullable|date_format:H:i|after:sunday_start',
            'late_tolerance_minutes' => 'nullable|integer|min:0|max:60',
            'early_departure_tolerance' => 'nullable|integer|min:0|max:60',
            'is_active' => 'boolean',
        ])->validate();

        $schedule = WorkSchedule::create($validated);
        $schedule->load('enterprises');

        return response()->json([
            'success' => true,
            'message' => 'Horario creado exitosamente',
            'data' => $schedule,
        ], 201);
    }

    /**
     * Mostrar horario
     */
    public function show(WorkSchedule $schedule): JsonResponse
    {
        $schedule->load(['enterprises', 'employees']);
        $schedule->append('schedule_summary');

        return response()->json([
            'success' => true,
            'data' => $schedule,
        ]);
    }

    /**
     * Actualizar horario
     */
    public function update(Request $request, WorkSchedule $schedule): JsonResponse
    {
        $data = $this->normalizeTimeFields($request);
        $validated = validator($data, [
            'name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string',
            'monday_start' => 'nullable|date_format:H:i',
            'monday_end' => 'nullable|date_format:H:i',
            'tuesday_start' => 'nullable|date_format:H:i',
            'tuesday_end' => 'nullable|date_format:H:i',
            'wednesday_start' => 'nullable|date_format:H:i',
            'wednesday_end' => 'nullable|date_format:H:i',
            'thursday_start' => 'nullable|date_format:H:i',
            'thursday_end' => 'nullable|date_format:H:i',
            'friday_start' => 'nullable|date_format:H:i',
            'friday_end' => 'nullable|date_format:H:i',
            'saturday_start' => 'nullable|date_format:H:i',
            'saturday_end' => 'nullable|date_format:H:i',
            'sunday_start' => 'nullable|date_format:H:i',
            'sunday_end' => 'nullable|date_format:H:i',
            'late_tolerance_minutes' => 'nullable|integer|min:0|max:60',
            'early_departure_tolerance' => 'nullable|integer|min:0|max:60',
            'is_active' => 'boolean',
        ])->validate();

        $schedule->update($validated);
        $schedule->load('enterprises');

        return response()->json([
            'success' => true,
            'message' => 'Horario actualizado exitosamente',
            'data' => $schedule,
        ]);
    }

    /**
     * Eliminar horario
     */
    public function destroy(WorkSchedule $schedule): JsonResponse
    {
        // Verificar que no tenga empleados asignados
        if ($schedule->employees()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar un horario con empleados asignados',
            ], 422);
        }

        // Desasignar de todas las empresas
        $schedule->enterprises()->detach();

        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Horario eliminado exitosamente',
        ]);
    }

    // ==================== ASIGNACIONES A EMPRESAS ====================

    /**
     * Asignar horario a una empresa
     */
    public function assignToEnterprise(Request $request, WorkSchedule $schedule): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'is_default' => 'boolean',
        ]);

        $enterpriseId = $validated['enterprise_id'];
        $isDefault = $validated['is_default'] ?? false;

        // Si será default, quitar el default de otros horarios de esa empresa
        if ($isDefault) {
            $schedule->enterprises()
                ->where('enterprise_id', $enterpriseId)
                ->update(['is_default' => false]);
        }

        // Verificar si ya está asignado
        if ($schedule->enterprises()->where('enterprise_id', $enterpriseId)->exists()) {
            // Actualizar pivot
            $schedule->enterprises()->updateExistingPivot($enterpriseId, [
                'is_default' => $isDefault,
            ]);
        } else {
            // Crear nueva asignación
            $schedule->enterprises()->attach($enterpriseId, [
                'is_default' => $isDefault,
            ]);
        }

        $schedule->load('enterprises');

        return response()->json([
            'success' => true,
            'message' => 'Horario asignado a la empresa exitosamente',
            'data' => $schedule,
        ]);
    }

    /**
     * Desasignar horario de una empresa
     */
    public function unassignFromEnterprise(WorkSchedule $schedule, Enterprise $enterprise): JsonResponse
    {
        // Verificar que no haya empleados de esa empresa con este horario
        $employeesCount = $schedule->employees()
            ->whereHas('department', function ($q) use ($enterprise) {
                $q->where('enterprise_id', $enterprise->id);
            })
            ->count();

        if ($employeesCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "No se puede desasignar: hay {$employeesCount} empleado(s) de esta empresa usando este horario",
            ], 422);
        }

        $schedule->enterprises()->detach($enterprise->id);
        $schedule->load('enterprises');

        return response()->json([
            'success' => true,
            'message' => 'Horario desasignado de la empresa',
            'data' => $schedule,
        ]);
    }

    /**
     * Obtener horarios disponibles para asignar a una empresa
     * (los que no están ya asignados)
     */
    public function availableForEnterprise(Enterprise $enterprise): JsonResponse
    {
        $schedules = WorkSchedule::active()
            ->notForEnterprise($enterprise->id)
            ->orderBy('name')
            ->get();

        $schedules->each(function ($schedule) {
            $schedule->append('schedule_summary');
        });

        return response()->json([
            'success' => true,
            'data' => $schedules,
        ]);
    }

    /**
     * Obtener horarios asignados a una empresa
     */
    public function forEnterprise(Enterprise $enterprise): JsonResponse
    {
        $schedules = $enterprise->workSchedules()
            ->orderBy('name')
            ->get();

        $schedules->each(function ($schedule) {
            $schedule->append('schedule_summary');
            // Incluir info del pivot
            $schedule->is_default_for_enterprise = $schedule->pivot->is_default ?? false;
        });

        return response()->json([
            'success' => true,
            'data' => $schedules,
        ]);
    }
}
