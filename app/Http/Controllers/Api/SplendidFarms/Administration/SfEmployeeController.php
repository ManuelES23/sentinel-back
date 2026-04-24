<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\SfEmployee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SfEmployeeController extends Controller
{
    /**
     * Listar empleados SF (paginado, con filtros).
     */
    public function index(Request $request): JsonResponse
    {
        $query = SfEmployee::query()
            ->with('enterprise:id,name,slug')
            ->when($request->enterprise_id, fn($q, $v) => $q->where('enterprise_id', $v))
            ->when($request->employee_type, fn($q, $v) => $q->where('employee_type', $v))
            ->when($request->status, fn($q, $v) => $q->where('status', $v))
            ->when($request->department, fn($q, $v) => $q->where('department', 'like', "%{$v}%"))
            ->when($request->search, fn($q, $v) => $q->search($v))
            ->orderBy('last_name')
            ->orderBy('first_name');

        $perPage = (int) $request->get('per_page', 15);
        $employees = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $employees,
        ]);
    }

    /**
     * Lista simple para selects.
     */
    public function list(Request $request): JsonResponse
    {
        $items = SfEmployee::query()
            ->when($request->enterprise_id, fn($q, $v) => $q->where('enterprise_id', $v))
            ->where('status', SfEmployee::STATUS_ACTIVE)
            ->select('id', 'code', 'first_name', 'last_name', 'second_last_name', 'employee_type')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn($e) => [
                'id' => $e->id,
                'code' => $e->code,
                'name' => $e->full_name,
                'employee_type' => $e->employee_type,
            ]);

        return response()->json(['success' => true, 'data' => $items]);
    }

    /**
     * Crear empleado SF.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateData($request);

        $validated['code'] = SfEmployee::generateCode();
        $validated['status'] = $validated['status'] ?? SfEmployee::STATUS_ACTIVE;

        if ($request->hasFile('photo')) {
            $validated['photo'] = $request->file('photo')->store('sf_employees/photos', 'public');
        }

        $employee = SfEmployee::create($validated);
        $employee->load('enterprise:id,name,slug');

        return response()->json([
            'success' => true,
            'message' => 'Empleado creado exitosamente',
            'data' => $employee,
        ], 201);
    }

    /**
     * Mostrar empleado.
     */
    public function show(SfEmployee $sfEmployee): JsonResponse
    {
        $sfEmployee->load(['enterprise:id,name,slug', 'contracts']);

        return response()->json([
            'success' => true,
            'data' => $sfEmployee,
        ]);
    }

    /**
     * Actualizar empleado.
     */
    public function update(Request $request, SfEmployee $sfEmployee): JsonResponse
    {
        $validated = $this->validateData($request, $sfEmployee->id, true);

        if ($request->hasFile('photo')) {
            if ($sfEmployee->photo) {
                Storage::disk('public')->delete($sfEmployee->photo);
            }
            $validated['photo'] = $request->file('photo')->store('sf_employees/photos', 'public');
        }

        $sfEmployee->update($validated);
        $sfEmployee->load('enterprise:id,name,slug');

        return response()->json([
            'success' => true,
            'message' => 'Empleado actualizado exitosamente',
            'data' => $sfEmployee,
        ]);
    }

    /**
     * Baja lógica.
     */
    public function destroy(SfEmployee $sfEmployee): JsonResponse
    {
        $sfEmployee->delete();

        return response()->json([
            'success' => true,
            'message' => 'Empleado eliminado exitosamente',
        ]);
    }

    /**
     * Validación común.
     */
    private function validateData(Request $request, ?int $ignoreId = null, bool $partial = false): array
    {
        $request->merge([
            'curp' => $this->normalizeUpperString($request->input('curp')),
            'rfc' => $this->normalizeUpperString($request->input('rfc')),
            'nss' => $this->normalizeUpperString($request->input('nss')),
            'checker_key' => $this->normalizeUpperString($request->input('checker_key')),
        ]);

        $req = $partial ? 'sometimes|required' : 'required';
        $opt = 'sometimes';

        return $request->validate([
            'enterprise_id' => $req . '|exists:enterprises,id',
            'employee_type' => $opt . '|in:permanent,temporary',
            'first_name' => $req . '|string|max:100',
            'last_name' => $req . '|string|max:100',
            'second_last_name' => 'nullable|string|max:100',
            'birth_date' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other',
            'curp' => 'nullable|string|size:18',
            'rfc' => 'nullable|string|max:13',
            'nss' => 'nullable|string|max:11',
            'checker_key' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('sf_employees', 'checker_key')->ignore($ignoreId),
            ],
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
            'department' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:100',
            'work_location' => 'nullable|string|max:150',
            'hire_date' => $req . '|date',
            'termination_date' => 'nullable|date|after_or_equal:hire_date',
            'payment_frequency' => $opt . '|in:weekly,biweekly,monthly',
            'salary' => 'nullable|numeric|min:0',
            'daily_rate' => 'nullable|numeric|min:0',
            'weekly_hours' => 'nullable|numeric|min:0|max:168',
            'weekly_schedule' => 'nullable|array',
            'status' => $opt . '|in:active,inactive,on_leave,terminated',
            'notes' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpeg,jpg,png|max:2048',
        ]);
    }

    private function normalizeUpperString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }
}
