<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\IncidentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentTypeController extends Controller
{
    /**
     * Listar tipos de incidencia
     */
    public function index(Request $request): JsonResponse
    {
        $query = IncidentType::with(['enterprise']);

        // Filtrar por empresa (incluye globales si no se especifica)
        if ($request->has('enterprise_id')) {
            $query->where(function($q) use ($request) {
                $q->where('enterprise_id', $request->enterprise_id)
                  ->orWhereNull('enterprise_id');
            });
        }

        // Filtrar por categoría
        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        // Solo activos
        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $query->orderBy('enterprise_id')->orderBy('name');

        $types = $query->get();

        // Agregar accessors
        $types->each(function (IncidentType $type) {
            $type->append('category_label');
        });

        return response()->json([
            'success' => true,
            'data' => $types,
        ]);
    }

    /**
     * Crear tipo de incidencia
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'nullable|exists:enterprises,id',
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20|unique:incident_types,code',
            'description' => 'nullable|string',
            'category' => 'required|in:permission,absence,illness,personal_leave,bereavement,maternity,paternity,medical,other',
            'requires_approval' => 'boolean',
            'affects_attendance' => 'boolean',
            'is_paid' => 'boolean',
            'max_days_per_year' => 'nullable|integer|min:1|max:365',
            'color' => 'nullable|string|max:7',
            'is_active' => 'boolean',
        ]);

        $type = IncidentType::create($validated);
        $type->load('enterprise');
        $type->append('category_label');

        return response()->json([
            'success' => true,
            'message' => 'Tipo de incidencia creado exitosamente',
            'data' => $type,
        ], 201);
    }

    /**
     * Mostrar tipo de incidencia
     */
    public function show(IncidentType $incidentType): JsonResponse
    {
        $incidentType->load(['enterprise', 'incidents' => function ($q) {
            $q->latest()->limit(10);
        }]);
        $incidentType->append('category_label');

        return response()->json([
            'success' => true,
            'data' => $incidentType,
        ]);
    }

    /**
     * Actualizar tipo de incidencia
     */
    public function update(Request $request, IncidentType $incidentType): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'code' => 'sometimes|required|string|max:20|unique:incident_types,code,' . $incidentType->id . ',id,enterprise_id,' . $incidentType->enterprise_id,
            'description' => 'nullable|string',
            'category' => 'sometimes|required|in:permission,absence,illness,personal_leave,bereavement,maternity,paternity,medical,other',
            'requires_approval' => 'boolean',
            'affects_attendance' => 'boolean',
            'is_paid' => 'boolean',
            'max_days_per_year' => 'nullable|integer|min:1|max:365',
            'color' => 'nullable|string|max:7',
            'is_active' => 'boolean',
        ]);

        $incidentType->update($validated);
        $incidentType->load('enterprise');
        $incidentType->append('category_label');

        return response()->json([
            'success' => true,
            'message' => 'Tipo de incidencia actualizado exitosamente',
            'data' => $incidentType,
        ]);
    }

    /**
     * Eliminar tipo de incidencia
     */
    public function destroy(IncidentType $incidentType): JsonResponse
    {
        // Verificar que no tenga incidencias asociadas
        if ($incidentType->incidents()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar un tipo con incidencias registradas',
            ], 422);
        }

        $incidentType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tipo de incidencia eliminado exitosamente',
        ]);
    }

    /**
     * Crear tipos por defecto para una empresa
     */
    public function createDefaults(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
        ]);

        IncidentType::createDefaultsForEnterprise($validated['enterprise_id']);

        $types = IncidentType::where('enterprise_id', $validated['enterprise_id'])
            ->orderBy('name')
            ->get();

        $types->each(function (IncidentType $type) {
            $type->append('category_label');
        });

        return response()->json([
            'success' => true,
            'message' => 'Tipos de incidencia creados exitosamente',
            'data' => $types,
        ]);
    }

    /**
     * Obtener categorías disponibles
     */
    public function categories(): JsonResponse
    {
        $categories = [
            ['value' => 'permission', 'label' => 'Permiso'],
            ['value' => 'absence', 'label' => 'Falta'],
            ['value' => 'illness', 'label' => 'Enfermedad'],
            ['value' => 'personal_leave', 'label' => 'Permiso Personal'],
            ['value' => 'bereavement', 'label' => 'Duelo'],
            ['value' => 'maternity', 'label' => 'Maternidad'],
            ['value' => 'paternity', 'label' => 'Paternidad'],
            ['value' => 'medical', 'label' => 'Cita Médica'],
            ['value' => 'other', 'label' => 'Otro'],
        ];

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }
}
