<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    /**
     * Listar registros de asistencia
     */
    public function index(Request $request): JsonResponse
    {
        $query = AttendanceRecord::with(['employee.enterprise', 'employee.department', 'employee.position']);

        // Filtrar por empleado
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        // Filtrar por empresa (a través del empleado)
        if ($request->has('enterprise_id')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('enterprise_id', $request->enterprise_id);
            });
        }

        // Filtrar por departamento
        if ($request->has('department_id')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('department_id', $request->department_id);
            });
        }

        // Filtrar por fecha
        if ($request->has('date')) {
            $query->where('date', $request->date);
        }

        // Filtrar por rango de fechas
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        // Filtrar por estado
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Hoy
        if ($request->boolean('today', false)) {
            $query->today();
        }

        // Esta semana
        if ($request->boolean('this_week', false)) {
            $query->thisWeek();
        }

        $query->orderBy('date', 'desc')->orderBy('check_in', 'desc');

        // Paginación
        $perPage = $request->get('per_page', 50);
        $records = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $records,
        ]);
    }

    /**
     * Crear/editar registro manual de asistencia
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'check_in' => 'nullable|date_format:Y-m-d H:i:s',
            'check_out' => 'nullable|date_format:Y-m-d H:i:s|after:check_in',
            'status' => 'required|in:present,absent,late,early_leave,half_day,holiday,vacation,sick_leave,personal_leave,work_from_home',
            'notes' => 'nullable|string',
            'justification' => 'nullable|string',
        ]);

        $validated['check_in_method'] = 'manual';
        $validated['check_out_method'] = 'manual';

        // Obtener empleado con su horario asignado
        $employee = Employee::with('workSchedule')->find($validated['employee_id']);

        // Calcular horas trabajadas si hay entrada y salida
        if ($validated['check_in'] && $validated['check_out']) {
            $checkIn = Carbon::parse($validated['check_in']);
            $checkOut = Carbon::parse($validated['check_out']);
            $validated['hours_worked'] = $checkIn->diffInMinutes($checkOut) / 60;
        }

        // Calcular retardo si tiene hora de entrada y horario asignado
        if ($validated['check_in'] && $employee->workSchedule) {
            $checkInTime = Carbon::parse($validated['check_in']);
            $lateMinutes = $employee->workSchedule->calculateLateMinutes($checkInTime);
            
            if ($lateMinutes > 0) {
                $validated['late_minutes'] = $lateMinutes;
                // Si el status es 'present', cambiarlo a 'late' automáticamente
                if ($validated['status'] === 'present') {
                    $validated['status'] = 'late';
                }
            } else {
                $validated['late_minutes'] = 0;
            }
        }

        $record = AttendanceRecord::updateOrCreate(
            [
                'employee_id' => $validated['employee_id'],
                'date' => $validated['date'],
            ],
            $validated
        );

        $record->load(['employee.enterprise', 'employee.department', 'employee.workSchedule']);

        return response()->json([
            'success' => true,
            'message' => 'Registro de asistencia guardado exitosamente',
            'data' => $record,
        ], 201);
    }

    /**
     * Mostrar registro
     */
    public function show(AttendanceRecord $attendance): JsonResponse
    {
        $attendance->load(['employee.enterprise', 'employee.department', 'employee.position', 'approvedBy']);

        return response()->json([
            'success' => true,
            'data' => $attendance,
        ]);
    }

    /**
     * Actualizar registro
     */
    public function update(Request $request, AttendanceRecord $attendance): JsonResponse
    {
        $validated = $request->validate([
            'check_in' => 'nullable|date_format:Y-m-d H:i:s',
            'check_out' => 'nullable|date_format:Y-m-d H:i:s',
            'status' => 'nullable|in:present,absent,late,early_leave,half_day,holiday,vacation,sick_leave,personal_leave,work_from_home',
            'notes' => 'nullable|string',
            'justification' => 'nullable|string',
        ]);

        // Recalcular horas si se modifican entrada/salida
        $checkIn = $validated['check_in'] ?? $attendance->check_in;
        $checkOut = $validated['check_out'] ?? $attendance->check_out;
        
        if ($checkIn && $checkOut) {
            $validated['hours_worked'] = Carbon::parse($checkIn)->diffInMinutes(Carbon::parse($checkOut)) / 60;
        }

        // Recalcular retardo si se modifica la hora de entrada
        if (isset($validated['check_in']) && $checkIn) {
            $attendance->load('employee.workSchedule');
            
            if ($attendance->employee && $attendance->employee->workSchedule) {
                $checkInTime = Carbon::parse($checkIn);
                $lateMinutes = $attendance->employee->workSchedule->calculateLateMinutes($checkInTime);
                
                $validated['late_minutes'] = $lateMinutes;
                
                // Si el status es 'present' y hay retardo, cambiarlo a 'late'
                $currentStatus = $validated['status'] ?? $attendance->status;
                if ($lateMinutes > 0 && $currentStatus === 'present') {
                    $validated['status'] = 'late';
                } elseif ($lateMinutes === 0 && $currentStatus === 'late') {
                    // Si ya no hay retardo, cambiar a 'present'
                    $validated['status'] = 'present';
                }
            }
        }

        $attendance->update($validated);
        $attendance->load(['employee.enterprise', 'employee.department', 'employee.workSchedule']);

        return response()->json([
            'success' => true,
            'message' => 'Registro actualizado exitosamente',
            'data' => $attendance,
        ]);
    }

    /**
     * Eliminar registro
     */
    public function destroy(AttendanceRecord $attendance): JsonResponse
    {
        $attendance->delete();

        return response()->json([
            'success' => true,
            'message' => 'Registro eliminado exitosamente',
        ]);
    }

    /**
     * Dashboard de asistencia del día
     */
    public function todayDashboard(Request $request): JsonResponse
    {
        $enterpriseId = $request->get('enterprise_id');

        // Total de empleados activos
        $employeesQuery = Employee::active();
        if ($enterpriseId) {
            $employeesQuery->where('enterprise_id', $enterpriseId);
        }
        $totalEmployees = $employeesQuery->count();

        // Registros de hoy
        $todayQuery = AttendanceRecord::today();
        if ($enterpriseId) {
            $todayQuery->whereHas('employee', function ($q) use ($enterpriseId) {
                $q->where('enterprise_id', $enterpriseId);
            });
        }
        $todayRecords = $todayQuery->get();

        // Estadísticas
        $checkedIn = $todayRecords->whereNotNull('check_in')->count();
        $checkedOut = $todayRecords->whereNotNull('check_out')->count();
        $onTime = $todayRecords->where('status', 'present')->count();
        $late = $todayRecords->where('status', 'late')->count();
        $absent = $totalEmployees - $checkedIn;

        // Últimos 10 checadas
        $recentChecks = AttendanceRecord::with(['employee'])
            ->today()
            ->whereNotNull('check_in')
            ->when($enterpriseId, function ($q) use ($enterpriseId) {
                $q->whereHas('employee', function ($sq) use ($enterpriseId) {
                    $sq->where('enterprise_id', $enterpriseId);
                });
            })
            ->orderBy('check_in', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'date' => today()->format('Y-m-d'),
                'total_employees' => $totalEmployees,
                'checked_in' => $checkedIn,
                'checked_out' => $checkedOut,
                'pending_checkout' => $checkedIn - $checkedOut,
                'on_time' => $onTime,
                'late' => $late,
                'absent' => $absent,
                'attendance_rate' => $totalEmployees > 0 ? round(($checkedIn / $totalEmployees) * 100, 1) : 0,
                'recent_checks' => $recentChecks,
            ],
        ]);
    }

    /**
     * Reporte de asistencia por período
     */
    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'enterprise_id' => 'nullable|exists:enterprises,id',
            'department_id' => 'nullable|exists:departments,id',
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $query = Employee::active()
            ->with(['enterprise', 'department', 'position']);

        if (isset($validated['enterprise_id'])) {
            $query->where('enterprise_id', $validated['enterprise_id']);
        }

        if (isset($validated['department_id'])) {
            $query->where('department_id', $validated['department_id']);
        }

        if (isset($validated['employee_id'])) {
            $query->where('id', $validated['employee_id']);
        }

        $employees = $query->get();
        $report = [];

        foreach ($employees as $employee) {
            $summary = AttendanceRecord::getSummaryForEmployee(
                $employee->id,
                $validated['start_date'],
                $validated['end_date']
            );

            $report[] = [
                'employee' => [
                    'id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name' => $employee->full_name,
                    'department' => $employee->department?->name,
                    'position' => $employee->position?->name,
                    'enterprise' => $employee->enterprise?->name,
                ],
                'summary' => $summary,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $validated['start_date'],
                    'end' => $validated['end_date'],
                ],
                'report' => $report,
            ],
        ]);
    }
}
