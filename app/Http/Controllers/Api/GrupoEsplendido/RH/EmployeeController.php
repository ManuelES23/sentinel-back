<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\DevicePairing;
use App\Models\Employee;
use App\Models\EmployeeFaceTemplate;
use App\Services\RH\SesionPortalVacaciones;
use App\Services\RH\VerificadorPinEmpleado;
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
        $query = Employee::with(['enterprise', 'department', 'position', 'supervisor', 'workSchedule'])
            ->withExists(['faceTemplate as has_face_template']);

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

        // Búsqueda (cada palabra en cualquier campo: nombre completo, número, CURP, clave…)
        if (is_string($request->search) && trim($request->search) !== '') {
            $query->search($request->search);
        }

        // Solo activos
        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        // Eliminados (borrado lógico): no salen en la lista normal, pero siguen ocupando su CURP y su clave.
        if ($request->boolean('eliminados', false)) {
            $query->onlyTrashed();
        }

        $query->orderBy('last_name')->orderBy('first_name')->orderBy('id');

        // Paginación (1 a 1000)
        $perPage = is_numeric($request->get('per_page')) ? min(1000, max(1, (int) $request->get('per_page'))) : 15;
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
            'curp' => 'nullable|string|size:18',
            'rfc' => 'nullable|string|max:13',
            'nss' => 'nullable|string|max:11',
            'checker_key' => 'nullable|string|max:50',
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

        if ($duplicado = $this->duplicado($validated)) {
            return $duplicado;
        }

        // Generar número de empleado y códigos
        $validated['employee_number'] = Employee::generateEmployeeNumber($validated['enterprise_id']);
        $validated['qr_code'] = Employee::generateQRCode();
        $validated['pin'] = Employee::generatePIN();
        $pinGenerado = $validated['pin'];
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
            'pin' => $pinGenerado, // única vez que se ve en claro
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
            'curp' => 'nullable|string|size:18',
            'rfc' => 'nullable|string|max:13',
            'nss' => 'nullable|string|max:11',
            'checker_key' => 'nullable|string|max:50',
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

        if ($duplicado = $this->duplicado($validated, $employee->id)) {
            return $duplicado;
        }

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
    public function destroy(Employee $employee, SesionPortalVacaciones $sesionesPortal): JsonResponse
    {
        $this->revokeActiveFaceTemplate($employee->id);
        $this->revokeActiveDevicePairings($employee->id);
        $sesionesPortal->revocarTodas($employee->id);

        // Soft delete
        $employee->delete();

        return response()->json([
            'success' => true,
            'message' => 'Empleado eliminado exitosamente',
        ]);
    }

    /** Restaura un empleado eliminado (borrado lógico) con sus datos e historial; conserva su estado. */
    public function restore(int $id): JsonResponse
    {
        $employee = Employee::onlyTrashed()->findOrFail($id);
        $employee->restore();
        $employee->load(['enterprise', 'department', 'position', 'supervisor']);

        return response()->json([
            'success' => true,
            'message' => 'Empleado restaurado',
            'data' => $employee,
        ]);
    }

    /**
     * CURP y clave de checador son únicas en la tabla, también contra los eliminados (el índice no
     * distingue). Si ya las tiene otro, 422 con el nombre y número de quien la tiene y, en
     * `existente`, si está eliminado (para ofrecer restaurarlo). La CURP se compara sin mayúsculas.
     */
    private function duplicado(array $datos, ?int $ignorarId = null): ?JsonResponse
    {
        $campos = ['curp' => 'La CURP', 'checker_key' => 'La clave de checador'];

        foreach ($campos as $campo => $etiqueta) {
            $valor = trim((string) ($datos[$campo] ?? ''));
            if ($valor === '') {
                continue;
            }

            $otro = Employee::withTrashed()
                ->whereRaw("UPPER({$campo}) = ?", [mb_strtoupper($valor)])
                ->when($ignorarId, fn ($q) => $q->whereKeyNot($ignorarId))
                ->first();
            if (! $otro) {
                continue;
            }

            $eliminado = $otro->trashed();
            $mensaje = "{$etiqueta} ya está registrada para {$otro->full_name} ({$otro->employee_number})."
                .($eliminado ? ' Ese empleado está eliminado: restáuralo desde «Eliminados» en lugar de darlo de alta otra vez.' : '');

            return response()->json([
                'success' => false,
                'message' => $mensaje,
                'errors' => [$campo => [$mensaje]],
                'existente' => ['id' => $otro->id, 'nombre' => $otro->full_name, 'numero' => $otro->employee_number, 'eliminado' => $eliminado],
            ], 422);
        }

        return null;
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
     * Regenerar PIN. Es la única vez que RH ve el PIN en claro; también
     * desbloquea al empleado si se bloqueó por intentos fallidos y cierra sus
     * sesiones del portal de vacaciones (el PIN anterior ya no debe dar acceso).
     */
    public function regeneratePIN(Employee $employee, VerificadorPinEmpleado $verificador, SesionPortalVacaciones $sesionesPortal): JsonResponse
    {
        $pin = Employee::generatePIN();
        $employee->pin = $pin;
        $employee->save();
        $verificador->desbloquear($employee);
        $sesionesPortal->revocarTodas($employee->id);

        return response()->json([
            'success' => true,
            'message' => 'PIN regenerado exitosamente',
            'data' => ['pin' => $pin],
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
    public function terminate(Request $request, Employee $employee, SesionPortalVacaciones $sesionesPortal): JsonResponse
    {
        $validated = $request->validate([
            'termination_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $this->revokeActiveFaceTemplate($employee->id);
        $this->revokeActiveDevicePairings($employee->id);
        $sesionesPortal->revocarTodas($employee->id);

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

    /**
     * Revoca la plantilla facial activa del empleado, si tiene una — parte
     * del ciclo de vida del dato biométrico al darlo de baja (por
     * terminate() o por destroy()). Se carga el modelo primero (no un
     * ->update() masivo sobre la query) para que el trait Loggable dispare
     * su evento updated() y quede auditado en activity_logs.
     */
    private function revokeActiveFaceTemplate(int $employeeId): void
    {
        $activeTemplate = EmployeeFaceTemplate::where('employee_id', $employeeId)
            ->where('status', EmployeeFaceTemplate::STATUS_ACTIVE)
            ->first();

        $activeTemplate?->update([
            'status' => EmployeeFaceTemplate::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }

    /**
     * Revoca cualquier emparejamiento de dispositivo personal (mode 'self')
     * que este empleado haya hecho, al darlo de baja (terminate() o
     * destroy()). Sin esto, el teléfono del empleado conservaba un token de
     * dispositivo funcional indefinidamente — podía seguir descargando el
     * roster-package completo (embeddings faciales de todos los empleados
     * enrolados) y mandando checadas hasta que un admin de RH lo encontrara
     * y revocara manualmente desde "Dispositivos". Se cargan los modelos
     * primero (no un ->update() masivo sobre la query) por el mismo motivo
     * que revokeActiveFaceTemplate(): que el trait Loggable dispare su
     * evento updated() y quede auditado en activity_logs. Un empleado puede
     * tener más de un emparejamiento self activo (p. ej. si se reemparejó
     * tras perder el teléfono), así que se revocan todos.
     */
    private function revokeActiveDevicePairings(int $employeeId): void
    {
        DevicePairing::where('paired_by_employee_id', $employeeId)
            ->where('mode', DevicePairing::MODE_SELF)
            ->whereNull('revoked_at')
            ->get()
            ->each(fn (DevicePairing $pairing) => $pairing->update(['revoked_at' => now()]));
    }
}
