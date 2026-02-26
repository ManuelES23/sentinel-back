<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\WorkSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkScheduleController extends Controller
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
     * Listar horarios de trabajo
     */
    public function index(Request $request): JsonResponse
    {
        $query = WorkSchedule::with(['enterprise']);

        // Filtrar por empresa
        if ($request->has('enterprise_id')) {
            $query->where('enterprise_id', $request->enterprise_id);
        }

        // Solo activos
        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $query->orderBy('enterprise_id')->orderBy('name');

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
     * Crear horario
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->normalizeTimeFields($request);
        $validated = validator($data, [
            'enterprise_id' => 'required|exists:enterprises,id',
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
            'is_default' => 'boolean',
        ])->validate();

        // Si es default, quitar el default de otros horarios de la misma empresa
        if (isset($validated['is_default']) && $validated['is_default']) {
            WorkSchedule::where('enterprise_id', $validated['enterprise_id'])
                ->update(['is_default' => false]);
        }

        $schedule = WorkSchedule::create($validated);
        $schedule->load('enterprise');

        return response()->json([
            'success' => true,
            'message' => 'Horario creado exitosamente',
            'data' => $schedule,
        ], 201);
    }

    /**
     * Mostrar horario
     */
    public function show(WorkSchedule $workSchedule): JsonResponse
    {
        $workSchedule->load(['enterprise', 'employees']);
        $workSchedule->append('schedule_summary');

        return response()->json([
            'success' => true,
            'data' => $workSchedule,
        ]);
    }

    /**
     * Actualizar horario
     */
    public function update(Request $request, WorkSchedule $workSchedule): JsonResponse
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
            'is_default' => 'boolean',
        ])->validate();

        // Si es default, quitar el default de otros
        if (isset($validated['is_default']) && $validated['is_default']) {
            WorkSchedule::where('enterprise_id', $workSchedule->enterprise_id)
                ->where('id', '!=', $workSchedule->id)
                ->update(['is_default' => false]);
        }

        $workSchedule->update($validated);
        $workSchedule->load('enterprise');

        return response()->json([
            'success' => true,
            'message' => 'Horario actualizado exitosamente',
            'data' => $workSchedule,
        ]);
    }

    /**
     * Eliminar horario
     */
    public function destroy(WorkSchedule $workSchedule): JsonResponse
    {
        // Verificar que no tenga empleados asignados
        if ($workSchedule->employees()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar un horario con empleados asignados',
            ], 422);
        }

        $workSchedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Horario eliminado exitosamente',
        ]);
    }
}
