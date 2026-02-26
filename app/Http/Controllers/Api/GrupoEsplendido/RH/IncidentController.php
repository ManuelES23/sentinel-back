<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\EmployeeIncident;
use App\Models\IncidentType;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class IncidentController extends Controller
{
    /**
     * Listar incidencias
     */
    public function index(Request $request): JsonResponse
    {
        $query = EmployeeIncident::with(['employee.enterprise', 'employee.position', 'incidentType', 'approver']);

        // Filtrar por empresa
        if ($request->has('enterprise_id')) {
            $query->where('enterprise_id', $request->enterprise_id);
        }

        // Filtrar por empleado
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        // Filtrar por tipo de incidencia
        if ($request->has('incident_type_id')) {
            $query->byType($request->incident_type_id);
        }

        // Filtrar por categoría
        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        // Filtrar por estado
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filtrar por rango de fechas
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->inDateRange($request->start_date, $request->end_date);
        }

        $query->orderBy('created_at', 'desc');

        $incidents = $query->get();

        // Agregar accessors
        $incidents->each(function ($incident) {
            $incident->append(['status_label', 'status_color', 'document_url']);
        });

        return response()->json([
            'success' => true,
            'data' => $incidents,
        ]);
    }

    /**
     * Crear incidencia
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'incident_type_id' => 'required|exists:incident_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'reason' => 'nullable|string|max:500',
            'document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $incidentType = IncidentType::findOrFail($validated['incident_type_id']);

        // Calcular días
        $startDate = \Carbon\Carbon::parse($validated['start_date']);
        $endDate = \Carbon\Carbon::parse($validated['end_date']);
        
        // Si tiene horario específico, calcular fracción de día
        if (isset($validated['start_time']) && isset($validated['end_time'])) {
            $startTime = \Carbon\Carbon::parse($validated['start_time']);
            $endTime = \Carbon\Carbon::parse($validated['end_time']);
            $hours = $endTime->diffInMinutes($startTime) / 60;
            $days = round($hours / 8, 1); // Asumiendo jornada de 8 horas
        } else {
            $days = $startDate->diffInDays($endDate) + 1;
        }

        // Crear instancia temporal para verificar límites
        $tempIncident = new EmployeeIncident([
            'employee_id' => $employee->id,
            'incident_type_id' => $incidentType->id,
            'days' => $days,
        ]);
        $tempIncident->setRelation('incidentType', $incidentType);

        $canRequest = $tempIncident->canEmployeeRequest();
        if (!$canRequest['allowed']) {
            return response()->json([
                'success' => false,
                'message' => $canRequest['message'],
            ], 422);
        }

        // Subir documento si existe
        $documentPath = null;
        if ($request->hasFile('document')) {
            $documentPath = $request->file('document')->store('incidents', 'public');
        }

        // Determinar estado inicial
        $status = $incidentType->requires_approval ? 'pending' : 'approved';

        $incident = EmployeeIncident::create([
            'employee_id' => $employee->id,
            'enterprise_id' => $employee->enterprise_id,
            'incident_type_id' => $incidentType->id,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'days' => $days,
            'start_time' => $validated['start_time'] ?? null,
            'end_time' => $validated['end_time'] ?? null,
            'reason' => $validated['reason'] ?? null,
            'document_path' => $documentPath,
            'status' => $status,
            'created_by' => Auth::id(),
            'approved_by' => !$incidentType->requires_approval ? Auth::id() : null,
            'approved_at' => !$incidentType->requires_approval ? now() : null,
        ]);

        $incident->load(['employee.enterprise', 'employee.position', 'incidentType']);
        $incident->append(['status_label', 'status_color', 'document_url']);

        return response()->json([
            'success' => true,
            'message' => 'Incidencia registrada exitosamente',
            'data' => $incident,
        ], 201);
    }

    /**
     * Mostrar incidencia
     */
    public function show(EmployeeIncident $incident): JsonResponse
    {
        $incident->load(['employee.enterprise', 'employee.position', 'incidentType', 'approver', 'creator']);
        $incident->append(['status_label', 'status_color', 'document_url']);

        return response()->json([
            'success' => true,
            'data' => $incident,
        ]);
    }

    /**
     * Actualizar incidencia (solo si está pendiente)
     */
    public function update(Request $request, EmployeeIncident $incident): JsonResponse
    {
        if ($incident->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden modificar incidencias pendientes',
            ], 422);
        }

        $validated = $request->validate([
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after_or_equal:start_date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'reason' => 'nullable|string|max:500',
            'document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        // Recalcular días si cambian las fechas
        if (isset($validated['start_date']) || isset($validated['end_date'])) {
            $startDate = \Carbon\Carbon::parse($validated['start_date'] ?? $incident->start_date);
            $endDate = \Carbon\Carbon::parse($validated['end_date'] ?? $incident->end_date);

            if (isset($validated['start_time']) && isset($validated['end_time'])) {
                $startTime = \Carbon\Carbon::parse($validated['start_time']);
                $endTime = \Carbon\Carbon::parse($validated['end_time']);
                $hours = $endTime->diffInMinutes($startTime) / 60;
                $validated['days'] = round($hours / 8, 1);
            } else {
                $validated['days'] = $startDate->diffInDays($endDate) + 1;
            }
        }

        // Subir nuevo documento si existe
        if ($request->hasFile('document')) {
            // Eliminar documento anterior
            if ($incident->document_path) {
                Storage::disk('public')->delete($incident->document_path);
            }
            $validated['document_path'] = $request->file('document')->store('incidents', 'public');
        }

        $incident->update($validated);
        $incident->load(['employee.enterprise', 'employee.position', 'incidentType']);
        $incident->append(['status_label', 'status_color', 'document_url']);

        return response()->json([
            'success' => true,
            'message' => 'Incidencia actualizada exitosamente',
            'data' => $incident,
        ]);
    }

    /**
     * Eliminar incidencia
     */
    public function destroy(EmployeeIncident $incident): JsonResponse
    {
        if ($incident->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden eliminar incidencias pendientes',
            ], 422);
        }

        // Eliminar documento si existe
        if ($incident->document_path) {
            Storage::disk('public')->delete($incident->document_path);
        }

        $incident->delete();

        return response()->json([
            'success' => true,
            'message' => 'Incidencia eliminada exitosamente',
        ]);
    }

    /**
     * Aprobar incidencia
     */
    public function approve(EmployeeIncident $incident): JsonResponse
    {
        if ($incident->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden aprobar incidencias pendientes',
            ], 422);
        }

        $incident->approve(Auth::user());
        $incident->load(['employee.enterprise', 'employee.position', 'incidentType', 'approver']);
        $incident->append(['status_label', 'status_color']);

        return response()->json([
            'success' => true,
            'message' => 'Incidencia aprobada exitosamente',
            'data' => $incident,
        ]);
    }

    /**
     * Rechazar incidencia
     */
    public function reject(Request $request, EmployeeIncident $incident): JsonResponse
    {
        if ($incident->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden rechazar incidencias pendientes',
            ], 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $incident->reject(Auth::user(), $validated['rejection_reason']);
        $incident->load(['employee.enterprise', 'employee.position', 'incidentType', 'approver']);
        $incident->append(['status_label', 'status_color']);

        return response()->json([
            'success' => true,
            'message' => 'Incidencia rechazada',
            'data' => $incident,
        ]);
    }

    /**
     * Resumen de incidencias por empleado
     */
    public function employeeSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'year' => 'nullable|integer',
        ]);

        $year = $validated['year'] ?? now()->year;

        $summary = EmployeeIncident::where('employee_id', $validated['employee_id'])
            ->whereYear('start_date', $year)
            ->where('status', 'approved')
            ->selectRaw('incident_type_id, SUM(days) as total_days, COUNT(*) as total_count')
            ->groupBy('incident_type_id')
            ->with('incidentType')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'summary' => $summary,
            ],
        ]);
    }
}
