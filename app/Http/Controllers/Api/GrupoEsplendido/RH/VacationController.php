<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Events\VacationRequestUpdated;
use App\Models\VacationRequest;
use App\Models\VacationBalance;
use App\Models\Employee;
use App\Services\VacationCalculatorService;
use App\Services\NotificationService;
use App\Services\ApprovalNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VacationController extends Controller
{
    /**
     * Listar solicitudes de vacaciones
     */
    public function index(Request $request): JsonResponse
    {
        $query = VacationRequest::with(['employee.enterprise', 'employee.position', 'approver']);

        // Filtrar por empresa
        if ($request->has('enterprise_id')) {
            $query->where('enterprise_id', $request->enterprise_id);
        }

        // Filtrar por empleado
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        // Filtrar por estado
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filtrar por año
        if ($request->has('year')) {
            $query->forYear($request->year);
        }

        // Filtrar por rango de fechas
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->inDateRange($request->start_date, $request->end_date);
        }

        $query->orderBy('created_at', 'desc');

        $vacations = $query->get();

        // Agregar accessors
        $vacations->each(function ($vacation) {
            $vacation->append(['status_label', 'status_color']);
        });

        return response()->json([
            'success' => true,
            'data' => $vacations,
        ]);
    }

    /**
     * Crear solicitud de vacaciones
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:500',
            'vacation_year' => 'nullable|integer|min:2020|max:2100',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        // Calcular días solicitados (excluyendo fines de semana)
        $startDate = \Carbon\Carbon::parse($validated['start_date']);
        $endDate = \Carbon\Carbon::parse($validated['end_date']);
        $daysRequested = $this->calculateBusinessDays($startDate, $endDate);

        // Año del período vacacional
        $vacationYear = $validated['vacation_year'] ?? now()->year;

        // Obtener información de vacaciones usando el servicio (cálculo acumulado)
        $vacationInfo = VacationCalculatorService::getEmployeeVacationInfo($employee);
        
        // Días disponibles totales (acumulados de todos los años)
        $availableDays = $vacationInfo['accumulated']['total_available_days'] ?? 0;

        // También crear/actualizar el balance del año para tracking
        $balance = VacationBalance::firstOrCreate(
            [
                'employee_id' => $employee->id,
                'year' => $vacationYear,
            ],
            [
                'entitled_days' => VacationBalance::calculateEntitledDays(
                    $employee->hire_date ? $employee->hire_date->diffInYears(now()) : 0
                ),
                'used_days' => 0,
                'pending_days' => 0,
                'carried_over' => 0,
            ]
        );

        if ($daysRequested > $availableDays) {
            return response()->json([
                'success' => false,
                'message' => "No tiene suficientes días disponibles. Disponibles: {$availableDays}, Solicitados: {$daysRequested}",
            ], 422);
        }

        // Verificar que no se traslape con otras solicitudes
        $overlapping = VacationRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->inDateRange($validated['start_date'], $validated['end_date'])
            ->exists();

        if ($overlapping) {
            return response()->json([
                'success' => false,
                'message' => 'Ya existe una solicitud de vacaciones en ese período',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $vacation = VacationRequest::create([
                'employee_id' => $employee->id,
                'enterprise_id' => $employee->enterprise_id,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'days_requested' => $daysRequested,
                'reason' => $validated['reason'] ?? null,
                'vacation_year' => $vacationYear,
                'status' => 'pending',
                'created_by' => Auth::id(),
            ]);

            // Actualizar días pendientes en el balance
            $balance->increment('pending_days', $daysRequested);

            DB::commit();

            $vacation->load(['employee.enterprise', 'employee.position']);
            $vacation->append(['status_label', 'status_color']);

            // Notificar a los aprobadores del departamento correspondiente
            $employee->load('position', 'department');
            ApprovalNotificationService::notifyApprovers(
                'vacation_requests',
                $employee,
                'Nueva solicitud de vacaciones',
                $employee->full_name . ' ha solicitado ' . $daysRequested . ' día(s) de vacaciones del ' .
                    $startDate->format('d/m/Y') . ' al ' . $endDate->format('d/m/Y'),
                '/profile?tab=approvals',
                'vacation'
            );

            return response()->json([
                'success' => true,
                'message' => 'Solicitud de vacaciones creada exitosamente',
                'data' => $vacation,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la solicitud: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mostrar solicitud
     */
    public function show(VacationRequest $vacation): JsonResponse
    {
        $vacation->load(['employee.enterprise', 'employee.position', 'approver', 'creator']);
        $vacation->append(['status_label', 'status_color']);

        return response()->json([
            'success' => true,
            'data' => $vacation,
        ]);
    }

    /**
     * Actualizar solicitud (solo si está pendiente)
     */
    public function update(Request $request, VacationRequest $vacation): JsonResponse
    {
        if ($vacation->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden modificar solicitudes pendientes',
            ], 422);
        }

        $validated = $request->validate([
            'start_date' => 'sometimes|required|date|after_or_equal:today',
            'end_date' => 'sometimes|required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:500',
        ]);

        // Si cambian las fechas, recalcular días
        if (isset($validated['start_date']) || isset($validated['end_date'])) {
            $startDate = \Carbon\Carbon::parse($validated['start_date'] ?? $vacation->start_date);
            $endDate = \Carbon\Carbon::parse($validated['end_date'] ?? $vacation->end_date);
            $newDays = $this->calculateBusinessDays($startDate, $endDate);

            // Obtener días disponibles usando cálculo acumulado
            $employee = $vacation->employee;
            $vacationInfo = VacationCalculatorService::getEmployeeVacationInfo($employee);
            $availableDays = $vacationInfo['accumulated']['total_available_days'] ?? 0;
            
            // Ajustar: sumar los días de esta solicitud que ya están pendientes
            $availableDays += $vacation->days_requested;

            $diff = $newDays - $vacation->days_requested;
            
            if ($newDays > $availableDays) {
                return response()->json([
                    'success' => false,
                    'message' => "No tiene suficientes días disponibles. Disponibles: {$availableDays}, Solicitados: {$newDays}",
                ], 422);
            }

            // Ajustar balance de días pendientes
            $balance = VacationBalance::where('employee_id', $vacation->employee_id)
                ->where('year', $vacation->vacation_year)
                ->first();

            if ($balance) {
                $balance->increment('pending_days', $diff);
            }

            $validated['days_requested'] = $newDays;
        }

        $vacation->update($validated);
        $vacation->load(['employee.enterprise', 'employee.position']);
        $vacation->append(['status_label', 'status_color']);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud actualizada exitosamente',
            'data' => $vacation,
        ]);
    }

    /**
     * Eliminar solicitud (solo si está pendiente)
     */
    public function destroy(VacationRequest $vacation): JsonResponse
    {
        if ($vacation->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden eliminar solicitudes pendientes',
            ], 422);
        }

        // Revertir días pendientes
        $balance = VacationBalance::where('employee_id', $vacation->employee_id)
            ->where('year', $vacation->vacation_year)
            ->first();

        if ($balance) {
            $balance->decrement('pending_days', $vacation->days_requested);
        }

        $vacation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Solicitud eliminada exitosamente',
        ]);
    }

    /**
     * Aprobar solicitud
     */
    public function approve(VacationRequest $vacation): JsonResponse
    {
        if ($vacation->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden aprobar solicitudes pendientes',
            ], 422);
        }

        $vacation->approve(Auth::user());
        $vacation->load(['employee.enterprise', 'employee.position', 'approver', 'employee.user']);
        $vacation->append(['status_label', 'status_color']);

        // Notificar al empleado si tiene usuario asociado
        if ($vacation->employee->user) {
            NotificationService::toUser($vacation->employee->user)
                ->withAction('/profile', 'Ver mis vacaciones')
                ->vacation(
                    '¡Vacaciones aprobadas!',
                    "Tu solicitud de vacaciones del {$vacation->start_date->format('d/m/Y')} al {$vacation->end_date->format('d/m/Y')} ({$vacation->days_requested} días) ha sido aprobada."
                );
        }

        // Broadcast para tiempo real
        broadcast(new VacationRequestUpdated(
            'approved',
            $vacation->toArray(),
            $vacation->employee->enterprise?->slug ?? 'grupoesplendido',
            'administration',
            'rh'
        ))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Solicitud aprobada exitosamente',
            'data' => $vacation,
        ]);
    }

    /**
     * Rechazar solicitud
     */
    public function reject(Request $request, VacationRequest $vacation): JsonResponse
    {
        if ($vacation->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden rechazar solicitudes pendientes',
            ], 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        // Revertir días pendientes
        $balance = VacationBalance::where('employee_id', $vacation->employee_id)
            ->where('year', $vacation->vacation_year)
            ->first();

        if ($balance) {
            $balance->decrement('pending_days', $vacation->days_requested);
        }

        $vacation->reject(Auth::user(), $validated['rejection_reason']);
        $vacation->load(['employee.enterprise', 'employee.position', 'approver', 'employee.user']);
        $vacation->append(['status_label', 'status_color']);

        // Notificar al empleado si tiene usuario asociado
        if ($vacation->employee->user) {
            NotificationService::toUser($vacation->employee->user)
                ->high()
                ->withAction('/profile', 'Ver detalles')
                ->vacation(
                    'Solicitud de vacaciones rechazada',
                    "Tu solicitud del {$vacation->start_date->format('d/m/Y')} al {$vacation->end_date->format('d/m/Y')} fue rechazada. Motivo: {$validated['rejection_reason']}"
                );
        }

        // Broadcast para tiempo real
        broadcast(new VacationRequestUpdated(
            'rejected',
            $vacation->toArray(),
            $vacation->employee->enterprise?->slug ?? 'grupoesplendido',
            'administration',
            'rh'
        ))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Solicitud rechazada',
            'data' => $vacation,
        ]);
    }

    /**
     * Cancelar solicitud
     */
    public function cancel(VacationRequest $vacation): JsonResponse
    {
        if (!in_array($vacation->status, ['pending', 'approved'])) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede cancelar esta solicitud',
            ], 422);
        }

        $vacation->load('employee.enterprise');
        $vacation->cancel();
        $vacation->append(['status_label', 'status_color']);

        // Broadcast para tiempo real
        broadcast(new VacationRequestUpdated(
            'cancelled',
            $vacation->toArray(),
            $vacation->employee->enterprise?->slug ?? 'grupoesplendido',
            'administration',
            'rh'
        ))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Solicitud cancelada',
            'data' => $vacation,
        ]);
    }

    /**
     * Obtener balance de vacaciones de un empleado
     */
    public function getBalance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'year' => 'nullable|integer',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $year = $validated['year'] ?? now()->year;

        $balance = VacationBalance::firstOrCreate(
            [
                'employee_id' => $employee->id,
                'year' => $year,
            ],
            [
                'entitled_days' => VacationBalance::calculateEntitledDays(
                    $employee->hire_date ? $employee->hire_date->diffInYears(now()) : 0
                ),
                'used_days' => 0,
                'pending_days' => 0,
                'carried_over' => 0,
            ]
        );

        $balance->append(['available_days', 'total_days']);

        return response()->json([
            'success' => true,
            'data' => [
                'balance' => $balance,
                'employee' => $employee->only(['id', 'full_name', 'hire_date']),
                'years_of_service' => $employee->hire_date 
                    ? $employee->hire_date->diffInYears(now()) 
                    : 0,
            ],
        ]);
    }

    /**
     * Calcular días hábiles entre dos fechas
     */
    private function calculateBusinessDays(\Carbon\Carbon $startDate, \Carbon\Carbon $endDate): int
    {
        $days = 0;
        $current = $startDate->copy();

        while ($current <= $endDate) {
            // Excluir sábados (6) y domingos (0)
            if (!$current->isWeekend()) {
                $days++;
            }
            $current->addDay();
        }

        return $days;
    }

    /**
     * Obtener información completa de vacaciones de un empleado según LFT México
     */
    public function getEmployeeVacationInfo(Employee $employee): JsonResponse
    {
        $info = VacationCalculatorService::getEmployeeVacationInfo($employee);

        return response()->json([
            'success' => true,
            'data' => $info,
        ]);
    }

    /**
     * Obtener tabla de vacaciones según LFT México
     */
    public function getVacationTable(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'table' => VacationCalculatorService::getVacationTable(),
                'source' => 'Ley Federal del Trabajo - México',
                'reform' => 'Vacaciones Dignas 2023',
                'note' => 'Los días de vacaciones se otorgan a partir del cumplimiento del primer año de servicio y deben disfrutarse en los 6 meses siguientes.',
            ],
        ]);
    }

    /**
     * Calcular días de vacaciones por años de servicio
     */
    public function calculateDays(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'years_of_service' => 'required|integer|min:0|max:100',
        ]);

        $years = $validated['years_of_service'];
        $days = VacationCalculatorService::calculateEntitledDays($years);

        return response()->json([
            'success' => true,
            'data' => [
                'years_of_service' => $years,
                'entitled_days' => $days,
                'next_year_days' => VacationCalculatorService::calculateEntitledDays($years + 1),
            ],
        ]);
    }

    /**
     * Inicializar/recalcular balances de vacaciones para todos los empleados activos
     */
    public function initializeBalances(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:2020|max:2100',
        ]);

        $year = $validated['year'] ?? now()->year;
        $results = VacationCalculatorService::initializeAllBalances($year);

        return response()->json([
            'success' => true,
            'message' => 'Balances inicializados/actualizados',
            'data' => [
                'year' => $year,
                'processed' => count($results['success']),
                'errors' => count($results['errors']),
                'details' => $results,
            ],
        ]);
    }

    /**
     * Recalcular balance de un empleado específico
     */
    public function recalculateBalance(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:2020|max:2100',
        ]);

        $year = $validated['year'] ?? now()->year;
        $balance = VacationCalculatorService::recalculateEmployeeBalance($employee, $year);
        $balance->append(['available_days', 'total_days']);

        return response()->json([
            'success' => true,
            'message' => 'Balance recalculado correctamente',
            'data' => [
                'balance' => $balance,
                'employee' => $employee->only(['id', 'full_name', 'hire_date']),
                'vacation_info' => VacationCalculatorService::getEmployeeVacationInfo($employee),
            ],
        ]);
    }

    /**
     * Aplicar ajuste manual al balance de vacaciones
     */
    public function applyAdjustment(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:2020|max:2100',
            'adjustment_days' => 'required|integer|min:-365|max:365',
            'adjustment_reason' => 'required|string|max:500',
        ]);

        $year = $validated['year'] ?? now()->year;

        // Obtener o crear el balance
        $balance = VacationBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->first();

        if (!$balance) {
            // Si no existe, inicializar primero
            $balance = VacationBalance::initializeForEmployee($employee, $year);
        }

        // Aplicar el ajuste
        $balance->applyAdjustment(
            $validated['adjustment_days'],
            $validated['adjustment_reason'],
            Auth::id()
        );

        $balance->load('adjustedByUser');
        $balance->append(['available_days', 'total_days']);

        return response()->json([
            'success' => true,
            'message' => 'Ajuste aplicado correctamente',
            'data' => [
                'balance' => $balance,
                'employee' => $employee->only(['id', 'full_name', 'hire_date']),
                'adjustment' => [
                    'days' => $validated['adjustment_days'],
                    'reason' => $validated['adjustment_reason'],
                    'applied_by' => Auth::user()->name ?? 'Sistema',
                    'applied_at' => now()->toDateTimeString(),
                ],
            ],
        ]);
    }

    /**
     * Obtener historial de balances de un empleado
     */
    public function getBalanceHistory(Employee $employee): JsonResponse
    {
        $balances = VacationBalance::where('employee_id', $employee->id)
            ->with('adjustedByUser')
            ->orderBy('year', 'desc')
            ->get();

        $balances->each(function (VacationBalance $balance) {
            $balance->append(['available_days', 'total_days']);
        });

        return response()->json([
            'success' => true,
            'data' => [
                'employee' => $employee->only(['id', 'full_name', 'hire_date', 'employee_number']),
                'balances' => $balances,
            ],
        ]);
    }
}
