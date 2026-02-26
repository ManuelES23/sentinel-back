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
            Log::info('=== ACTIVITY LOG CONTROLLER EJECUTADO ===');
            Log::info('Usuario autenticado:', ['user_id' => auth()->id(), 'user' => auth()->user()?->email]);

            // Primero verificar si hay logs en la DB
            $totalEnDB = ActivityLog::count();
            Log::info("Total logs en base de datos: {$totalEnDB}");

            if ($totalEnDB > 0) {
                Log::info('Primeros 3 logs:', ActivityLog::take(3)->get()->toArray());
            }

            $query = ActivityLog::with('user:id,name,email');

            Log::info('Request params:', $request->all());

            // Filtro por usuario (solo si tiene valor no vacío)
            if ($request->filled('user_id')) {
                Log::info('Aplicando filtro user_id: '.$request->user_id);
                $query->byUser($request->user_id);
            }

            // Filtro por acción (solo si tiene valor no vacío)
            if ($request->filled('action')) {
                Log::info('Aplicando filtro action: '.$request->action);
                $query->byAction($request->action);
            }

            // Filtro por modelo (solo si tiene valor no vacío)
            if ($request->filled('model')) {
                Log::info('Aplicando filtro model: '.$request->model);
                $query->byModel($request->model);
            }

            // Filtros de jerarquía
            if ($request->filled('enterprise')) {
                $query->where('enterprise', $request->enterprise);
            }

            if ($request->filled('application')) {
                $query->where('application', $request->application);
            }

            if ($request->filled('module')) {
                $query->where('module', $request->module);
            }

            if ($request->filled('submodule')) {
                $query->where('submodule', $request->submodule);
            }

            // Filtro por rango de fechas
            if ($request->filled('start_date') && $request->filled('end_date')) {
                Log::info('Aplicando filtro fechas: '.$request->start_date.' a '.$request->end_date);
                $query->dateRange($request->start_date, $request->end_date);
            }

            // Búsqueda general (solo si tiene valor no vacío)
            if ($request->filled('search')) {
                $search = $request->search;
                Log::info('Aplicando búsqueda: '.$search);
                $query->where(function ($q) use ($search) {
                    $q->where('action', 'like', "%{$search}%")
                        ->orWhere('model', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            // Ordenar por más reciente
            $query->orderBy('created_at', 'desc');

            // Contar después de filtros
            $queryCount = $query->count();
            Log::info("Después de filtros: {$queryCount} logs");

            // Paginación
            $perPage = $request->get('per_page', 20);
            $logs = $query->paginate($perPage);

            Log::info('Logs paginados:', [
                'count' => $logs->count(),
                'total' => $logs->total(),
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
            ]);

            return response()->json([
                'success' => true,
                'data' => $logs,
            ]);

        } catch (\Exception $e) {
            Log::error('ERROR en Activity Log Controller: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error al cargar los logs: '.$e->getMessage(),
            ], 500);
        }
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
