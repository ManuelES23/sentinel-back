<?php
// sentinel-back/app/Jobs/VerifyTimeClockCheckJob.php
namespace App\Jobs;

use App\Exceptions\FaceRecognitionException;
use App\Models\Employee;
use App\Models\EmployeeFaceTemplate;
use App\Models\TimeClockCheck;
use App\Services\FaceMatchingService;
use App\Services\FaceRecognitionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class VerifyTimeClockCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function backoff(): array
    {
        return [30, 60, 300, 900];
    }

    public function __construct(public readonly int $timeClockCheckId)
    {
    }

    public function handle(FaceRecognitionService $faceService, FaceMatchingService $matchService): void
    {
        $check = TimeClockCheck::find($this->timeClockCheckId);
        if (! $check) {
            return;
        }

        $template = EmployeeFaceTemplate::where('employee_id', $check->employee_id)
            ->where('status', EmployeeFaceTemplate::STATUS_ACTIVE)
            ->first();

        if (! $template) {
            $check->update(['verification_status' => TimeClockCheck::STATUS_NO_TEMPLATE]);
            return;
        }

        $photoContents = Storage::disk('local')->get($check->evidence_photo_path);

        try {
            $result = $faceService->embed($photoContents);
        } catch (FaceRecognitionException $e) {
            if ($e->getReason() === 'service_unavailable') {
                // Falla transitoria — se relanza para que el mecanismo de
                // reintento de la cola actue (ver $tries/backoff() arriba).
                throw $e;
            }

            $check->update(['verification_status' => TimeClockCheck::STATUS_LOW_CONFIDENCE]);
            return;
        }

        // Un embedding de una version de modelo distinta a la de la
        // plantilla enrolada NO es comparable (aunque la distancia
        // euclidiana "salga" un numero, no significa nada) — se trata
        // igual que "sin plantilla": necesita re-enrolarse, va a revision
        // sin intentar comparar.
        if ($template->model_version !== $result['model_version']) {
            $check->update(['verification_status' => TimeClockCheck::STATUS_NO_TEMPLATE]);
            return;
        }

        $distance = $matchService->euclideanDistance($result['embedding'], $template->embedding);

        $skewToleranceSeconds = ((int) config('biometrics.clock_skew_tolerance_minutes')) * 60;
        $clockSkewOk = ($check->clock_skew_seconds ?? 0) <= $skewToleranceSeconds;

        if (! $clockSkewOk) {
            $check->update([
                'verification_status' => TimeClockCheck::STATUS_LOW_CONFIDENCE,
                'server_confidence' => 1 - min($distance, 1),
            ]);
            return;
        }

        if (! $matchService->isMatch($distance)) {
            $check->update([
                'verification_status' => TimeClockCheck::STATUS_LOW_CONFIDENCE,
                'server_confidence' => 1 - min($distance, 1),
            ]);
            return;
        }

        $employee = Employee::find($check->employee_id);

        try {
            if ($check->type === TimeClockCheck::TYPE_CHECK_IN) {
                \App\Models\AttendanceRecord::checkIn($employee, 'biometric', null, $check->checked_at);
            } else {
                \App\Models\AttendanceRecord::checkOut($employee, 'biometric', null, $check->checked_at);
            }
        } catch (\Exception $e) {
            // "Ya registraste tu entrada/salida hoy" o "Primero debes
            // registrar tu entrada" — no se pierde el evento, queda
            // rechazado con el motivo visible para RH, sin tronar el job.
            $check->update([
                'verification_status' => TimeClockCheck::STATUS_REJECTED,
                'server_confidence' => 1 - min($distance, 1),
                'review_notes' => $e->getMessage(),
            ]);
            return;
        }

        $check->update([
            'verification_status' => TimeClockCheck::STATUS_VERIFIED,
            'server_confidence' => 1 - min($distance, 1),
        ]);
    }

    /**
     * Se ejecuta cuando el job agota todos los reintentos ($tries) sin
     * completar exitosamente. Nunca se pierde el evento: el check pasa a
     * revisión humana igual que cualquier otro caso no verificable.
     */
    public function failed(\Throwable $exception): void
    {
        $check = TimeClockCheck::find($this->timeClockCheckId);
        $check?->update(['verification_status' => TimeClockCheck::STATUS_LOW_CONFIDENCE]);
    }
}
