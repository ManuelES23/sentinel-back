<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    private const ACTIVIDAD_RECIENTE = 8;

    /**
     * Resumen del panel de administración: cifras, actividad reciente y
     * alertas calculadas. Las alertas con conteo 0 se omiten.
     */
    public function index(): JsonResponse
    {
        // Las empresas asignadas viven en user_enterprise_access, no en el
        // pivote legacy user_enterprises.
        $conEmpresa = UserEnterpriseAccess::where('is_active', true)->select('user_id');

        $alertas = collect([
            // Usuarios sin empresa que no sean administradores (los administradores
            // gestionan el sistema y normalmente carecen de empresa asignada).
            'users_without_enterprise' => User::whereNotIn('role', EnsureUserIsAdmin::ROLES_ADMIN)
                ->whereNotIn('id', $conEmpresa)
                ->count(),
            'users_must_change_password' => User::where('must_change_password', true)->count(),
        ])
            ->filter(fn (int $count) => $count > 0)
            ->map(fn (int $count, string $key) => ['key' => $key, 'count' => $count])
            ->values();

        $actividad = ActivityLog::with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::ACTIVIDAD_RECIENTE)
            ->get(['id', 'user_id', 'action', 'model', 'model_id', 'enterprise', 'module', 'created_at'])
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'model' => $log->model,
                'model_id' => $log->model_id,
                'enterprise' => $log->enterprise,
                'module' => $log->module,
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => [
                    'users_total' => User::count(),
                    'enterprises_active' => Enterprise::where('is_active', true)->count(),
                    'applications_active' => Application::where('is_active', true)->count(),
                    'modules_active' => Module::where('is_active', true)->count(),
                ],
                'recent_activity' => $actividad,
                'alerts' => $alertas,
            ],
        ]);
    }
}
