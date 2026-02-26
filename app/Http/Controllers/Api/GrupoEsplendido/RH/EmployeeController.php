<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmployeeController extends Controller
{
    /**
     * Listar empleados (de todas las empresas del corporativo)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Employee::with(['enterprise', 'department', 'position', 'supervisor', 'workSchedule']);

        // Filtrar por empresa
        if ($request->has('enterprise_id')) {
            $query->where('enterprise_id', $request->enterprise_id);
        }

        // Filtrar por departamento
        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        // Filtrar por puesto
        if ($request->has('position_id')) {
            $query->where('position_id', $request->position_id);
        }

        // Filtrar por estado
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Búsqueda
        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Solo activos
        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $query->orderBy('last_name')->orderBy('first_name');

        // Paginación
        $perPage = $request->get('per_page', 15);
        $employees = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $employees,
        ]);
    }

    /**
     * Crear empleado
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'second_last_name' => 'nullable|string|max:100',
            'birth_date' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other',
            'curp' => 'nullable|string|size:18|unique:employees,curp',
            'rfc' => 'nullable|string|max:13',
            'nss' => 'nullable|string|max:11',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'emergency_contact' => 'nullable|string|max:100',
            'emergency_phone' => 'nullable|string|max:20',
            'address_street' => 'nullable|string|max:255',
            'address_number' => 'nullable|string|max:20',
            'address_interior' => 'nullable|string|max:20',
            'address_colony' => 'nullable|string|max:100',
            'address_city' => 'nullable|string|max:100',
            'address_state' => 'nullable|string|max:100',
            'address_zip' => 'nullable|string|max:10',
            'address' => 'nullable|string|max:500',
            'department_id' => 'nullable|exists:departments,id',
            'position_id' => 'nullable|exists:positions,id',
            'reports_to' => 'nullable|exists:employees,id',
            'hire_date' => 'required|date',
            'contract_type' => 'nullable|in:permanent,temporary,contractor,intern',
            'work_shift' => 'nullable|in:morning,afternoon,night,mixed,flexible',
            'work_schedule_id' => 'nullable|exists:work_schedules,id',
            'salary' => 'nullable|numeric|min:0',
            'payment_frequency' => 'nullable|in:weekly,biweekly,monthly',
            'notes' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpeg,jpg,png|max:2048',
        ]);

        // Generar número de empleado y códigos
        $validated['employee_number'] = Employee::generateEmployeeNumber($validated['enterprise_id']);
        $validated['qr_code'] = Employee::generateQRCode();
        $validated['pin'] = Employee::generatePIN();
        $validated['status'] = Employee::STATUS_ACTIVE;

        // Manejar foto
        if ($request->hasFile('photo')) {
            $validated['photo'] = $request->file('photo')->store('employees/photos', 'public');
        }

        $employee = Employee::create($validated);
        $employee->load(['enterprise', 'department', 'position', 'supervisor']);

        return response()->json([
            'success' => true,
            'message' => 'Empleado creado exitosamente',
            'data' => $employee,
        ], 201);
    }

    /**
     * Mostrar empleado
     */
    public function show(Employee $employee): JsonResponse
    {
        $employee->load([
            'enterprise',
            'department',
            'position',
            'supervisor',
            'workSchedule',
            'subordinates',
            'managedDepartments',
        ]);

        // Agregar info adicional
        $employee->append(['seniority', 'age']);

        return response()->json([
            'success' => true,
            'data' => $employee,
        ]);
    }

    /**
     * Actualizar empleado
     */
    public function update(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'sometimes|exists:enterprises,id',
            'first_name' => 'sometimes|required|string|max:100',
            'last_name' => 'sometimes|required|string|max:100',
            'second_last_name' => 'nullable|string|max:100',
            'birth_date' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other',
            'curp' => 'nullable|string|size:18|unique:employees,curp,' . $employee->id,
            'rfc' => 'nullable|string|max:13',
            'nss' => 'nullable|string|max:11',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'emergency_contact' => 'nullable|string|max:100',
            'emergency_phone' => 'nullable|string|max:20',
            'address_street' => 'nullable|string|max:255',
            'address_number' => 'nullable|string|max:20',
            'address_interior' => 'nullable|string|max:20',
            'address_colony' => 'nullable|string|max:100',
            'address_city' => 'nullable|string|max:100',
            'address_state' => 'nullable|string|max:100',
            'address_zip' => 'nullable|string|max:10',
            'address' => 'nullable|string|max:500',
            'department_id' => 'nullable|exists:departments,id',
            'position_id' => 'nullable|exists:positions,id',
            'reports_to' => 'nullable|exists:employees,id',
            'hire_date' => 'sometimes|required|date',
            'termination_date' => 'nullable|date|after:hire_date',
            'contract_type' => 'nullable|in:permanent,temporary,contractor,intern',
            'work_shift' => 'nullable|in:morning,afternoon,night,mixed,flexible',
            'work_schedule_id' => 'nullable|exists:work_schedules,id',
            'salary' => 'nullable|numeric|min:0',
            'payment_frequency' => 'nullable|in:weekly,biweekly,monthly',
            'status' => 'nullable|in:active,inactive,on_leave,terminated',
            'notes' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpeg,jpg,png|max:2048',
        ]);

        // No puede ser su propio supervisor
        if (isset($validated['reports_to']) && $validated['reports_to'] == $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Un empleado no puede ser su propio supervisor',
            ], 422);
        }

        // Manejar foto
        if ($request->hasFile('photo')) {
            // Eliminar foto anterior
            if ($employee->photo) {
                Storage::disk('public')->delete($employee->photo);
            }
            $validated['photo'] = $request->file('photo')->store('employees/photos', 'public');
        }

        $employee->update($validated);
        $employee->load(['enterprise', 'department', 'position', 'supervisor']);

        return response()->json([
            'success' => true,
            'message' => 'Empleado actualizado exitosamente',
            'data' => $employee,
        ]);
    }

    /**
     * Eliminar empleado
     */
    public function destroy(Employee $employee): JsonResponse
    {
        // Soft delete
        $employee->delete();

        return response()->json([
            'success' => true,
            'message' => 'Empleado eliminado exitosamente',
        ]);
    }

    /**
     * Regenerar código QR
     */
    public function regenerateQR(Employee $employee): JsonResponse
    {
        $employee->qr_code = Employee::generateQRCode();
        $employee->save();

        return response()->json([
            'success' => true,
            'message' => 'Código QR regenerado exitosamente',
            'data' => [
                'qr_code' => $employee->qr_code,
            ],
        ]);
    }

    /**
     * Regenerar PIN
     */
    public function regeneratePIN(Employee $employee): JsonResponse
    {
        $employee->pin = Employee::generatePIN();
        $employee->save();

        return response()->json([
            'success' => true,
            'message' => 'PIN regenerado exitosamente',
            'data' => [
                'pin' => $employee->pin,
            ],
        ]);
    }

    /**
     * Obtener credencial del empleado (datos para QR)
     */
    public function getCredential(Employee $employee): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'position' => $employee->position?->name,
                'department' => $employee->department?->name,
                'enterprise' => $employee->enterprise?->name,
                'qr_code' => $employee->qr_code,
                'photo_url' => $employee->photo_url,
            ],
        ]);
    }

    /**
     * Dar de baja empleado
     */
    public function terminate(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'termination_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $employee->update([
            'status' => Employee::STATUS_TERMINATED,
            'termination_date' => $validated['termination_date'],
            'notes' => $employee->notes . "\n\n[BAJA] " . ($validated['notes'] ?? 'Sin comentarios'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Empleado dado de baja exitosamente',
            'data' => $employee,
        ]);
    }
}
