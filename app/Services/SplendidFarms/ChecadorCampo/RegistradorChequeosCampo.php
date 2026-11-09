<?php

namespace App\Services\SplendidFarms\ChecadorCampo;

use App\Jobs\VerifyFieldCheckJob;
use App\Models\SfFieldCheck;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Registra un lote de chequeos de campo (idempotente por client_uuid) y despacha
 * su verificación. Lo comparten el endpoint Sanctum de Administración y los
 * endpoints públicos del dispositivo emparejado; solo cambia quién autentica y
 * de dónde sale la atribución (checked_by_user_id, dispositivo, rescate).
 */
class RegistradorChequeosCampo
{
    /**
     * Reglas de validación de `checks` y `checks.*` (sin `enterprise_id`, que
     * cada controlador valida a su manera).
     */
    public function reglas(int $enterpriseId): array
    {
        return [
            'checks' => 'required|array|min:1|max:20',
            'checks.*.client_uuid' => 'required|uuid',
            'checks.*.sf_employee_id' => [
                'nullable',
                Rule::exists('sf_employees', 'id')->where(function ($query) use ($enterpriseId) {
                    $query->where('enterprise_id', $enterpriseId);
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
        ];
    }

    /**
     * @param  array  $checks  ítems ya validados con reglas()
     * @param  array  $atribucion  ['checked_by_user_id' => ?int, 'device_pairing_id' => ?int,
     *                             'rescued_by_user_id' => ?int, 'review_reason' => ?string]
     * @return array<int, array{client_uuid: string, status: string, reason?: string}>
     */
    public function registrar(array $checks, int $enterpriseId, array $atribucion): array
    {
        $results = [];

        // Máximo storable en la columna decimal(5,4) de sf_field_checks.client_confidence.
        $clientConfidenceMax = 9.9999;

        foreach ($checks as $item) {
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
                'enterprise_id' => $enterpriseId,
                'client_uuid' => $item['client_uuid'],
                'sf_employee_id' => $item['sf_employee_id'] ?? null,
                'checked_by_user_id' => $atribucion['checked_by_user_id'] ?? null,
                'device_pairing_id' => $atribucion['device_pairing_id'] ?? null,
                'rescued_by_user_id' => $atribucion['rescued_by_user_id'] ?? null,
                'review_reason' => $atribucion['review_reason'] ?? null,
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

        return $results;
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
