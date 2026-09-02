<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Jobs\VerifyFieldCheckJob;
use App\Models\SfEmployee;
use App\Models\SfFieldCheck;
use App\Services\AttendanceConsolidationService;
use App\Services\ThumbnailService;
use App\Traits\AuthorizesEnterpriseAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SfFieldCheckController extends Controller
{
    use AuthorizesEnterpriseAccess;

    private const CURRENT_MODEL_VERSION = 'faceapi-v1';

    public function __construct(
        private readonly ThumbnailService $thumbnailService,
        private readonly AttendanceConsolidationService $consolidationService,
    ) {
    }

    /**
     * Paquete de cuadrilla: empleados activos y enrolados de la empresa, con embedding y miniatura.
     */
    public function crewPackage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
        ]);

        $this->authorizeEnterpriseAccess($request, (int) $validated['enterprise_id']);

        $employees = SfEmployee::query()
            ->where('enterprise_id', $validated['enterprise_id'])
            ->where('status', SfEmployee::STATUS_ACTIVE)
            ->whereHas('faceTemplate', function ($q) {
                $q->where('model_version', self::CURRENT_MODEL_VERSION);
            })
            ->with(['faceTemplate' => function ($q) {
                $q->where('model_version', self::CURRENT_MODEL_VERSION);
            }])
            ->get(['id', 'enterprise_id', 'code', 'first_name', 'last_name', 'second_last_name']);

        $rows = $employees->map(function (SfEmployee $employee) {
            $template = $employee->faceTemplate;

            return [
                'id' => $employee->id,
                'code' => $employee->code,
                'full_name' => trim("{$employee->first_name} {$employee->last_name} {$employee->second_last_name}"),
                'embedding' => $template->embedding,
                'thumbnail' => $this->thumbnailService->makeThumbnailDataUri($template->photo_path),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'model_version' => self::CURRENT_MODEL_VERSION,
                'generated_at' => now()->toIso8601String(),
                'employees' => $rows,
            ],
        ]);
    }

    /**
     * Sincroniza un lote de chequeos de campo. Idempotente por client_uuid.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'checks' => 'required|array|min:1|max:20',
            'checks.*.client_uuid' => 'required|uuid',
            'checks.*.sf_employee_id' => [
                'nullable',
                Rule::exists('sf_employees', 'id')->where(function ($query) use ($request) {
                    $query->where('enterprise_id', $request->input('enterprise_id'));
                }),
            ],
            'checks.*.type' => 'required|in:' . SfFieldCheck::TYPE_CHECK_IN . ',' . SfFieldCheck::TYPE_CHECK_OUT,
            'checks.*.checked_at' => 'required|date',
            'checks.*.device_synced_at' => 'required|date',
            'checks.*.evidence_photo' => 'required|string',
            // Límite superior deliberadamente NO puesto aquí como regla de validación
            // (p.ej. |max:9.9999): Laravel invalida el REQUEST completo si cualquier
            // item del arreglo falla una regla, tumbando todo el lote de hasta 20
            // checks por un solo valor corrupto. En vez de eso, un valor fuera de
            // rango para decimal(5,4) se rechaza per-item dentro del loop (igual que
            // el caso de foto base64 inválida, ver más abajo) para no perder el resto
            // del lote.
            'checks.*.client_confidence' => 'nullable|numeric|min:0',
            'checks.*.manual_override' => 'nullable|boolean',
            'checks.*.latitude' => 'nullable|numeric|between:-90,90',
            'checks.*.longitude' => 'nullable|numeric|between:-180,180',
            'checks.*.device_info' => 'nullable|array',
        ]);

        $this->authorizeEnterpriseAccess($request, (int) $validated['enterprise_id']);

        $results = [];

        // Máximo storable en la columna decimal(5,4) de sf_field_checks.client_confidence.
        $clientConfidenceMax = 9.9999;

        foreach ($validated['checks'] as $item) {
            $existing = SfFieldCheck::where('client_uuid', $item['client_uuid'])->first();
            if ($existing) {
                $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'duplicate'];
                continue;
            }

            $clientConfidence = $item['client_confidence'] ?? null;
            if ($clientConfidence !== null && $clientConfidence > $clientConfidenceMax) {
                $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'rejected', 'reason' => 'client_confidence fuera de rango'];
                continue;
            }

            $decodedPhoto = $this->decodeBase64Photo($item['evidence_photo']);
            if ($decodedPhoto === null || strlen($decodedPhoto) > 2 * 1024 * 1024) {
                $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'rejected', 'reason' => 'foto de evidencia inválida o demasiado grande'];
                continue;
            }

            $photoPath = 'private/sf-field-checks-evidence/' . $item['client_uuid'] . '.jpg';
            Storage::disk('local')->put($photoPath, $decodedPhoto);

            // checked_at es cuándo el DISPOSITIVO capturó el chequeo (puede ser horas
            // atrás si estaba offline) — se guarda tal cual, sigue siendo el timestamp
            // real de asistencia. El cliente manda new Date().toISOString() (UTC
            // explícito, sufijo "Z"); Carbon::parse() respeta ese offset pero, si no se
            // normaliza, se guarda en la BD con los dígitos crudos de esa zona horaria
            // en vez de la zona de la app (America/Mazatlan) — eso desalinea checked_at
            // contra created_at/synced_at (que sí usan now(), ya en hora de la app) por
            // el offset UTC↔Mazatlan completo. setTimezone() lo normaliza antes de
            // guardar. device_synced_at es la hora que el reloj del dispositivo marca
            // AHORA MISMO, en el instante del sync — comparado contra now() (hora del
            // servidor en ese mismo instante), esto sí mide desfase de reloj real entre
            // dispositivo y servidor, independiente de cuánto tiempo estuvo el
            // dispositivo desconectado; solo se usa para el diff, no se persiste tal
            // cual, así que no necesita esta misma normalización.
            $checkedAt = Carbon::parse($item['checked_at'])->setTimezone(config('app.timezone'));
            $deviceSyncedAt = Carbon::parse($item['device_synced_at']);
            $clockSkewSeconds = abs(now()->diffInSeconds($deviceSyncedAt));

            $check = SfFieldCheck::create([
                'enterprise_id' => $validated['enterprise_id'],
                'client_uuid' => $item['client_uuid'],
                'sf_employee_id' => $item['sf_employee_id'] ?? null,
                'checked_by_user_id' => $request->user()->id,
                'type' => $item['type'],
                'checked_at' => $checkedAt,
                'synced_at' => now(),
                'evidence_photo_path' => $photoPath,
                'client_confidence' => $clientConfidence,
                'verification_status' => SfFieldCheck::STATUS_PENDING,
                'manual_override' => $item['manual_override'] ?? false,
                'latitude' => $item['latitude'] ?? null,
                'longitude' => $item['longitude'] ?? null,
                'device_info' => $item['device_info'] ?? null,
                'clock_skew_seconds' => $clockSkewSeconds,
            ]);

            VerifyFieldCheckJob::dispatch($check->id);

            $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'accepted'];
        }

        return response()->json(['success' => true, 'data' => ['results' => $results]]);
    }

    /**
     * Listado paginado de chequeos de campo, con filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'sf_employee_id' => 'nullable|exists:sf_employees,id',
            'verification_status' => 'nullable|in:pending,verified,low_confidence,mismatch,no_template,manually_approved,rejected',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $this->authorizeEnterpriseAccess($request, (int) $validated['enterprise_id']);

        $query = SfFieldCheck::query()
            ->with(['employee:id,enterprise_id,code,first_name,last_name,second_last_name', 'checkedBy:id,name'])
            ->where('enterprise_id', $validated['enterprise_id'])
            ->when($validated['sf_employee_id'] ?? null, fn ($q, $v) => $q->where('sf_employee_id', $v))
            ->when($validated['verification_status'] ?? null, fn ($q, $v) => $q->where('verification_status', $v))
            ->when(($validated['start_date'] ?? null) && ($validated['end_date'] ?? null), fn ($q) => $q->whereBetween('checked_at', [$validated['start_date'], $validated['end_date']]))
            ->orderByDesc('checked_at');

        return response()->json([
            'success' => true,
            'data' => $query->paginate((int) $request->get('per_page', 50)),
        ]);
    }

    /**
     * RH aprueba o rechaza un chequeo pendiente de revisión humana
     * (low_confidence | mismatch | no_template). Aprobar puede reasignar el
     * empleado (obligatorio si el check no trae uno) y consolida en
     * sf_attendance_records. Rechazar es terminal, nunca consolida.
     */
    public function review(Request $request, SfFieldCheck $fieldCheck): JsonResponse
    {
        $validated = $request->validate([
            'decision' => 'required|in:approve,reject',
            'sf_employee_id' => [
                'nullable',
                Rule::exists('sf_employees', 'id')->where(
                    fn ($query) => $query->where('enterprise_id', $fieldCheck->enterprise_id)
                ),
            ],
        ]);

        $this->authorizeEnterpriseAccess($request, (int) $fieldCheck->enterprise_id);

        $reviewableStatuses = [
            SfFieldCheck::STATUS_LOW_CONFIDENCE,
            SfFieldCheck::STATUS_MISMATCH,
            SfFieldCheck::STATUS_NO_TEMPLATE,
        ];
        abort_unless(
            in_array($fieldCheck->verification_status, $reviewableStatuses, true),
            422,
            'Este chequeo ya fue resuelto o aún no ha sido procesado.'
        );

        if ($validated['decision'] === 'reject') {
            $fieldCheck->update([
                'verification_status' => SfFieldCheck::STATUS_REJECTED,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Chequeo rechazado',
                'data' => $fieldCheck->fresh(),
            ]);
        }

        // approve: sfEmployeeId (si viene) siempre reemplaza el empleado
        // actual del check — necesario para 'mismatch' (el empleado que trae
        // hoy es justo el que el servidor determinó que NO coincide) y para
        // 'no_template' (no trae ninguno).
        $employeeId = $validated['sf_employee_id'] ?? $fieldCheck->sf_employee_id;
        abort_if(
            $employeeId === null,
            422,
            'Debes asignar un empleado antes de aprobar un chequeo sin coincidencia.'
        );

        $fieldCheck->update([
            'sf_employee_id' => $employeeId,
            'verification_status' => SfFieldCheck::STATUS_MANUALLY_APPROVED,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $this->consolidationService->consolidate($fieldCheck->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Chequeo aprobado',
            'data' => $fieldCheck->fresh(),
        ]);
    }

    /**
     * Sirve la foto de evidencia de un chequeo, autenticada y con el mismo
     * guard de empresa que el resto del módulo. 404 si ya fue purgada.
     */
    public function evidencePhoto(Request $request, SfFieldCheck $fieldCheck)
    {
        $this->authorizeEnterpriseAccess($request, (int) $fieldCheck->enterprise_id);

        if (! $fieldCheck->evidence_photo_path || ! Storage::disk('local')->exists($fieldCheck->evidence_photo_path)) {
            abort(404, 'La foto de evidencia ya no está disponible.');
        }

        return Storage::disk('local')->response($fieldCheck->evidence_photo_path, null, [
            'Content-Type' => 'image/jpeg',
        ]);
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
