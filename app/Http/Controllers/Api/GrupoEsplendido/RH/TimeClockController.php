<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Jobs\VerifyTimeClockCheckJob;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\TimeClockCheck;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Controlador del Checador de Asistencia
 * Este endpoint es para las terminales/kioscos de checado
 */
class TimeClockController extends Controller
{
    /**
     * Consultar estado del empleado (sin checar)
     */
    public function getStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_code' => 'required_without:employee_number|string',
            'employee_number' => 'required_without:qr_code|string',
        ]);

        if (isset($validated['qr_code'])) {
            $employee = Employee::findByQRCode($validated['qr_code']);
        } else {
            $employee = Employee::where('employee_number', $validated['employee_number'])
                ->where('status', Employee::STATUS_ACTIVE)
                ->first();
        }

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Empleado no encontrado',
            ], 404);
        }

        $todayRecord = $employee->todayAttendance();

        return response()->json([
            'success' => true,
            'data' => [
                'employee' => [
                    'id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name' => $employee->full_name,
                    'photo_url' => $employee->photo_url,
                    'department' => $employee->department?->name,
                    'position' => $employee->position?->name,
                    'enterprise' => $employee->enterprise?->name,
                ],
                'today' => $todayRecord ? [
                    'date' => $todayRecord->date->format('Y-m-d'),
                    'check_in' => $todayRecord->check_in?->format('H:i:s'),
                    'check_out' => $todayRecord->check_out?->format('H:i:s'),
                    'status' => $todayRecord->status,
                    'status_label' => $todayRecord->status_label,
                    'hours_worked' => $todayRecord->hours_worked,
                ] : null,
                'can_check_in' => !$todayRecord || !$todayRecord->check_in,
                'can_check_out' => $todayRecord && $todayRecord->check_in && !$todayRecord->check_out,
            ],
        ]);
    }

    /**
     * Obtener hora actual del servidor (para sincronizar terminal)
     */
    public function serverTime(): JsonResponse
    {
        $now = now();

        return response()->json([
            'success' => true,
            'data' => [
                'datetime' => $now->toISOString(),
                'date' => $now->format('Y-m-d'),
                'time' => $now->format('H:i:s'),
                'timezone' => config('app.timezone'),
                'timestamp' => $now->timestamp,
            ],
        ]);
    }

    /**
     * Obtener lista de empleados que han checado hoy (para mostrar en pantalla del kiosco)
     */
    public function todayChecks(Request $request): JsonResponse
    {
        $enterpriseId = $request->get('enterprise_id');

        $query = AttendanceRecord::with(['employee'])
            ->today()
            ->whereNotNull('check_in');

        if ($enterpriseId) {
            $query->whereHas('employee', function ($q) use ($enterpriseId) {
                $q->where('enterprise_id', $enterpriseId);
            });
        }

        $records = $query->orderBy('check_in', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($record) {
                return [
                    'employee_name' => $record->employee->full_name,
                    'employee_photo' => $record->employee->photo_url,
                    'department' => $record->employee->department?->name,
                    'check_in' => $record->check_in->format('H:i'),
                    'check_out' => $record->check_out?->format('H:i'),
                    'status' => $record->status,
                    'status_label' => $record->status_label,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $records,
        ]);
    }

    /**
     * Sincroniza un lote de chequeos biométricos. Idempotente por client_uuid.
     * Público (sin Sanctum) pero requiere `X-Device-Token` (middleware
     * `device.token`) — el empleado ya fue identificado en el cliente por
     * matching facial local contra el roster-package antes de sincronizar;
     * este endpoint recibe el `employee_id` ya resuelto, y la verificación
     * 1:1 real ocurre server-side vía VerifyTimeClockCheckJob.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'checks' => 'required|array|min:1|max:20',
            'checks.*.client_uuid' => 'required|uuid',
            'checks.*.employee_id' => 'required|integer|exists:employees,id',
            'checks.*.type' => 'required|in:' . TimeClockCheck::TYPE_CHECK_IN . ',' . TimeClockCheck::TYPE_CHECK_OUT,
            'checks.*.checked_at' => 'required|date',
            'checks.*.device_synced_at' => 'required|date',
            // max en caracteres (regla 'string'): ~2MB de JPEG real en
            // base64 son ~2,800,000 caracteres; 3,000,000 da margen sin
            // dejar el payload sin techo — antes de esto la validacion
            // aceptaba un string de cualquier tamaño y el limite de ~2MB
            // solo se checaba DESPUES de decodificar el base64 completo
            // ya recibido (decodeBase64Photo() mas abajo).
            'checks.*.evidence_photo' => 'required|string|max:3000000',
            'checks.*.latitude' => 'nullable|numeric|between:-90,90',
            'checks.*.longitude' => 'nullable|numeric|between:-180,180',
            'checks.*.device_info' => 'nullable|array',
        ]);

        $results = [];

        foreach ($validated['checks'] as $item) {
            $existing = TimeClockCheck::where('client_uuid', $item['client_uuid'])->first();
            if ($existing) {
                $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'duplicate'];
                continue;
            }

            $employee = Employee::where('id', $item['employee_id'])
                ->where('status', Employee::STATUS_ACTIVE)
                ->first();

            if (! $employee) {
                $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'rejected', 'reason' => 'empleado no encontrado o inactivo'];
                continue;
            }

            $decodedPhoto = $this->decodeBase64Photo($item['evidence_photo']);
            if ($decodedPhoto === null || strlen($decodedPhoto) > 2 * 1024 * 1024) {
                $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'rejected', 'reason' => 'foto de evidencia invalida o demasiado grande'];
                continue;
            }

            $photoPath = 'private/time-clock-evidence/' . $item['client_uuid'] . '.jpg';
            Storage::disk('local')->put($photoPath, $decodedPhoto);

            // checked_at: el celular manda new Date().toISOString() (UTC, sufijo
            // "Z") — se normaliza a la zona horaria de la app antes de guardar,
            // igual que se corrigio en SfFieldCheckController::sync() (ver
            // sentinel-back/CLAUDE.md / commit ccc405d) para que no quede
            // desalineado contra created_at (que si usa now(), ya en hora de la app).
            $checkedAt = Carbon::parse($item['checked_at'])->setTimezone(config('app.timezone'));
            $deviceSyncedAt = Carbon::parse($item['device_synced_at']);
            $clockSkewSeconds = abs(now()->diffInSeconds($deviceSyncedAt));

            $check = TimeClockCheck::create([
                'client_uuid' => $item['client_uuid'],
                'employee_id' => $employee->id,
                'type' => $item['type'],
                'checked_at' => $checkedAt,
                'synced_at' => now(),
                'evidence_photo_path' => $photoPath,
                'verification_status' => TimeClockCheck::STATUS_PENDING,
                'latitude' => $item['latitude'] ?? null,
                'longitude' => $item['longitude'] ?? null,
                'device_info' => $item['device_info'] ?? null,
                'clock_skew_seconds' => $clockSkewSeconds,
            ]);

            VerifyTimeClockCheckJob::dispatch($check->id);

            $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'accepted'];
        }

        return response()->json(['success' => true, 'data' => ['results' => $results]]);
    }

    private function decodeBase64Photo(string $data): ?string
    {
        if (str_contains($data, 'base64,')) {
            $data = substr($data, strpos($data, 'base64,') + 7);
        }

        $decoded = base64_decode($data, true);

        return $decoded === false ? null : $decoded;
    }
}
