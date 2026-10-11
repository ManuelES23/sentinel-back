<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DatabaseBackup;
use App\Services\Respaldos\CicloRespaldos;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Respaldos de la BD (solo superadmin). El panel no genera nada: crea filas
 * pendientes que procesa respaldos:ejecutar desde el programador.
 */
class DatabaseBackupController extends Controller
{
    private const MINUTOS_URL = 5;

    public function __construct(private SettingsService $ajustes, private CicloRespaldos $ciclo) {}

    public function index(): JsonResponse
    {
        $pagina = DatabaseBackup::with('creador:id,name')->orderByDesc('id')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $pagina->items(),
            'pagination' => [
                'current_page' => $pagina->currentPage(),
                'last_page' => $pagina->lastPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
        ]);
    }

    public function status(): JsonResponse
    {
        $ultimoTerminado = DatabaseBackup::whereIn('status', [DatabaseBackup::ESTADO_COMPLETADO, DatabaseBackup::ESTADO_FALLIDO])
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'ultimo_completado' => DatabaseBackup::where('status', DatabaseBackup::ESTADO_COMPLETADO)->orderByDesc('id')->first(),
                'ultimo_fallido_reciente' => $ultimoTerminado?->status === DatabaseBackup::ESTADO_FALLIDO ? $ultimoTerminado : null,
                'en_curso' => DatabaseBackup::whereIn('status', DatabaseBackup::ESTADOS_EN_CURSO)->orderBy('id')->get(),
                'proximo_programado' => $this->ciclo->proximoProgramado()?->toIso8601String(),
                'scheduler_heartbeat' => $this->ciclo->ultimoLatido()?->toIso8601String(),
                'scheduler_activo' => $this->ciclo->programadorActivo(),
                'config' => $this->ajustes->backupConfig(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (DatabaseBackup::hayEnCurso()) {
            return response()->json([
                'success' => false,
                'message' => 'Ya hay un respaldo en curso. Espera a que termine.',
            ], 409);
        }

        $backup = DatabaseBackup::create([
            'type' => DatabaseBackup::TIPO_MANUAL,
            'status' => DatabaseBackup::ESTADO_PENDIENTE,
            'created_by' => $request->user()->id,
        ]);
        ActivityLog::log('backup_create', 'database_backups', $backup->id, null, ['type' => DatabaseBackup::TIPO_MANUAL]);

        return response()->json([
            'success' => true,
            'data' => $backup->fresh(),
            'message' => 'Respaldo en cola. Empezará en menos de un minuto.',
        ], 201);
    }

    public function updateConfig(Request $request): JsonResponse
    {
        $validado = $request->validate(
            [
                'enabled' => ['required', 'boolean'],
                'hour' => ['required', 'integer', 'min:0', 'max:23'],
                'keep_scheduled' => ['required', 'integer', 'min:1', 'max:90'],
            ],
            SettingsService::messagesForRequest() + ['required' => 'Este campo es obligatorio.'],
            [
                'enabled' => SettingsService::attributesForRequest()['backups.enabled'],
                'hour' => SettingsService::attributesForRequest()['backups.hour'],
                'keep_scheduled' => SettingsService::attributesForRequest()['backups.keep_scheduled'],
            ],
        );

        $cambios = $this->ajustes->update([
            'backups.enabled' => $validado['enabled'],
            'backups.hour' => $validado['hour'],
            'backups.keep_scheduled' => $validado['keep_scheduled'],
        ], $request->user());

        if ($cambios['despues'] !== []) {
            ActivityLog::log('backup_config_update', 'database_backups', null, $cambios['antes'], $cambios['despues']);
        }

        return response()->json([
            'success' => true,
            'data' => $this->ajustes->backupConfig(),
            'message' => 'Configuración de respaldos guardada.',
        ]);
    }

    public function downloadUrl(DatabaseBackup $backup): JsonResponse
    {
        if ($backup->status !== DatabaseBackup::ESTADO_COMPLETADO) {
            return response()->json(['success' => false, 'message' => 'Este respaldo no está disponible para descargar.'], 422);
        }
        if (! $backup->archivo_disponible) {
            return response()->json(['success' => false, 'message' => 'El archivo ya no existe en el servidor.'], 404);
        }

        $expira = now()->addMinutes(self::MINUTOS_URL);
        // Firma relativa: valida aunque el proxy cambie http/https o el host.
        $relativa = URL::temporarySignedRoute('backups.download', $expira, ['backup' => $backup->id], absolute: false);
        ActivityLog::log('backup_download', 'database_backups', $backup->id);

        return response()->json([
            'success' => true,
            'data' => ['url' => url($relativa), 'expires_at' => $expira->toIso8601String()],
        ]);
    }

    /** Ruta pública con firma (middleware signed:relative); sin Sanctum para que la descargue el navegador. */
    public function download(DatabaseBackup $backup): Response
    {
        if (! $backup->archivo_disponible) {
            return response()->json(['success' => false, 'message' => 'El archivo ya no existe en el servidor.'], 404);
        }

        return Storage::disk('local')->download($backup->rutaRelativa(), $backup->filename, [
            'Content-Type' => 'application/gzip',
        ]);
    }

    public function destroy(DatabaseBackup $backup): JsonResponse
    {
        if ($backup->status === DatabaseBackup::ESTADO_EN_PROCESO) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar un respaldo que se está generando.',
            ], 409);
        }

        // El modelo se borra dentro de eliminar(): se captura antes para el log.
        $id = $backup->id;
        $antes = ['filename' => $backup->filename, 'status' => $backup->status];

        if (! $this->ciclo->eliminar($backup)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo borrar el archivo del servidor. Revisa los permisos de storage/app/private/backups.',
            ], 500);
        }

        ActivityLog::log('backup_delete', 'database_backups', $id, $antes);

        return response()->json(['success' => true, 'message' => 'Respaldo eliminado.']);
    }
}
