<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::with(['enterprises', 'applications'])->get()->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'role' => $user->role ?? 'user',
                'created_at' => $user->created_at,
                'permissions' => [
                    'enterprises' => $user->enterprises->pluck('id')->toArray(),
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
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'role' => 'nullable|in:user,admin',
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $employeeId = $validated['employee_id'] ?? null;
        unset($validated['employee_id']);

        $validated['password'] = Hash::make($validated['password']);
        $validated['role'] = $validated['role'] ?? 'user';

        $user = User::create($validated);

        // Si se especificó un empleado, vincularlo al usuario
        if ($employeeId) {
            Employee::where('id', $employeeId)->update(['user_id' => $user->id]);
        }

        return response()->json([
            'message' => 'Usuario creado exitosamente',
            'user' => $user->load('employee')
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

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => 'sometimes|string|min:8',
            'phone' => 'nullable|string|max:20',
            'role' => 'sometimes|in:user,admin',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'Usuario actualizado exitosamente',
            'user' => $user
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json([
            'message' => 'Usuario eliminado exitosamente'
        ]);
    }

    /**
     * Assign enterprises to user
     */
    public function assignEnterprises(Request $request, string $id)
    {
        $user = User::findOrFail($id);
        
        $validated = $request->validate([
            'enterprise_ids' => 'required|array',
            'enterprise_ids.*' => 'exists:enterprises,id',
        ]);

        $user->enterprises()->sync($validated['enterprise_ids']);

        return response()->json([
            'message' => 'Empresas asignadas exitosamente',
            'user' => $user->load('enterprises')
        ]);
    }

    /**
     * Assign applications to user for specific enterprise
     */
    public function assignApplications(Request $request, string $userId, string $enterpriseId)
    {
        $user = User::findOrFail($userId);
        
        $validated = $request->validate([
            'application_ids' => 'required|array',
            'application_ids.*' => 'exists:applications,id',
        ]);

        // Sync applications for this user and enterprise
        $user->applications()->syncWithoutDetaching($validated['application_ids']);

        return response()->json([
            'message' => 'Aplicaciones asignadas exitosamente',
            'user' => $user->load('applications')
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
}
