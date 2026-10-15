<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\VacationBalance;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\RH\AprobadorVacaciones;
use App\Services\RH\VacationRequestService;
use App\Services\VacationCalculatorService;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Vacaciones desde RH de Grupo Espléndido. Las reglas viven en
 * VacationRequestService; aquí solo se validan la entrada y los permisos
 * del submódulo RH → Gestión → Vacaciones (view/create/edit/aprobar).
 */
class VacationController extends Controller
{
    use VerificaPermisoSubmodulo;

    private const RELACIONES = ['employee.enterprise', 'employee.position', 'employee.department', 'approver', 'approvals.decider:id,name'];

    public function __construct(private VacationRequestService $vacaciones) {}

    public function index(Request $request): JsonResponse
    {
        $this->exigir('view');

        $vacations = VacationRequest::with(self::RELACIONES)
            ->when($request->has('enterprise_id'), fn ($q) => $q->where('enterprise_id', $request->enterprise_id))
            ->when($request->has('employee_id'), fn ($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->has('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->has('year'), fn ($q) => $q->forYear((int) $request->year))
            ->when($request->has('start_date') && $request->has('end_date'), fn ($q) => $q
                ->whereDate('start_date', '<=', $request->end_date)
                ->whereDate('end_date', '>=', $request->start_date))
            ->orderBy('created_at', 'desc')
            ->get()
            ->each->append(['status_label', 'status_color']);

        return response()->json(['success' => true, 'data' => $vacations]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->exigir('create');

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'reason' => 'nullable|string|max:500',
            'omitir_antiguedad' => 'sometimes|boolean',
        ]);

        $vacation = $this->vacaciones->crear(
            Employee::findOrFail($validated['employee_id']),
            Carbon::parse($validated['start_date']),
            Carbon::parse($validated['end_date']),
            $validated['reason'] ?? null,
            VacationRequest::SOURCE_RH,
            Auth::user(),
            ['permitir_pasado' => true, 'omitir_antiguedad' => (bool) ($validated['omitir_antiguedad'] ?? false)],
        );

        return response()->json([
            'success' => true,
            'message' => 'Solicitud de vacaciones creada exitosamente',
            'data' => $this->presentar($vacation),
        ], 201);
    }

    public function show(VacationRequest $vacation): JsonResponse
    {
        $this->exigir('view');

        return response()->json(['success' => true, 'data' => $this->presentar($vacation->load('creator'))]);
    }

    public function update(Request $request, VacationRequest $vacation): JsonResponse
    {
        $this->exigir('edit');

        $validated = $request->validate([
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date',
            'reason' => 'nullable|string|max:500',
        ]);

        $vacation = $this->vacaciones->reprogramar(
            $vacation,
            Carbon::parse($validated['start_date'] ?? $vacation->start_date),
            Carbon::parse($validated['end_date'] ?? $vacation->end_date),
            $validated['reason'] ?? null,
            ['permitir_pasado' => true, 'omitir_antiguedad' => true],
        );

        return response()->json([
            'success' => true,
            'message' => 'Solicitud actualizada exitosamente',
            'data' => $this->presentar($vacation),
        ]);
    }

    public function destroy(VacationRequest $vacation): JsonResponse
    {
        $this->exigir('edit');

        if ($vacation->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden eliminar solicitudes pendientes'], 422);
        }

        // Las del portal o del perfil las pidió el empleado: se cancelan (con aviso), no se borran en silencio.
        if ($vacation->source !== VacationRequest::SOURCE_RH) {
            return response()->json(['success' => false, 'message' => 'Solo se pueden eliminar solicitudes capturadas por RH; cancela esta para avisar al empleado.'], 422);
        }

        $vacation->delete();

        return response()->json(['success' => true, 'message' => 'Solicitud eliminada exitosamente']);
    }

    /** Aprobar desde RH = resolver como RH en el paso que vaya. */
    public function approve(VacationRequest $vacation): JsonResponse
    {
        $this->exigir('aprobar');

        $vacation = $this->vacaciones->resolverComoRh($vacation, Auth::user(), VacationRequestApproval::APPROVED);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud aprobada exitosamente',
            'data' => $this->presentar($vacation),
        ]);
    }

    public function reject(Request $request, VacationRequest $vacation): JsonResponse
    {
        $this->exigir('aprobar');

        $validated = $request->validate(['rejection_reason' => 'required|string|max:500']);
        $vacation = $this->vacaciones->resolverComoRh($vacation, Auth::user(), VacationRequestApproval::REJECTED, $validated['rejection_reason']);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud rechazada',
            'data' => $this->presentar($vacation),
        ]);
    }

    public function cancel(VacationRequest $vacation): JsonResponse
    {
        $this->exigir('edit');

        $vacation = $this->vacaciones->cancelar($vacation, VacationRequestService::CANCELA_RH);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud cancelada',
            'data' => $this->presentar($vacation),
        ]);
    }

    private function presentar(VacationRequest $vacation): VacationRequest
    {
        return $vacation->load(self::RELACIONES)->append(['status_label', 'status_color']);
    }

    /** Qué puede hacer el usuario en Vacaciones; el front oculta lo demás (la API sigue respondiendo 403). */
    public function misPermisos(): JsonResponse
    {
        $empresaId = (int) Enterprise::where('slug', AprobadorVacaciones::EMPRESA_CORPORATIVA)->value('id');

        return response()->json([
            'success' => true,
            'data' => collect(['view', 'create', 'edit', 'aprobar'])->mapWithKeys(fn (string $permiso) => [
                $permiso => $empresaId && $this->tienePermisoSubmodulo($empresaId, 'gestion', 'vacaciones', $permiso, 'rh'),
            ]),
        ]);
    }

    private function exigir(string $permiso): void
    {
        $this->exigirPermisoSubmodulo(
            (int) Enterprise::where('slug', AprobadorVacaciones::EMPRESA_CORPORATIVA)->value('id') ?: null,
            'gestion',
            'vacaciones',
            $permiso,
            'No tienes permiso para realizar esta acción en Vacaciones.',
            'rh',
        );
    }

    /**
     * Obtener balance de vacaciones de un empleado
     */
    public function getBalance(Request $request): JsonResponse
    {
        $this->exigir('view');

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
     * Obtener información completa de vacaciones de un empleado según LFT México
     */
    public function getEmployeeVacationInfo(Employee $employee): JsonResponse
    {
        $this->exigir('view');

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
        $this->exigir('view');

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
        $this->exigir('view');

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
        $this->exigir('edit');

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
        $this->exigir('edit');

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
        $this->exigir('edit');

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
        $this->exigir('view');

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
