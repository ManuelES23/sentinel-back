<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ActivityLogController extends Controller
{
    /**
     * Listar logs de actividad con filtros
     */
    public function index(Request $request)
    {
        try {
            $query = ActivityLog::with('user:id,name,email');

            if ($request->filled('user_id')) {
                $query->byUser($request->user_id);
            }

            if ($request->filled('action')) {
                $query->byAction($request->action);
            }

            if ($request->filled('model')) {
                $query->byModel($request->model);
            }

            // Filtros de jerarquía
            foreach (['enterprise', 'application', 'module', 'submodule'] as $campo) {
                if ($request->filled($campo)) {
                    $query->where($campo, $request->input($campo));
                }
            }

            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->dateRange($request->start_date, $request->end_date);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('action', 'like', "%{$search}%")
                        ->orWhere('model', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            $logs = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $logs,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al listar logs de actividad: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al cargar los logs: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Valores distintos de `model` registrados, para el filtro "Entidad".
     */
    public function models()
    {
        $modelos = ActivityLog::whereNotNull('model')
            ->distinct()
            ->orderBy('model')
            ->pluck('model');

        return response()->json([
            'success' => true,
            'data' => $modelos,
        ]);
    }

    /**
     * Obtener detalle de un log específico
     */
    public function show($id)
    {
        try {
            $log = ActivityLog::with('user')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $log,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Log no encontrado',
            ], 404);
        }
    }

    /**
     * Obtener estadísticas de logs
     */
    public function stats(Request $request)
    {
        try {
            $startDate = $request->get('start_date', now()->subDays(30));
            $endDate = $request->get('end_date', now());

            $stats = [
                'total_logs' => ActivityLog::dateRange($startDate, $endDate)->count(),
                'by_action' => ActivityLog::dateRange($startDate, $endDate)
                    ->selectRaw('action, count(*) as count')
                    ->groupBy('action')
                    ->get(),
                'by_model' => ActivityLog::dateRange($startDate, $endDate)
                    ->selectRaw('model, count(*) as count')
                    ->whereNotNull('model')
                    ->groupBy('model')
                    ->get(),
                'by_user' => ActivityLog::with('user:id,name')
                    ->dateRange($startDate, $endDate)
                    ->selectRaw('user_id, count(*) as count')
                    ->whereNotNull('user_id')
                    ->groupBy('user_id')
                    ->orderByDesc('count')
                    ->limit(10)
                    ->get(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar estadísticas: '.$e->getMessage(),
            ], 500);
        }
    }
}
