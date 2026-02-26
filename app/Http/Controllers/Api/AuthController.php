<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login de usuario
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        // Crear token
        $token = $user->createToken('auth-token')->plainTextToken;

        // Obtener permisos del usuario
        $permissions = $this->getUserPermissions($user);

        return response()->json([
            'message' => 'Login exitoso',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'user',
            ],
            'token' => $token,
            'permissions' => $permissions
        ]);
    }

    /**
     * Registro de usuario
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'user', // Por defecto usuario regular
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Usuario registrado exitosamente',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'token' => $token,
            'permissions' => [
                'enterprises' => [],
                'applications' => []
            ]
        ], 201);
    }

    /**
     * Obtener información del usuario autenticado
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();
        $permissions = $this->getUserPermissions($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'user',
            ],
            'permissions' => $permissions
        ]);
    }

    /**
     * Logout del usuario
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout exitoso'
        ]);
    }

    /**
     * Obtener permisos del usuario usando sistema jerárquico
     */
    private function getUserPermissions(User $user): array
    {
        // Obtener empresas con acceso desde tablas jerárquicas
        $enterpriseAccess = \App\Models\UserEnterpriseAccess::where('user_id', $user->id)
            ->where('is_active', true)
            ->with('enterprise:id,slug,name')
            ->get();
        
        $enterprises = $enterpriseAccess->pluck('enterprise.slug')->filter()->toArray();

        // Obtener aplicaciones con acceso desde tablas jerárquicas
        $applicationAccess = \App\Models\UserApplicationAccess::where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['application:id,slug,name,enterprise_id', 'application.enterprise:id,slug'])
            ->get();

        // Agrupar aplicaciones por empresa
        $applications = [];
        foreach ($applicationAccess as $access) {
            if ($access->application && $access->application->enterprise) {
                $enterpriseSlug = $access->application->enterprise->slug;
                if (!isset($applications[$enterpriseSlug])) {
                    $applications[$enterpriseSlug] = [];
                }
                $applications[$enterpriseSlug][] = $access->application->slug;
            }
        }

        return [
            'enterprises' => $enterprises,
            'applications' => $applications
        ];
    }
}
