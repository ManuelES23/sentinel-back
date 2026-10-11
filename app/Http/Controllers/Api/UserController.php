<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Employee;
use App\Models\UserEnterpriseAccess;
use App\Models\ActivityLog;
use App\Support\PasswordPolicy;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Las empresas asignadas viven en user_enterprise_access (donde escribe el
        // modal de permisos jerárquicos), no en el pivote legacy user_enterprises.
        $empresasPorUsuario = UserEnterpriseAccess::where('is_active', true)
            ->get(['user_id', 'enterprise_id'])
            ->groupBy('user_id');

        $users = User::with(['applications', 'employee.enterprise', 'employee.position'])->get()->map(function ($user) use ($empresasPorUsuario) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'role' => $user->role ?? 'user',
                'must_change_password' => (bool) $user->must_change_password,
                'created_at' => $user->created_at,
                'employee' => $this->resumenEmpleado($user->employee),
                'permissions' => [
                    'enterprises' => ($empresasPorUsuario->get($user->id) ?? collect())
                        ->pluck('enterprise_id')->unique()->values()->toArray(),
                    'applications' => $user->applications->groupBy('enterprise_id')->map(function ($apps) {
                        return $apps->pluck('id')->toArray();
                    })->toArray()
                ]
            ];
        });

        return response()->json($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'string', Password::defaults()],
            'phone' => 'nullable|string|max:20',
            'role' => 'nullable|in:user,admin,superadmin',
            'employee_id' => 'nullable|integer|exists:employees,id',
        ], PasswordPolicy::messages());

        $permiso = Gate::inspect('create', [User::class, $validated['role'] ?? null]);
        if ($permiso->denied()) {
            return $this->prohibido($permiso);
        }

        $employeeId = $validated['employee_id'] ?? null;
        unset($validated['employee_id']);

        $validated['password'] = Hash::make($validated['password']);
        $validated['role'] = $validated['role'] ?? 'user';

        $user = DB::transaction(function () use ($validated, $employeeId) {
            $user = User::create($validated);

            // Si se especificó un empleado, vincularlo al usuario
            if ($employeeId) {
                $this->ligarEmpleado($user, $employeeId);
            }

            return $user;
        });

        $user->load('employee.enterprise', 'employee.position');

        return response()->json([
            'message' => 'Usuario creado exitosamente',
            'user' => array_merge($user->withoutRelations()->toArray(), [
                'employee' => $this->resumenEmpleado($user->employee),
            ]),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::with(['enterprises', 'applications'])->findOrFail($id);
        
        return response()->json($user);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $permiso = Gate::inspect('update', $user);
        if ($permiso->denied()) {
            return $this->prohibido($permiso);
        }

        // La contraseña ya no se edita aquí: la única vía administrativa es
        // POST /users/{user}/reset-password. Si llega "password", se ignora.
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => 'nullable|string|max:20',
            'role' => 'sometimes|in:user,admin,superadmin',
            // Presente y null = quitar el empleado; ausente = no tocar el vínculo.
            'employee_id' => 'sometimes|nullable|integer|exists:employees,id',
        ]);

        if (isset($validated['role']) && $validated['role'] !== $user->role) {
            $permiso = Gate::inspect('changeRole', [$user, $validated['role']]);
            if ($permiso->denied()) {
                return $this->prohibido($permiso);
            }
        }

        $cambiaEmpleado = array_key_exists('employee_id', $validated);
        $employeeId = $validated['employee_id'] ?? null;
        unset($validated['employee_id']);

        DB::transaction(function () use ($user, $validated, $cambiaEmpleado, $employeeId) {
            $user->update($validated);

            if ($cambiaEmpleado) {
                $this->ligarEmpleado($user, $employeeId);
            }
        });

        $user->load('employee.enterprise', 'employee.position');

        return response()->json([
            'message' => 'Usuario actualizado exitosamente',
            'user' => array_merge($user->withoutRelations()->toArray(), [
                'employee' => $this->resumenEmpleado($user->employee),
            ]),
        ]);
    }

    /**
     * Liga el usuario con un empleado de RH (o lo desliga si $employeeId es
     * null). Un usuario tiene un solo empleado: el anterior queda libre. No se
     * toma el empleado de otro usuario ni uno inactivo. Llamar dentro de una
     * transacción.
     */
    private function ligarEmpleado(User $user, ?int $employeeId): void
    {
        // Lista: datos viejos pueden tener más de un empleado con el mismo usuario.
        $anteriores = Employee::where('user_id', $user->id)->orderBy('id')->pluck('id')->all();
        if ($anteriores === ($employeeId ? [$employeeId] : [])) {
            return;
        }

        if ($employeeId) {
            $empleado = Employee::with('user:id,name')->lockForUpdate()->findOrFail($employeeId);

            if ($empleado->user_id && (int) $empleado->user_id !== (int) $user->id) {
                throw ValidationException::withMessages([
                    'employee_id' => "Este empleado ya está ligado al usuario {$empleado->user?->name}.",
                ]);
            }
            if ($empleado->status !== 'active') {
                throw ValidationException::withMessages(['employee_id' => 'El empleado no está activo.']);
            }
        }

        Employee::where('user_id', $user->id)->update(['user_id' => null]);
        if ($employeeId) {
            Employee::whereKey($employeeId)->update(['user_id' => $user->id]);
        }

        ActivityLog::log('user_employee_link', User::class, $user->id,
            ['employee_ids' => $anteriores], ['employee_id' => $employeeId]);
    }

    private function resumenEmpleado(?Employee $empleado): ?array
    {
        if (! $empleado) {
            return null;
        }

        return [
            'id' => $empleado->id,
            'employee_number' => $empleado->employee_number,
            'full_name' => $empleado->full_name,
            'enterprise_id' => $empleado->enterprise_id,
            'enterprise_name' => $empleado->enterprise?->name,
            'position' => $empleado->position?->name,
        ];
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);

        $permiso = Gate::inspect('delete', $user);
        if ($permiso->denied()) {
            return $this->prohibido($permiso);
        }

        $user->delete();

        return response()->json([
            'message' => 'Usuario eliminado exitosamente'
        ]);
    }

    /**
     * Restablece la contraseña de un usuario: escrita por el admin (manual) o
     * generada por el sistema (generate, se devuelve una sola vez). Cierra todas
     * las sesiones del usuario y opcionalmente lo obliga a cambiarla al entrar.
     */
    public function resetPassword(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $permiso = Gate::inspect('resetPassword', $user);
        if ($permiso->denied()) {
            return $this->prohibido($permiso);
        }

        $validated = $request->validate([
            'mode' => 'required|in:manual,generate',
            'password' => ['required_if:mode,manual', 'nullable', 'string', 'confirmed', Password::defaults()],
            'force_change' => 'required|boolean',
        ], PasswordPolicy::messages());

        $generada = $validated['mode'] === 'generate';
        // La temporal cumple la política (sin símbolos salvo que se exijan).
        $nueva = $generada ? PasswordPolicy::generate() : $validated['password'];
        $forzar = (bool) $validated['force_change'];

        DB::transaction(function () use ($user, $nueva, $forzar, $validated) {
            $user->forceFill([
                'password' => Hash::make($nueva),
                'must_change_password' => $forzar,
            ])->save();

            $user->tokens()->delete();

            ActivityLog::log('password_reset', User::class, $user->id, null, [
                'mode' => $validated['mode'],
                'force_change' => $forzar,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Contraseña restablecida. Se cerraron las sesiones activas del usuario.',
            'data' => ['must_change_password' => $forzar] + ($generada ? ['temporary_password' => $nueva] : []),
        ]);
    }

    /**
     * Get employees without linked user account
     */
    public function employeesWithoutUser(Request $request)
    {
        $query = Employee::whereNull('user_id')
            ->where('status', 'active')
            ->with(['department', 'position', 'enterprise']);

        // Filtrar por empresa si se especifica
        if ($request->has('enterprise_id')) {
            $query->where('enterprise_id', $request->enterprise_id);
        }

        $employees = $query->get()->map(function ($employee) {
            return [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'phone' => $employee->phone ?? $employee->mobile,
                'department' => $employee->department?->name,
                'position' => $employee->position?->name,
                'enterprise_id' => $employee->enterprise_id,
                'enterprise_name' => $employee->enterprise?->name,
            ];
        });

        return response()->json($employees);
    }

    /**
     * 403 con el formato de error estándar del proyecto y el motivo de la policy.
     */
    private function prohibido(AccessResponse $permiso): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $permiso->message() ?? 'No tienes permiso para realizar esta acción.',
        ], 403);
    }
}
