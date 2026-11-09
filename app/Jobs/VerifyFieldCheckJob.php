<?php
// sentinel-back/app/Jobs/VerifyFieldCheckJob.php
namespace App\Jobs;

use App\Exceptions\FaceRecognitionException;
use App\Models\SfEmployeeFaceTemplate;
use App\Models\SfFieldCheck;
use App\Services\AttendanceConsolidationService;
use App\Services\FaceMatchingService;
use App\Services\FaceRecognitionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class VerifyFieldCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function backoff(): array
    {
        return [30, 60, 300, 900];
    }

    public function __construct(public readonly int $fieldCheckId)
    {
    }

    public function handle(): void
    {
        // Resueltos manualmente desde el contenedor (en vez de vía inyección
        // en la firma del método) para que handle() se comporte igual tanto
        // si lo llama el queue worker (dispatch normal) como si se invoca
        // directamente ($job->handle(), como hacen los tests de esta clase).
        // La inyección por firma solo la resuelve automáticamente
        // Illuminate\Queue\CallQueuedHandler cuando el job pasa por la cola;
        // una llamada directa a ->handle() con parámetros tipados y sin
        // valores por defecto produciría un ArgumentCountError.
        $faceService = app(FaceRecognitionService::class);
        $matchService = app(FaceMatchingService::class);

        $check = SfFieldCheck::find($this->fieldCheckId);
        if (! $check) {
            return;
        }

        if (! $check->sf_employee_id) {
            $check->update(['verification_status' => SfFieldCheck::STATUS_NO_TEMPLATE]);
            return;
        }

        $template = SfEmployeeFaceTemplate::where('sf_employee_id', $check->sf_employee_id)
            ->where('status', SfEmployeeFaceTemplate::STATUS_ACTIVE)
            ->first();

        if (! $template) {
            $check->update(['verification_status' => SfFieldCheck::STATUS_NO_TEMPLATE]);
            return;
        }

        // La foto de evidencia puede faltar (borrada, sync incompleto,
        // almacenamiento corrupto). El disco 'local' tiene 'throw' => false
        // (ver config/filesystems.php), así que Storage::get() NO lanza
        // excepción para un archivo faltante — simplemente retorna null, que
        // si no se detecta aquí terminaría enviándose como una foto "vacía"
        // al face-service en vez de tratarse como un caso "no se puede
        // verificar". Igual que un fallo del face-service, esto nunca debe
        // tirar el job ni dejar el check atascado en 'pending': se enruta a
        // revisión humana como cualquier otro evento no verificable.
        if (! Storage::disk('local')->exists($check->evidence_photo_path)) {
            $this->markUnverifiable($check);
            return;
        }

        $photoContents = Storage::disk('local')->get($check->evidence_photo_path);

        try {
            $result = $faceService->embed($photoContents);
        } catch (FaceRecognitionException $e) {
            if ($e->getReason() === 'service_unavailable') {
                // Falla transitoria (servicio caído, timeout, HTTP no-2xx) —
                // se relanza para que el mecanismo de reintento de la cola
                // actúe (ver $tries/backoff() arriba). Solo si se agotan los
                // reintentos, failed() abajo marca el check como no
                // verificable. Reintentar aquí NO cambia el resultado para
                // 'no_face'/'multiple_faces'/'invalid_response' (el problema
                // está en la foto, no en el servicio) — esos casos siguen
                // yendo directo a revisión sin reintentar.
                throw $e;
            }

            $this->markUnverifiable($check);
            return;
        }

        // El embedding recién generado y el de la plantilla enrolada deben venir del
        // mismo modelo — comparar distancias entre embeddings de modelos distintos
        // no es matemáticamente significativo (espacios vectoriales incompatibles).
        // Falla cerrado: un desfase de model_version nunca debe poder producir un
        // falso-verificado, solo enrutar a revisión humana como cualquier otro caso
        // no verificable (spec §12).
        if ($result['model_version'] !== $template->model_version) {
            $this->markUnverifiable($check);
            return;
        }

        $distance = $matchService->euclideanDistance($result['embedding'], $template->embedding);

        $skewToleranceSeconds = ((int) config('biometrics.clock_skew_tolerance_minutes')) * 60;
        $clockSkewOk = ($check->clock_skew_seconds ?? 0) <= $skewToleranceSeconds;

        // server_confidence guarda la distancia euclidiana (recortada a [0,1]
        // para caber en decimal(5,4) — distancias de embeddings sin relación
        // pueden superar 50 en 128 dimensiones), no una "confianza" invertida:
        // 0.0 = coinciden exactamente, valores mayores = más disímiles.
        $clampedDistance = min($distance, 1);

        // Un check con motivo de revisión (p. ej. tomado con un celular revocado o
        // vencido y rescatado) nunca se verifica ni consolida solo, aunque el rostro
        // coincida y el reloj esté bien: queda en la bandeja para aprobación humana.
        if ($check->manual_override || ! $clockSkewOk || $check->review_reason !== null) {
            $check->update([
                'verification_status' => SfFieldCheck::STATUS_LOW_CONFIDENCE,
                'server_confidence' => $clampedDistance,
            ]);
            return;
        }

        if (! $matchService->isMatch($distance)) {
            $check->update([
                'verification_status' => SfFieldCheck::STATUS_MISMATCH,
                'server_confidence' => $clampedDistance,
            ]);
            return;
        }

        $check->update([
            'verification_status' => SfFieldCheck::STATUS_VERIFIED,
            'server_confidence' => $clampedDistance,
        ]);

        app(AttendanceConsolidationService::class)->consolidate($check);
    }

    /**
     * Marca el check como no verificable (foto faltante o irreconocible,
     * face-service caído, etc.): nunca se pierde el evento, siempre queda un
     * estado accionable por un humano en vez de silenciarse o tronar el job.
     */
    private function markUnverifiable(SfFieldCheck $check): void
    {
        $check->update([
            'verification_status' => SfFieldCheck::STATUS_LOW_CONFIDENCE,
            'server_confidence' => null,
        ]);
    }

    /**
     * Se ejecuta cuando el job agota todos los reintentos ($tries) sin
     * completar exitosamente. Nunca se pierde el evento: el check pasa a
     * revisión humana igual que cualquier otro caso no verificable.
     *
     * Solo se degrada el check si todavía está en su estado previo a
     * verificación (STATUS_PENDING). Si handle() ya avanzó el check más
     * allá de pending (p. ej. lo dejó 'verified' y luego consolidate()
     * lanzó una excepción en un intento posterior), degradarlo aquí a
     * low_confidence tergiversaría lo que realmente pasó.
     */
    public function failed(\Throwable $exception): void
    {
        $check = SfFieldCheck::find($this->fieldCheckId);
        if ($check && $check->verification_status === SfFieldCheck::STATUS_PENDING) {
            $this->markUnverifiable($check);
        }
    }
}
